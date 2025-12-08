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
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Main execution orchestrator for REPLACE SELECT operations
 *
 * Uses position-based field mapping:
 * - Validator returns targetFieldsOrdered (indexed by position)
 * - BatchProcessor processes rows by position, not by field name
 * - REPLACE statements have guaranteed column order from DESC
 */
final class Handler extends BaseHandlerWithClient {
	private bool $transactionStarted = false;
	private float $operationStartTime;

	/**
	 * Constructor
	 *
	 * @param Payload $payload
	 */
	public function __construct(public Payload $payload) {
		$this->operationStartTime = microtime(true);
	}

	/**
	 * Execute the REPLACE SELECT operation
	 *
	 * @return Task
	 */
	public function run(): Task {
		$taskFn = function (): TaskResult {
			// Pre-validate payload
			$this->payload->validate();

			try {
				// 1. Begin transaction
				$this->beginTransaction();

				// 2. Validate schema compatibility
				$validator = new FieldValidator($this->manticoreClient);
				$validator->validateCompatibility(
					$this->payload->selectQuery,
					$this->payload->targetTable,
					$this->payload->replaceColumnList
				);

				// 3. Execute batch processing
				$processor = new BatchProcessor(
					$this->manticoreClient,
					$this->payload,
					$validator->getTargetFields(),
					$this->payload->batchSize
				);

				$totalProcessed = $processor->execute();

				// 4. Commit transaction
				$this->commitTransaction();

				$operationDuration = microtime(true) - $this->operationStartTime;

				// Build result row with metrics
				$resultRow = [
					'total' => $totalProcessed,
					'batches' => $processor->getBatchesProcessed(),
					'batch_size' => $this->payload->batchSize,
					'duration_seconds' => round($operationDuration, 3),
					'records_per_second' => $operationDuration > 0 ? round($totalProcessed / $operationDuration, 2) : 0,
				];

				// Return result as a formatted table with proper column types
				return TaskResult::withData([$resultRow])
					->column('total', Column::Long)
					->column('batches', Column::Long)
					->column('batch_size', Column::Long)
					->column('duration_seconds', Column::String)
					->column('records_per_second', Column::String);
			} finally {
				if ($this->transactionStarted) {
					$this->rollbackTransaction();
				}
			}
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * Begin database transaction
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function beginTransaction(): void {
		if ($this->transactionStarted) {
			return; // Transaction already started
		}

		$result = $this->manticoreClient->sendRequest('BEGIN');
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Database transaction failed');
		}

		$this->transactionStarted = true;
	}

	/**
	 * Commit database transaction
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function commitTransaction(): void {
		if (!$this->transactionStarted) {
			return; // No transaction to commit
		}

		$result = $this->manticoreClient->sendRequest('COMMIT');
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Failed to commit transaction');
		}

		$this->transactionStarted = false;
	}


	/**
	 * Rollback database transaction
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function rollbackTransaction(): void {
		if (!$this->transactionStarted) {
			return; // No transaction to rollback
		}

		$result = $this->manticoreClient->sendRequest('ROLLBACK');
		if ($result->hasError()) {
			Buddy::debug('Warning: Failed to rollback transaction: ' . $result->getError());
		}

		$this->transactionStarted = false;
	}
}
