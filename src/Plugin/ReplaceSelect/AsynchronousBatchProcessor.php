<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ReplaceSelect;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Swoole\Coroutine;
use Throwable;

/**
 * Asynchronous concurrent batch processor for REPLACE SELECT operations
 *
 * Processing model:
 * - Processes up to swoole_cpu_num() batches in-flight simultaneously
 * - Each batch is split into swoole_cpu_num() chunks
 * - Fires sendMultiRequest in coroutines without waiting for responses
 * - Continues reading next batch while previous chunks are processing
 * - Checks responses when available via shared error state
 * - Any chunk failure stops everything and triggers rollback
 *
 * @phpstan-type FieldInfo array{name: string, type: string, properties: string}
 */
final class AsynchronousBatchProcessor extends BatchProcessor {

	/** @var bool Flag indicating if an error occurred in any async operation */
	private bool $hasErrors = false;

	/** @var string Error message from failed async operation */
	private string $errorMessage = '';

	/** @var int Number of in-flight async operations */
	private int $inFlightBatches = 0;

	/** @var int Maximum concurrent batches allowed */
	private int $maxInFlightBatches;

	/**
	 * Constructor
	 *
	 * @param mixed $client
	 * @param mixed $payload
	 * @param array<int,mixed> $targetFieldsOrdered
	 * @param int $batchSize
	 * @throws ManticoreSearchClientError
	 */
	public function __construct(
		mixed $client,
		mixed $payload,
		array $targetFieldsOrdered,
		int $batchSize
	) {
		parent::__construct($client, $payload, $targetFieldsOrdered, $batchSize);
		$this->maxInFlightBatches = swoole_cpu_num();
	}

	/**
	 * Execute batch processing with asynchronous concurrent writes
	 *
	 * @return int Total number of records processed
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function execute(): int {
		try {
			return $this->executeAsyncLoop();
		} catch (Throwable $e) {
			// Wait for all in-flight operations before propagating error
			while ($this->inFlightBatches > 0) {
				Coroutine::sleep(0.001);
			}
			throw $e;
		}
	}

	/**
	 * Execute the main async batch processing loop
	 *
	 * @return int Total number of records processed
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function executeAsyncLoop(): int {
		$batchSize = $this->batchSize;
		$consecutiveEmptyBatches = 0;
		$maxEmptyBatches = 3;
		$userLimit = $this->payload->selectLimit;
		$offset = $this->payload->selectOffset ?? 0;

		do {
			$this->checkAsyncErrors();
			$this->waitForCapacity();

			if ($this->hasReachedUserLimit($userLimit)) {
				break;
			}

			$batchStartTime = microtime(true);
			$currentBatchSize = $this->calculateCurrentBatchSize(
				$batchSize,
				$this->totalProcessed,
				$userLimit
			);

			$batchQuery = "{$this->baseQueryTemplate} LIMIT $currentBatchSize OFFSET $offset";
			$batch = $this->fetchBatch($batchQuery);

			if (empty($batch)) {
				if ($this->handleEmptyBatch($consecutiveEmptyBatches, $maxEmptyBatches)) {
					break;
				}
			} else {
				$this->handleNonEmptyBatchAsync($batch, $batchStartTime, $consecutiveEmptyBatches);

				if ($this->hasReachedUserLimit($userLimit)) {
					break;
				}
			}

			$offset += $currentBatchSize;
		} while (sizeof($batch) === $batchSize);

		$this->waitForCompletion();

		$this->logProcessingStatistics();
		return $this->totalProcessed;
	}

	/**
	 * Check for errors from async operations
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function checkAsyncErrors(): void {
		if ($this->hasErrors) {
			throw ManticoreSearchClientError::create($this->errorMessage);
		}
	}

	/**
	 * Wait for in-flight batch capacity
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function waitForCapacity(): void {
		while ($this->inFlightBatches >= $this->maxInFlightBatches) {
			Coroutine::sleep(0.001);
			$this->checkAsyncErrors();
		}
	}

	/**
	 * Wait for all in-flight operations to complete
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function waitForCompletion(): void {
		while ($this->inFlightBatches > 0) {
			$this->checkAsyncErrors();
			Coroutine::sleep(0.001);
		}

		// Final error check before returning
		$this->checkAsyncErrors();
	}

	/**
	 * Handle non-empty batch by firing it asynchronously
	 *
	 * @param array<int,array<string,mixed>> $batch
	 * @param float $batchStartTime
	 * @param int $consecutiveEmptyBatches
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function handleNonEmptyBatchAsync(
		array $batch,
		float $batchStartTime,
		int &$consecutiveEmptyBatches
	): void {
		$consecutiveEmptyBatches = 0;

		// Process row data synchronously
		$processedBatch = [];
		foreach ($batch as $row) {
			$processedBatch[] = $this->processRow($row);
		}

		// Fire asynchronously
		$this->fireAsyncBatch($processedBatch, $batchStartTime);
	}

	/**
	 * Fire a batch for asynchronous processing
	 *
	 * Spawns a coroutine that executes the batch insert without waiting.
	 *
	 * @param array<int,array<int,mixed>> $processedBatch
	 * @param float $batchStartTime
	 * @return void
	 */
	private function fireAsyncBatch(array $processedBatch, float $batchStartTime): void {
		$this->inFlightBatches++;

		$closure = function () use ($processedBatch, $batchStartTime): void {
			try {
				$this->executeAsyncReplaceBatch($processedBatch);
				$this->recordBatchStatistics($processedBatch, $batchStartTime);
			} catch (Throwable $e) {
				// Capture error for main thread to detect
				$this->hasErrors = true;
				$this->errorMessage = 'Async batch failed: ' . $e->getMessage();
			} finally {
				$this->inFlightBatches--;
			}
		};
		go($closure);
	}

	/**
	 * Execute REPLACE for a batch asynchronously with concurrent chunks
	 *
	 * Splits batch into CPU-based chunks and sends concurrently.
	 *
	 * @param array<int,array<int,mixed>> $batch
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function executeAsyncReplaceBatch(array $batch): void {
		if (empty($batch)) {
			return;
		}

		$cpuCount = swoole_cpu_num();
		$batchSize = sizeof($batch);

		// Small batch - use sequential
		if ($batchSize <= $cpuCount) {
			$this->executeReplaceBatchSequential($batch);
			return;
		}

		// Split into chunks for concurrent execution
		$chunkSize = (int)ceil($batchSize / $cpuCount);
		$chunks = array_chunk($batch, $chunkSize);

		$requests = [];
		$columns = $this->determineReplaceColumns();

		foreach ($chunks as $chunk) {
			$sql = $this->buildChunkSQL($chunk, $columns);
			$requests[] = [
				'url' => '',
				'path' => 'sql?mode=raw',
				'request' => $sql,
			];
		}

		// Execute all chunks concurrently
		$responses = $this->client->sendMultiRequest($requests);

		// Check for any failures
		foreach ($responses as $i => $response) {
			if ($response->hasError()) {
				throw ManticoreSearchClientError::create(
					'Async concurrent chunk ' . ($i + 1) . ' failed: ' . $response->getError()
				);
			}
		}
	}

	/**
	 * Execute batch sequentially (fallback for small batches)
	 *
	 * @param array<int,array<int,mixed>> $batch
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function executeReplaceBatchSequential(array $batch): void {
		if (empty($batch)) {
			return;
		}

		$columns = $this->determineReplaceColumns();
		$valuesData = $this->buildValuesClause($batch, $columns['columnPositions']);

		$targetTable = $this->payload->getTargetTableWithCluster();
		$sql = sprintf(
			/** @lang manticore*/            'REPLACE INTO %s (%s) VALUES %s',
			$targetTable,
			implode(',', $columns['columnNames']),
			implode(',', $valuesData['values'])
		);

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Sequential batch insert failed: ' . $result->getError()
			);
		}
	}

	/**
	 * Build SQL for a chunk
	 *
	 * @param array<int,array<int,mixed>> $chunk
	 * @param array{columnNames: array<int,string>, columnPositions: array<int,int>|null} $columns
	 * @return string
	 * @throws ManticoreSearchClientError
	 */
	private function buildChunkSQL(array $chunk, array $columns): string {
		$valuesData = $this->buildValuesClause($chunk, $columns['columnPositions']);
		$targetTable = $this->payload->getTargetTableWithCluster();

		return sprintf(
			/** @lang manticore*/            'REPLACE INTO %s (%s) VALUES %s',
			$targetTable,
			implode(',', $columns['columnNames']),
			implode(',', $valuesData['values'])
		);
	}

	/**
	 * Handle empty batch
	 *
	 * @param int $consecutiveEmptyBatches
	 * @param int $maxEmptyBatches
	 * @return bool True if processing should stop
	 */
	private function handleEmptyBatch(int &$consecutiveEmptyBatches, int $maxEmptyBatches): bool {
		$consecutiveEmptyBatches++;
		if ($consecutiveEmptyBatches >= $maxEmptyBatches) {
			return true;
		}
		return false;
	}

	/**
	 * Calculate current batch size respecting user LIMIT
	 *
	 * @param int $batchSize
	 * @param int $processed
	 * @param int|null $userLimit
	 * @return int
	 */
	private function calculateCurrentBatchSize(int $batchSize, int $processed, ?int $userLimit): int {
		$currentBatchSize = $batchSize;

		if ($userLimit === null) {
			return $currentBatchSize;
		}

		if (($processed + $currentBatchSize) <= $userLimit) {
			return $currentBatchSize;
		}

		return max(0, $userLimit - $processed);
	}


}
