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
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Batch processing engine for REPLACE SELECT operations
 *
 * Uses position-based field mapping:
 * - Maps row values to target table fields by position
 * - Row values are indexed arrays (from SELECT result)
 * - Target fields are indexed by position (from DESC)
 * - No field name matching needed
 */
final class BatchProcessor {
	use StringFunctionsTrait;

	private Client $client;
	private Payload $payload;
	/** @var array<int,array<string,mixed>> Target fields ordered by position from DESC */
	private array $targetFieldsOrdered;
	private int $batchSize;
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
	 * @param array<int,array<string,mixed>> $targetFieldsOrdered Position-based fields from DESC
	 */
	public function __construct(Client $client, Payload $payload, array $targetFieldsOrdered) {
		$this->client = $client;
		$this->payload = $payload;
		$this->targetFieldsOrdered = $targetFieldsOrdered;
		$this->batchSize = Config::getBatchSize();
		$this->processingStartTime = microtime(true);
	}

	/**
	 * Execute the batch processing
	 *
	 * @return int Total number of records processed
	 * @throws ManticoreSearchClientError
	 */
	public function execute(): int {
		$batchSize = $this->batchSize;
		$consecutiveEmptyBatches = 0;
		$maxEmptyBatches = 3;
		$userLimit = $this->payload->selectLimit;
		$userOffset = $this->payload->selectOffset ?? 0;
		$offset = $userOffset;

		Buddy::debug("Starting batch processing with size: $batchSize");
		if ($userLimit !== null) {
			Buddy::debug("SELECT LIMIT detected: processing max {$userLimit} records");
		}
		if ($userOffset > 0) {
			Buddy::debug("SELECT OFFSET detected: starting from offset {$userOffset}");
		}

		do {
			$batchStartTime = microtime(true);
			$baseQuery = $this->payload->selectQuery;
			// Remove any existing LIMIT clause to avoid conflicts
			$baseQuery = preg_replace('/\s+LIMIT\s+\d+\s*(?:OFFSET\s+\d+)?\s*$/i', '', $baseQuery);

			// Calculate batch size for this iteration
			// If user specified a LIMIT, respect it as an upper bound
			$currentBatchSize = $batchSize;
			if ($userLimit !== null && ($this->totalProcessed + $currentBatchSize) > $userLimit) {
				$currentBatchSize = max(0, $userLimit - $this->totalProcessed);
				Buddy::debug("Adjusting batch size to {$currentBatchSize} to respect user LIMIT");
			}

			// Stop if we've reached the user's limit
			if ($userLimit !== null && $this->totalProcessed >= $userLimit) {
				Buddy::debug("User LIMIT reached ({$userLimit}), stopping batch processing");
				break;
			}

			$batchQuery = "{$baseQuery} LIMIT {$currentBatchSize} OFFSET {$offset}";

			Buddy::debug("Fetching batch at offset $offset with limit $currentBatchSize");
			Buddy::debug("Batch query: $batchQuery");

			try {
				$batch = $this->fetchBatch($batchQuery);

				if (empty($batch)) {
					$consecutiveEmptyBatches++;
					Buddy::debug("Batch is empty. Consecutive empty batches: $consecutiveEmptyBatches");
					if ($this->shouldStopProcessing($consecutiveEmptyBatches, $maxEmptyBatches)) {
						Buddy::debug('Stopping batch processing due to empty batches');
						break;
					}
				} else {
					Buddy::debug('Batch has ' . sizeof($batch) . ' records');
					$consecutiveEmptyBatches = 0;
					$this->processBatch($batch);
					$this->recordBatchStatistics($batch, $batchStartTime);

					// Check if we've reached the user's limit
					if ($userLimit !== null && $this->totalProcessed >= $userLimit) {
						Buddy::debug("User LIMIT reached ({$userLimit}), stopping batch processing");
						break;
					}
				}

				$offset += $currentBatchSize;
			} catch (\Exception $e) {
				$errorMsg = "Batch processing failed at offset $offset: " . $e->getMessage();
				Buddy::debug($errorMsg);
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
			Buddy::debug("Stopping after $consecutiveEmptyBatches consecutive empty batches");
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

		Buddy::debug(
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
		if (empty($this->statistics)) {
			Buddy::debug('No batches processed - no statistics to log');
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

		Buddy::debug('Batch processing completed: ' . json_encode($summary));
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
		Buddy::debug('Executing batch SELECT query');
		$result = $this->client->sendRequest($query);

		if ($result->hasError()) {
			$errorMsg = 'Batch SELECT failed: ' . $result->getError();
			Buddy::debug("Batch SELECT error: $errorMsg");
			throw ManticoreSearchClientError::create($errorMsg);
		}

		/** @var array<int,array<string,mixed>> $data */
		$data = $result->getResult()->toArray();

		// Validate data structure
		if (!is_array($data) || !isset($data[0])) {
			$errorMsg = 'Batch SELECT failed. Response is not an array with data';
			Buddy::debug("Batch SELECT error: $errorMsg");
			throw ManticoreSearchClientError::create($errorMsg);
		}

		// Try wrapped format first (standard Manticore response)
		if (is_array($data[0]) && isset($data[0]['data']) && is_array($data[0]['data'])) {
			$batchData = $data[0]['data'];
			Buddy::debug('Batch SELECT returned ' . sizeof($batchData) . ' records (wrapped format)');
			return $batchData;
		}

		// Try unwrapped format (data rows directly)
		if (is_array($data[0]) && !isset($data[0]['error'])
			&& !isset($data[0]['data']) && !isset($data[0]['columns'])) {
			Buddy::debug('Batch SELECT returned ' . sizeof($data) . ' records (unwrapped format)');
			return $data;
		}

		$errorMsg = 'Batch SELECT failed. Wrong response structure';
		Buddy::debug("Batch SELECT error: $errorMsg. Response: " . json_encode($data));
		throw ManticoreSearchClientError::create($errorMsg);
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
	 * Position-based processing:
	 * - Convert row to indexed values array
	 * - Match each value to target field by position
	 * - Apply type conversion
	 * - Return indexed array (no field names)
	 *
	 * @param array<string,mixed> $row Associative array from SELECT result
	 * @return array<int,mixed> Indexed array of converted values
	 * @throws ManticoreSearchClientError
	 */
	private function processRow(array $row): array {
		// Convert row to indexed values array
		$values = array_values($row);
		$processed = [];

		// Validate count matches
		if (count($values) !== count($this->targetFieldsOrdered)) {
			$errorMsg = 'Row field count (' . count($values) . ') does not match target field count ('
				. count($this->targetFieldsOrdered) . ')';
			Buddy::debug("Row validation error: $errorMsg");
			throw new ManticoreSearchClientError($errorMsg);
		}

		// Process each value by position
		foreach ($values as $index => $value) {
			try {
				$fieldInfo = $this->targetFieldsOrdered[$index];
				$fieldName = $fieldInfo['name'];
				$fieldType = $fieldInfo['type'];

				if (!is_string($fieldType)) {
					$errorMsg = "Invalid field type for position $index ('$fieldName'): expected string, got "
						. gettype($fieldType);
					Buddy::debug("Field type error: $errorMsg");
					throw new ManticoreSearchClientError($errorMsg);
				}

				Buddy::debug("Processing position $index ('$fieldName') with type '$fieldType'");
				$processed[$index] = $this->morphValuesByFieldType($value, $fieldType);
			} catch (\Exception $e) {
				$valuePrev = json_encode($value);
				$fieldName = $this->targetFieldsOrdered[$index]['name'] ?? "position_$index";
				$errorMsg = "Failed to process field '$fieldName' at position $index with value '$valuePrev': "
					. $e->getMessage();
				Buddy::debug("Field processing error: $errorMsg");
				throw new ManticoreSearchClientError($errorMsg);
			}
		}

		// Ensure ID field is present (must be at position 0)
		if (!isset($processed[0])) {
			$errorMsg = "Row missing required 'id' field at position 0";
			Buddy::debug("Row validation error: $errorMsg");
			throw new ManticoreSearchClientError($errorMsg);
		}

		return $processed;
	}

	/**
	 * Execute REPLACE query for a batch of processed records
	 *
	 * Position-based REPLACE building:
	 * - Column list from targetFieldsOrdered in guaranteed order
	 * - Values from indexed row arrays
	 * - Preserves target DESC field order
	 *
	 * @param array<int,array<int,mixed>> $batch Indexed array of indexed row arrays
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function executeReplaceBatch(array $batch): void {
		if (empty($batch)) {
			Buddy::debug('Empty batch, skipping REPLACE execution');
			return;
		}

		// Build column list from targetFieldsOrdered (guaranteed order)
		$columnNames = array_map(
			fn($f) => $f['name'],
			$this->targetFieldsOrdered
		);
		$fields = implode(',', $columnNames);

		$values = [];
		$valueCount = 0;

		Buddy::debug(
			'Building REPLACE statement with ' . sizeof($batch) . ' rows and '
			. count($this->targetFieldsOrdered) . ' fields'
		);

		foreach ($batch as $rowIndex => $row) {
			try {
				// Row values are already indexed, just convert to SQL format
				$rowValues = [];
				foreach ($row as $value) {
					// Value is already type-converted by processRow
					$rowValues[] = is_string($value) ? "'$value'" : ($value === null ? 'NULL' : $value);
				}
				$values[] = '(' . implode(',', $rowValues) . ')';
				$valueCount++;
			} catch (\Exception $e) {
				$errorMsg = "Failed to format row $rowIndex for REPLACE: " . $e->getMessage();
				Buddy::debug("REPLACE row formatting error: $errorMsg");
				throw new ManticoreSearchClientError($errorMsg);
			}
		}

		$targetTable = $this->payload->getTargetTableWithCluster();

		$sql = sprintf(
			'REPLACE INTO %s (%s) VALUES %s',
			$targetTable,
			$fields,
			implode(',', $values)
		);

		Buddy::debug("Executing REPLACE with $valueCount rows. SQL preview: " . substr($sql, 0, 200) . '...');

		$result = $this->client->sendRequest($sql);

		if ($result->hasError()) {
			$errorMsg = "Batch REPLACE failed for $valueCount rows: " . $result->getError() .
				"\nSQL: " . substr($sql, 0, 500) . '...';
			Buddy::debug("REPLACE execution error: $errorMsg");
			throw ManticoreSearchClientError::create($errorMsg);
		}

		Buddy::debug("REPLACE batch execution successful for $valueCount rows");
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
