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

			$validator = null;
			$processor = null;

			Buddy::debug('Starting REPLACE SELECT operation for table: ' . $this->payload->targetTable);

			try {
				// 1. Begin transaction
				$this->beginTransaction();

				// 2. Validate schema compatibility
				$validator = new FieldValidator($this->manticoreClient);
				$validator->validateCompatibility(
					$this->payload->selectQuery,
					$this->payload->targetTable
				);

				// 3. Execute batch processing
				$processor = new BatchProcessor(
					$this->manticoreClient,
					$this->payload,
					$validator->getTargetFields()
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

			// Log detailed debug information if enabled
			if (Config::isDebugEnabled()) {
				Buddy::debug('Processing statistics: ' . json_encode($processor->getProcessingStatistics()));
				Buddy::debug('Source query: ' . $this->payload->selectQuery);
				Buddy::debug('Target table: ' . $this->payload->getTargetTableWithCluster());
			}

			// Return result as a formatted table with proper column types
			return TaskResult::withData([$resultRow])
				->column('total', Column::Long)
				->column('batches', Column::Long)
				->column('batch_size', Column::Long)
				->column('duration_seconds', Column::String)
				->column('records_per_second', Column::String);
		} catch (\Exception $e) {
				// Enhanced error information
				$errorContext = [
					'operation' => 'REPLACE SELECT',
					'target_table' => $this->payload->targetTable,
					'select_query' => $this->payload->selectQuery,
					'batch_size' => $this->payload->batchSize,
					'transaction_started' => $this->transactionStarted,
					'operation_duration' => microtime(true) - $this->operationStartTime,
					'exception_class' => $e::class,
					'exception_code' => $e->getCode(),
				];

				if ($processor) {
					$errorContext['records_processed'] = $processor->getTotalProcessed();
					$errorContext['batches_processed'] = $processor->getBatchesProcessed();
				}

				$contextJson = json_encode($errorContext, JSON_PRETTY_PRINT);
				Buddy::debug(
					'REPLACE SELECT operation failed: ' . $e->getMessage() .
					"\nContext: " . $contextJson .
					"\nTrace: " . $e->getTraceAsString()
				);

				// Rollback transaction if it was started
				$this->rollbackTransaction();

				// Re-throw with enhanced context including exception type
				throw new ManticoreSearchClientError(
					$e->getMessage() . ' (processed ' . ($processor?->getTotalProcessed() ?? 0) . ' records)',
					$e->getCode(),
					$e
				);
			} finally {
				Buddy::debug(
					'REPLACE SELECT operation completed. Total duration: ' .
					round(microtime(true) - $this->operationStartTime, 3) . 's'
				);
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
			Buddy::debug('Transaction already started, skipping BEGIN');
			return; // Transaction already started
		}

		Buddy::debug('Starting transaction for REPLACE SELECT operation');
		$result = $this->manticoreClient->sendRequest('BEGIN');
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Failed to begin transaction: ' . $result->getError()
			);
		}

		$this->transactionStarted = true;
		Buddy::debug('Transaction started successfully');
	}

	/**
	 * Commit database transaction
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function commitTransaction(): void {
		if (!$this->transactionStarted) {
			Buddy::debug('No transaction to commit, skipping COMMIT');
			return; // No transaction to commit
		}

		Buddy::debug('Committing transaction');
		$result = $this->manticoreClient->sendRequest('COMMIT');
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Failed to commit transaction: ' . $result->getError()
			);
		}

		$this->transactionStarted = false;
		Buddy::debug('Transaction committed successfully');
	}

	/**
	 * Rollback database transaction
	 *
	 * @return void
	 */
	private function rollbackTransaction(): void {
		if (!$this->transactionStarted) {
			Buddy::debug('No transaction to rollback, skipping ROLLBACK');
			return; // No transaction to rollback
		}

		Buddy::debug('Rolling back transaction due to error');
		$result = $this->manticoreClient->sendRequest('ROLLBACK');
		if ($result->hasError()) {
			Buddy::debug('Warning: Failed to rollback transaction: ' . $result->getError());
		} else {
			Buddy::debug('Transaction rolled back successfully');
		}

		$this->transactionStarted = false;
	}
}
