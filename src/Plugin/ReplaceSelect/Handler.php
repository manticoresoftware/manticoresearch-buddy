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
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

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

			$lockManager = new LockManager($this->manticoreClient, $this->payload->targetTable);
			$validator = null;
			$processor = null;

			if (Config::isDebugEnabled()) {
				error_log('Starting REPLACE SELECT operation for table: ' . $this->payload->targetTable);
			}

			try {
				// 1. Acquire exclusive lock (this may retry)
				$lockManager->acquireLock();

				// 2. Begin transaction
				$this->beginTransaction();

				// 3. Validate schema compatibility
				$validator = new FieldValidator($this->manticoreClient);
				$validator->validateCompatibility(
					$this->payload->selectQuery,
					$this->payload->targetTable
				);

				// 4. Execute batch processing
				$processor = new BatchProcessor(
					$this->manticoreClient,
					$this->payload,
					$validator->getTargetFields()
				);

				$totalProcessed = $processor->execute();

				// 5. Commit transaction
				$this->commitTransaction();

				$operationDuration = microtime(true) - $this->operationStartTime;
				$lockDuration = $lockManager->getLockDuration();

				$result = [
					'total' => $totalProcessed,
					'batches' => $processor->getBatchesProcessed(),
					'batch_size' => $this->payload->batchSize,
					'duration_seconds' => round($operationDuration, 3),
					'lock_duration_seconds' => round($lockDuration, 3),
				'records_per_second' => $operationDuration > 0 ? round($totalProcessed / $operationDuration, 2) : 0,
				'message' => "Successfully processed $totalProcessed records in "
					. $processor->getBatchesProcessed() . ' batches',
				];

				if (Config::isDebugEnabled()) {
					$result['statistics'] = $processor->getProcessingStatistics();
					$result['query'] = $this->payload->selectQuery;
					$result['target_table'] = $this->payload->getTargetTableWithCluster();
				}

				return TaskResult::raw($result);
			} catch (\Exception $e) {
				// Enhanced error information
				$errorContext = [
					'operation' => 'REPLACE SELECT',
					'target_table' => $this->payload->targetTable,
					'select_query' => $this->payload->selectQuery,
					'batch_size' => $this->payload->batchSize,
					'transaction_started' => $this->transactionStarted,
					'lock_held' => $lockManager->hasLock(),
					'operation_duration' => microtime(true) - $this->operationStartTime,
				];

				if ($processor) {
					$errorContext['records_processed'] = $processor->getTotalProcessed();
					$errorContext['batches_processed'] = $processor->getBatchesProcessed();
				}

				error_log(
					'REPLACE SELECT operation failed: ' . $e->getMessage() .
					"\nContext: " . json_encode($errorContext)
				);

				// Rollback transaction if it was started
				$this->rollbackTransaction();

				// Re-throw with enhanced context
				throw new ManticoreSearchClientError(
					$e->getMessage() . ' (processed ' . ($processor?->getTotalProcessed() ?? 0) . ' records)',
					$e->getCode(),
					$e
				);
			} finally {
				// Always release lock
				$lockManager->releaseLock();

				if (Config::isDebugEnabled()) {
					error_log(
						'REPLACE SELECT operation completed. Total duration: ' .
						round(microtime(true) - $this->operationStartTime, 3) . 's'
					);
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
			throw ManticoreSearchClientError::create(
				'Failed to begin transaction: ' . $result->getError()
			);
		}

		$this->transactionStarted = true;

		if (!Config::isDebugEnabled()) {
			return;
		}

		error_log('Transaction started for REPLACE SELECT operation');
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
			throw ManticoreSearchClientError::create(
				'Failed to commit transaction: ' . $result->getError()
			);
		}

		$this->transactionStarted = false;

		if (!Config::isDebugEnabled()) {
			return;
		}

		error_log('Transaction committed successfully');
	}

	/**
	 * Rollback database transaction
	 *
	 * @return void
	 */
	private function rollbackTransaction(): void {
		if (!$this->transactionStarted) {
			return; // No transaction to rollback
		}

		$result = $this->manticoreClient->sendRequest('ROLLBACK');
		if ($result->hasError()) {
			error_log('Warning: Failed to rollback transaction: ' . $result->getError());
		} elseif (Config::isDebugEnabled()) {
			error_log('Transaction rolled back due to error');
		}

		$this->transactionStarted = false;
	}
}
