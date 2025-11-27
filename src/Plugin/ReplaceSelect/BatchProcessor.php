<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ReplaceSelect;

use Manticoresearch\Buddy\Base\Plugin\Queue\StringFunctionsTrait;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

/**
 * Batch processing engine for REPLACE SELECT operations
 */
final class BatchProcessor {
	use StringFunctionsTrait;

	private Client $client;
	private Payload $payload;
	/** @var array<string,array<string,mixed>> */
	private array $targetFields;
	private int $totalProcessed = 0;
	private int $batchesProcessed = 0;
	private float $processingStartTime;
	/** @var array<int,array<string,mixed>> */
	private array $statistics = [];

	/**
	 * Constructor
	 *
	 * @param Client $client
	 * @param Payload $payload
	 * @param array<string,array<string,mixed>> $targetFields
	 */
	public function __construct(Client $client, Payload $payload, array $targetFields) {
		$this->client = $client;
		$this->payload = $payload;
		$this->targetFields = $targetFields;
		$this->processingStartTime = microtime(true);
		$this->getFields($client, $payload->targetTable);
	}

	/**
	 * Execute the batch processing
	 *
	 * @return int Total number of records processed
	 * @throws ManticoreSearchClientError
	 */
	public function execute(): int {
		$offset = 0;
		$batchSize = $this->payload->batchSize;
		$consecutiveEmptyBatches = 0;
		$maxEmptyBatches = 3;

		if (Config::isDebugEnabled()) {
			error_log("Starting batch processing with size: $batchSize");
		}

		do {
			$batchStartTime = microtime(true);
			$batchQuery = "({$this->payload->selectQuery}) LIMIT $batchSize OFFSET $offset";

			try {
				$batch = $this->fetchBatch($batchQuery);

				if (empty($batch)) {
					$consecutiveEmptyBatches++;
					if ($this->shouldStopProcessing($consecutiveEmptyBatches, $maxEmptyBatches)) {
						break;
					}
				} else {
					$consecutiveEmptyBatches = 0;
					$this->processBatch($batch);
					$this->recordBatchStatistics($batch, $batchStartTime);
				}

				$offset += $batchSize;
			} catch (\Exception $e) {
				error_log("Batch processing failed at offset $offset: " . $e->getMessage());
				throw $e;
			}
		} while (sizeof($batch) === $batchSize);

		$this->logProcessingStatistics();
		return $this->totalProcessed;
	}

	/**
	 * Check if we should stop processing due to empty batches
	 *
	 * @param int $consecutiveEmptyBatches
	 * @param int $maxEmptyBatches
	 * @return bool
	 */
	private function shouldStopProcessing(int $consecutiveEmptyBatches, int $maxEmptyBatches): bool {
		if ($consecutiveEmptyBatches >= $maxEmptyBatches) {
			if (Config::isDebugEnabled()) {
				error_log("Stopping after $consecutiveEmptyBatches consecutive empty batches");
			}
			return true;
		}
		return false;
	}

	/**
	 * Record statistics for a processed batch
	 *
	 * @param array<int,array<string,mixed>> $batch
	 * @param float $batchStartTime
	 * @return void
	 */
	private function recordBatchStatistics(array $batch, float $batchStartTime): void {
		$this->totalProcessed += sizeof($batch);
		$this->batchesProcessed++;

		$batchDuration = microtime(true) - $batchStartTime;
		$this->statistics[] = [
			'batch_number' => $this->batchesProcessed,
			'records_count' => sizeof($batch),
			'duration_seconds' => $batchDuration,
			'records_per_second' => sizeof($batch) / $batchDuration,
		];

		if (!Config::isDebugEnabled()) {
			return;
		}

		error_log(
			sprintf(
				'Batch %d: %d records in %.2fs (%.0f records/sec)',
				$this->batchesProcessed,
				sizeof($batch),
				$batchDuration,
				sizeof($batch) / $batchDuration
			)
		);
	}



	/**
	 * Log processing statistics for debugging
	 *
	 * @return void
	 */
	private function logProcessingStatistics(): void {
		if (!Config::isDebugEnabled() || empty($this->statistics)) {
			return;
		}

		$totalDuration = microtime(true) - $this->processingStartTime;
		$avgRecordsPerBatch = $this->totalProcessed / max(1, $this->batchesProcessed);
		$avgDurationPerBatch = array_sum(array_column($this->statistics, 'duration_seconds'))
			/ max(1, $this->batchesProcessed);
		$overallRecordsPerSecond = $this->totalProcessed / $totalDuration;

		$summary = [
			'total_records' => $this->totalProcessed,
			'total_batches' => $this->batchesProcessed,
			'total_duration_seconds' => $totalDuration,
			'avg_records_per_batch' => round($avgRecordsPerBatch, 2),
			'avg_duration_per_batch' => round($avgDurationPerBatch, 4),
			'overall_records_per_second' => round($overallRecordsPerSecond, 2),
		];

		error_log('Batch processing completed: ' . json_encode($summary));
	}

	/**
	 * Fetch a single batch of data
	 *
	 * @param string $query
	 *
	 * @return array<int,array<string,mixed>>
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function fetchBatch(string $query): array {
		$result = $this->client->sendRequest($query);

		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Batch SELECT failed: ' . $result->getError()
			);
		}

		$data = $result->getResult();
		if (is_array($data[0])) {
			return $data[0]['data'] ?? [];
		}

		throw ManticoreSearchClientError::create(
			'Batch SELECT failed. Wrong response structure'
		);
	}

	/**
	 * Process a single batch of records
	 *
	 * @param array<int,array<string,mixed>> $batch
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function processBatch(array $batch): void {
		if (empty($batch)) {
			return;
		}

		// Process each row for field type compatibility
		$processedBatch = [];
		foreach ($batch as $row) {
			$processedBatch[] = $this->processRow($row);
		}

		// Build and execute REPLACE query
		$this->executeReplaceBatch($processedBatch);
	}

	/**
	 * Process a single row for field type compatibility
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 * @throws ManticoreSearchClientError
	 */
	private function processRow(array $row): array {
		$processed = [];

		foreach ($row as $fieldName => $value) {
			if (!isset($this->targetFields[$fieldName])) {
				if (Config::isDebugEnabled()) {
					error_log("Skipping unknown field: $fieldName");
				}
				continue; // Skip unknown fields (shouldn't happen after validation)
			}

			try {
				$fieldType = $this->targetFields[$fieldName]['type'];
				if (!is_string($fieldType)) {
					throw new ManticoreSearchClientError(
						"Invalid field type for '$fieldName': expected string, got " . gettype($fieldType)
					);
				}
				$processed[$fieldName] = $this->morphValuesByFieldType($value, $fieldType);
			} catch (\Exception $e) {
				throw new ManticoreSearchClientError(
					"Failed to process field '$fieldName' with value '" . json_encode($value) . "': " . $e->getMessage()
				);
			}
		}

		// Ensure ID field is present
		if (!isset($processed['id'])) {
			throw new ManticoreSearchClientError("Row missing required 'id' field");
		}

		return $processed;
	}

	/**
	 * Execute REPLACE query for a batch of processed records
	 *
	 * @param array<int,array<string,mixed>> $batch
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function executeReplaceBatch(array $batch): void {
		if (empty($batch)) {
			return;
		}

		$fields = array_keys($batch[0]);
		$values = [];
		$valueCount = 0;

		foreach ($batch as $rowIndex => $row) {
			try {
				$rowValues = array_values($row);
				$values[] = '(' . implode(',', $rowValues) . ')';
				$valueCount++;
			} catch (\Exception $e) {
				throw new ManticoreSearchClientError(
					"Failed to format row $rowIndex for REPLACE: " . $e->getMessage()
				);
			}
		}

		$targetTable = $this->payload->getTargetTableWithCluster();

		$sql = sprintf(
			'REPLACE INTO %s (%s) VALUES %s',
			$targetTable,
			implode(',', $fields),
			implode(',', $values)
		);

		if (Config::isDebugEnabled()) {
			error_log("Executing REPLACE with $valueCount rows: " . substr($sql, 0, 200) . '...');
		}

		$result = $this->client->sendRequest($sql);

		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				"Batch REPLACE failed for $valueCount rows: " . $result->getError() .
				"\nSQL: " . substr($sql, 0, 500) . '...'
			);
		}
	}

	/**
	 * Get total number of processed records
	 *
	 * @return int
	 */
	public function getTotalProcessed(): int {
		return $this->totalProcessed;
	}

	/**
	 * Get number of processed batches
	 *
	 * @return int
	 */
	public function getBatchesProcessed(): int {
		return $this->batchesProcessed;
	}

	/**
	 * Get detailed processing statistics
	 *
	 * @return array<string,mixed>
	 */
	public function getProcessingStatistics(): array {
		$totalDuration = microtime(true) - $this->processingStartTime;

		return [
			'total_records' => $this->totalProcessed,
			'total_batches' => $this->batchesProcessed,
			'total_duration_seconds' => $totalDuration,
			'records_per_second' => $totalDuration > 0 ? $this->totalProcessed / $totalDuration : 0,
			'avg_batch_size' => $this->batchesProcessed > 0 ? $this->totalProcessed / $this->batchesProcessed : 0,
			'batch_statistics' => $this->statistics,
		];
	}
}
