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
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Synchronous concurrent batch processor for REPLACE SELECT operations
 *
 * Processing model:
 * - Processes 1 batch at a time
 * - Splits each batch into swoole_cpu_num() chunks
 * - Sends all chunks concurrently via sendMultiRequest
 * - Waits for all chunk responses before proceeding to next batch
 * - Any chunk failure immediately throws exception (triggers rollback)
 *
 * @phpstan-type FieldInfo array{name: string, type: string, properties: string}
 */
final class SynchronousConcurrentBatchProcessor extends BatchProcessor {

	/**
	 * Execute REPLACE batch with synchronous concurrent processing
	 *
	 * Splits batch into CPU-count chunks and processes concurrently.
	 * Small batches fall back to sequential processing to avoid overhead.
	 *
	 * @param array<int,array<int,mixed>> $batch
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	protected function executeReplaceBatch(array $batch): void {
		if (empty($batch)) {
			return;
		}

		$cpuCount = swoole_cpu_num();
		$batchSize = sizeof($batch);

		// Small batch optimization - don't split if batch is smaller than or equal to CPU count
		if ($batchSize <= $cpuCount) {
			parent::executeReplaceBatch($batch);
			return;
		}

		// Split batch into CPU-based chunks
		$chunkSize = (int)ceil($batchSize / $cpuCount);
		$chunks = array_chunk($batch, $chunkSize);

		Buddy::debug(
			sprintf(
				'SyncConcurrent: Processing %d records in %d chunks (chunk_size=%d, cpu_cores=%d)',
				$batchSize,
				sizeof($chunks),
				$chunkSize,
				$cpuCount
			)
		);

		$this->executeConcurrentChunks($chunks);
	}

	/**
	 * Execute all chunks concurrently and wait for responses
	 *
	 * @param array<int,array<int,array<int,mixed>>> $chunks
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function executeConcurrentChunks(array $chunks): void {
		// Build all chunk requests
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

		// Execute all chunks concurrently via sendMultiRequest
		$startTime = microtime(true);
		$responses = $this->client->sendMultiRequest($requests);
		$duration = microtime(true) - $startTime;

		// Check for any failures - throw immediately if found
		foreach ($responses as $i => $response) {
			if ($response->hasError()) {
				throw ManticoreSearchClientError::create(
					'Concurrent chunk ' . ($i + 1) . ' failed: ' . $response->getError()
				);
			}
		}

		Buddy::debug(
			sprintf(
				'SyncConcurrent: %d chunks completed in %.3fs (%.0f chunks/sec)',
				sizeof($chunks),
				$duration,
				sizeof($chunks) / ($duration > 0 ? $duration : 1)
			)
		);
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
}
