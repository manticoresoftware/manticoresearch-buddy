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
	private int $recordsProcessedBeforeError = 0;

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
					'batch_size' => Config::getBatchSize(),
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
			} catch (ManticoreSearchClientError $e) {
				throw $this->enhanceError($e);
			} finally {
				// Ensure transaction is rolled back if it was started
				// This allows any exception to propagate naturally to EventHandler
				// with its original, detailed error message
				if ($this->transactionStarted) {
					$this->rollbackTransaction();
				}

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
			$errorMessage = $this->buildErrorContext(
				'Failed to begin transaction: ' . $result->getError(),
				$this->recordsProcessedBeforeError
			);
			$error = new ManticoreSearchClientError($errorMessage);
			$error->setResponseError($errorMessage);
			throw $error;
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
			$errorMessage = $this->buildErrorContext(
				'Failed to commit transaction: ' . $result->getError(),
				$this->recordsProcessedBeforeError
			);
			$error = new ManticoreSearchClientError($errorMessage);
			$error->setResponseError($errorMessage);
			throw $error;
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

	/**
	 * Build error message with operation context
	 *
	 * @param string $originalError
	 * @param int $recordsProcessed
	 * @return string
	 */
	private function buildErrorContext(string $originalError, int $recordsProcessed = 0): string {
		return sprintf(
			'Operation error (processed %d records): %s',
			$recordsProcessed,
			$originalError
		);
	}

	/**
	 * Enhance error with operation context if not already enhanced
	 *
	 * @param ManticoreSearchClientError $e
	 * @return ManticoreSearchClientError
	 */
	private function enhanceError(ManticoreSearchClientError $e): ManticoreSearchClientError {
		if (stripos($e->getMessage(), 'processed') !== false) {
			return $e;
		}

		$errorMsg = $e->getMessage() ?: $e->getResponseError();
		$contextualError = new ManticoreSearchClientError(
			$this->buildErrorContext($errorMsg, $this->recordsProcessedBeforeError)
		);
		$contextualError->setResponseError($contextualError->getMessage());
		return $contextualError;
	}
}
