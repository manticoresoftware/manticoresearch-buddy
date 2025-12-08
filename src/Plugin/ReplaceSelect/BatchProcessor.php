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
		$this->logBatchStart();

		$batchSize = $this->batchSize;
		$consecutiveEmptyBatches = 0;
		$maxEmptyBatches = 3;
		$userLimit = $this->payload->selectLimit;
		$offset = $this->payload->selectOffset ?? 0;

		do {
			if ($this->hasReachedUserLimit($userLimit)) {
				break;
			}

			$batchStartTime = microtime(true);
			$baseQuery = $this->prepareBaseQuery();
			$currentBatchSize = $this->calculateCurrentBatchSize(
				$batchSize,
				$this->totalProcessed,
				$userLimit
			);

			$batchQuery = "{$baseQuery} LIMIT {$currentBatchSize} OFFSET {$offset}";
			Buddy::debug("Fetching batch at offset $offset with limit $currentBatchSize");
			Buddy::debug("Batch query: $batchQuery");

			try {
				$batch = $this->fetchBatch($batchQuery);

				if (empty($batch)) {
					if ($this->handleEmptyBatch($consecutiveEmptyBatches, $maxEmptyBatches)) {
						break;
					}
				} else {
					$this->handleNonEmptyBatch($batch, $batchStartTime, $consecutiveEmptyBatches);

					if ($this->hasReachedUserLimit($userLimit)) {
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
	 * Log batch processing initialization info
	 *
	 * @return void
	 */
	private function logBatchStart(): void {
		$batchSize = $this->batchSize;
		$userLimit = $this->payload->selectLimit;
		$userOffset = $this->payload->selectOffset ?? 0;

		Buddy::debug("Starting batch processing with size: $batchSize");

		if ($userLimit !== null) {
			Buddy::debug("SELECT LIMIT detected: processing max {$userLimit} records");
		}

		if ($userOffset <= 0) {
			return;
		}

		Buddy::debug("SELECT OFFSET detected: starting from offset {$userOffset}");
	}

	/**
	 * Prepare base query by removing existing LIMIT/OFFSET clauses
	 *
	 * @return string Clean base query
	 */
	private function prepareBaseQuery(): string {
		$baseQuery = $this->payload->selectQuery;
		return preg_replace('/\s+LIMIT\s+\d+\s*(?:OFFSET\s+\d+)?\s*$/i', '', $baseQuery);
	}

	/**
	 * Calculate current batch size respecting user LIMIT
	 *
	 * @param int $batchSize Default batch size
	 * @param int $processed Already processed records
	 * @param int|null $userLimit User-specified limit
	 * @return int Adjusted batch size
	 */
	private function calculateCurrentBatchSize(int $batchSize, int $processed, ?int $userLimit): int {
		$currentBatchSize = $batchSize;

		if ($userLimit === null) {
			return $currentBatchSize;
		}

		if (($processed + $currentBatchSize) <= $userLimit) {
			return $currentBatchSize;
		}

		$adjustedSize = max(0, $userLimit - $processed);
		Buddy::debug("Adjusting batch size to {$adjustedSize} to respect user LIMIT");
		return $adjustedSize;
	}

	/**
	 * Check if processing should stop due to user LIMIT
	 *
	 * @param int|null $userLimit User-specified limit
	 * @return bool True if limit reached
	 */
	private function hasReachedUserLimit(?int $userLimit): bool {
		if ($userLimit === null) {
			return false;
		}

		if ($this->totalProcessed >= $userLimit) {
			Buddy::debug("User LIMIT reached ({$userLimit}), stopping batch processing");
			return true;
		}

		return false;
	}

	/**
	 * Handle empty batch and determine if processing should stop
	 *
	 * @param int $consecutiveEmptyBatches Counter for consecutive empty batches
	 * @param int $maxEmptyBatches Maximum allowed empty batches
	 * @return bool True if processing should stop
	 */
	private function handleEmptyBatch(int &$consecutiveEmptyBatches, int $maxEmptyBatches): bool {
		$consecutiveEmptyBatches++;
		Buddy::debug("Batch is empty. Consecutive empty batches: $consecutiveEmptyBatches");

		if ($this->shouldStopProcessing($consecutiveEmptyBatches, $maxEmptyBatches)) {
			Buddy::debug('Stopping batch processing due to empty batches');
			return true;
		}

		return false;
	}

	/**
	 * Handle non-empty batch processing
	 *
	 * @param array<int,array<string,mixed>> $batch
	 * @param float $batchStartTime
	 * @param int $consecutiveEmptyBatches
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function handleNonEmptyBatch(
		array $batch,
		float $batchStartTime,
		int &$consecutiveEmptyBatches
	): void {
		Buddy::debug('Batch has ' . sizeof($batch) . ' records');
		$consecutiveEmptyBatches = 0;
		$this->processBatch($batch);
		$this->recordBatchStatistics($batch, $batchStartTime);
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
	 * - When column list is specified, process only those columns
	 * - Return indexed array (no field names)
	 *
	 * @param array<string,mixed> $row Associative array from SELECT result
	 * @return array<int,mixed> Indexed array of converted values
	 * @throws ManticoreSearchClientError
	 */
	private function processRow(array $row): array {
		// Convert row to indexed values array
		$values = array_values($row);

		// Route to appropriate processing method based on column list presence
		if ($this->payload->replaceColumnList !== null) {
			return $this->processRowWithColumnList($values);
		}

		return $this->processRowWithoutColumnList($values);
	}

	/**
	 * Build map from column name to target field position
	 *
	 * @return array<string,int> Column name to position map
	 */
	private function buildColumnToFieldMap(): array {
		$columnToFieldMap = [];
		foreach ($this->targetFieldsOrdered as $position => $fieldInfo) {
			$columnToFieldMap[$fieldInfo['name']] = $position;
		}
		return $columnToFieldMap;
	}

	/**
	 * Validate that field type is a string
	 *
	 * @param mixed $fieldType
	 * @param string $fieldName
	 * @param int $position
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateFieldType(mixed $fieldType, string $fieldName, int $position): void {
		if (is_string($fieldType)) {
			return;
		}

		throw new ManticoreSearchClientError(
			"Invalid field type for '$fieldName' at position $position: "
			. 'expected string, got ' . gettype($fieldType)
		);
	}

	/**
	 * Throw field processing error with context
	 *
	 * @param string $fieldName
	 * @param int $position
	 * @param mixed $value
	 * @param \Exception $e
	 * @return never
	 * @throws ManticoreSearchClientError
	 */
	private function throwFieldProcessingError(
		string $fieldName,
		int $position,
		mixed $value,
		\Exception $e
	): never {
		$valuePrev = json_encode($value);
		$errorMsg = "Failed to process field '$fieldName' at position $position "
			. "with value '$valuePrev': " . $e->getMessage();
		Buddy::debug("Field processing error: $errorMsg");
		throw new ManticoreSearchClientError($errorMsg);
	}

	/**
	 * Process single column value with type conversion
	 *
	 * @param string $columnName
	 * @param int $selectIndex
	 * @param array<int,mixed> $values
	 * @param array<string,int> $columnToFieldMap
	 * @param array<int,mixed> $processed Output array (by reference)
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function processColumn(
		string $columnName,
		int $selectIndex,
		array $values,
		array $columnToFieldMap,
		array &$processed
	): void {
		if (!isset($columnToFieldMap[$columnName])) {
			throw new ManticoreSearchClientError(
				"Column '$columnName' not found in target table"
			);
		}

		$targetPosition = $columnToFieldMap[$columnName];
		$fieldInfo = $this->targetFieldsOrdered[$targetPosition];
		$fieldType = $fieldInfo['type'];
		$value = $values[$selectIndex] ?? null;

		$this->validateFieldType($fieldType, $columnName, $targetPosition);

		try {
			Buddy::debug(
				"Processing column '$columnName' at position $targetPosition with type '$fieldType'"
			);
			$processed[$targetPosition] = $this->morphValuesByFieldType($value, $fieldType);
		} catch (\Exception $e) {
			$this->throwFieldProcessingError($columnName, $targetPosition, $value, $e);
		}
	}

	/**
	 * Process row with specified column list
	 *
	 * @param array<int,mixed> $values Indexed values array from row
	 * @return array<int,mixed> Processed values indexed by target position
	 * @throws ManticoreSearchClientError
	 */
	private function processRowWithColumnList(array $values): array {
		$expectedCount = sizeof($this->payload->replaceColumnList);
		if (sizeof($values) !== $expectedCount) {
			throw new ManticoreSearchClientError(
				'Row field count (' . sizeof($values) . ') does not match column list count ('
				. $expectedCount . ')'
			);
		}

		// Build column name to position map
		$columnToFieldMap = $this->buildColumnToFieldMap();

		// Process each column
		$processed = [];
		foreach ($this->payload->replaceColumnList as $selectIndex => $columnName) {
			$this->processColumn(
				$columnName,
				$selectIndex,
				$values,
				$columnToFieldMap,
				$processed
			);
		}

		return $processed;
	}

	/**
	 * Process field at specific position with type conversion
	 *
	 * @param int $index Field position
	 * @param mixed $value Field value
	 * @param array<int,mixed> $processed Output array (by reference)
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function processFieldAtPosition(int $index, mixed $value, array &$processed): void {
		try {
			$fieldInfo = $this->targetFieldsOrdered[$index];
			$fieldName = $fieldInfo['name'];
			$fieldType = $fieldInfo['type'];

			$this->validateFieldType($fieldType, $fieldName, $index);

			Buddy::debug("Processing position $index ('$fieldName') with type '$fieldType'");
			$processed[$index] = $this->morphValuesByFieldType($value, $fieldType);
		} catch (\Exception $e) {
			$fieldName = $this->targetFieldsOrdered[$index]['name'] ?? "position_$index";
			$this->throwFieldProcessingError($fieldName, $index, $value, $e);
		}
	}

	/**
	 * Process row without column list (all target fields)
	 *
	 * @param array<int,mixed> $values Indexed values array from row
	 * @return array<int,mixed> Processed values indexed by position
	 * @throws ManticoreSearchClientError
	 */
	private function processRowWithoutColumnList(array $values): array {
		// Validate field count
		if (sizeof($values) !== sizeof($this->targetFieldsOrdered)) {
			throw new ManticoreSearchClientError(
				'Row field count (' . sizeof($values) . ') does not match target field count ('
				. sizeof($this->targetFieldsOrdered) . ')'
			);
		}

		// Process each value by position
		$processed = [];
		foreach ($values as $index => $value) {
			$this->processFieldAtPosition($index, $value, $processed);
		}

		// Ensure ID field is present (must be at position 0)
		if (!isset($processed[0])) {
			throw new ManticoreSearchClientError(
				"Row missing required 'id' field at position 0"
			);
		}

		return $processed;
	}

	/**
	 * Determine columns and positions for REPLACE statement
	 *
	 * @return array{columnNames: array<int,string>, columnPositions: array<int,int>|null}
	 */
	private function determineReplaceColumns(): array {
		if ($this->payload->replaceColumnList !== null) {
			return $this->determineColumnsFromList();
		}

		return $this->determineColumnsFromTargetFields();
	}

	/**
	 * Determine columns from explicit column list
	 *
	 * @return array{columnNames: array<int,string>, columnPositions: array<int,int>}
	 */
	private function determineColumnsFromList(): array {
		// Build map from column name to target field position
		$columnToPositionMap = [];
		foreach ($this->targetFieldsOrdered as $position => $fieldInfo) {
			$columnToPositionMap[$fieldInfo['name']] = $position;
		}

		// Use only the specified columns in the order they were specified
		$columnNames = $this->payload->replaceColumnList;
		$columnPositions = array_map(
			fn($colName) => $columnToPositionMap[$colName],
			$columnNames
		);

		Buddy::debug(
			'Building REPLACE statement with column list: ' . implode(',', $columnNames)
		);

		return [
			'columnNames' => $columnNames,
			'columnPositions' => $columnPositions,
		];
	}

	/**
	 * Determine columns from target fields (all fields)
	 *
	 * @return array{columnNames: array<int,string>, columnPositions: null}
	 */
	private function determineColumnsFromTargetFields(): array {
		$columnNames = array_map(
			fn($f) => $f['name'],
			$this->targetFieldsOrdered
		);

		Buddy::debug(
			'Building REPLACE statement with ' . sizeof($this->targetFieldsOrdered) . ' fields'
		);

		return [
			'columnNames' => $columnNames,
			'columnPositions' => null,
		];
	}

	/**
	 * Extract row values for REPLACE statement
	 *
	 * @param array<int,mixed> $row
	 * @param array<int,int>|null $columnPositions
	 * @return array<int,string> Values ready for SQL insertion
	 */
	private function extractRowValues(array $row, ?array $columnPositions): array {
		$rowValues = [];

		if ($columnPositions !== null) {
			// Extract only specified columns by position
			foreach ($columnPositions as $position) {
				$value = $row[$position] ?? null;
				$rowValues[] = ($value === null ? 'NULL' : $value);
			}
		} else {
			// Extract all values in order
			foreach ($row as $value) {
				$rowValues[] = ($value === null ? 'NULL' : $value);
			}
		}

		return $rowValues;
	}

	/**
	 * Build VALUES clause for REPLACE statement
	 *
	 * @param array<int,array<int,mixed>> $batch
	 * @param array<int,int>|null $columnPositions
	 * @return array{values: array<int,string>, count: int}
	 * @throws ManticoreSearchClientError
	 */
	private function buildValuesClause(array $batch, ?array $columnPositions): array {
		$values = [];
		$valueCount = 0;

		foreach ($batch as $rowIndex => $row) {
			try {
				$rowValues = $this->extractRowValues($row, $columnPositions);
				$values[] = '(' . implode(',', $rowValues) . ')';
				$valueCount++;
			} catch (\Exception $e) {
				throw new ManticoreSearchClientError(
					"Failed to format row $rowIndex for REPLACE: " . $e->getMessage()
				);
			}
		}

		return [
			'values' => $values,
			'count' => $valueCount,
		];
	}

	/**
	 * Execute REPLACE query and handle errors
	 *
	 * @param string $sql
	 * @param int $valueCount
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function executeReplaceQuery(string $sql, int $valueCount): void {
		Buddy::debug(
			"Executing REPLACE with $valueCount rows. SQL preview: "
			. substr($sql, 0, 200) . '...'
		);

		$result = $this->client->sendRequest($sql);

		if ($result->hasError()) {
			$errorMsg = "Batch REPLACE failed for $valueCount rows: " . $result->getError()
				. "\nSQL: " . substr($sql, 0, 500) . '...';
			Buddy::debug("REPLACE execution error: $errorMsg");
			throw ManticoreSearchClientError::create($errorMsg);
		}

		Buddy::debug("REPLACE batch execution successful for $valueCount rows");
	}

	/**
	 * Execute REPLACE query for a batch of processed records
	 *
	 * Position-based REPLACE building:
	 * - When column list specified: use only those columns in REPLACE INTO (col1, col2)
	 * - When no column list: use all fields from targetFieldsOrdered
	 * - Values from indexed row arrays (extracted by position)
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

		// Determine columns for REPLACE statement
		$columns = $this->determineReplaceColumns();
		Buddy::debug('Building REPLACE for ' . sizeof($batch) . ' rows');

		// Build VALUES clause
		$valuesData = $this->buildValuesClause($batch, $columns['columnPositions']);

		// Build and execute SQL
		$targetTable = $this->payload->getTargetTableWithCluster();
		$sql = sprintf(
			'REPLACE INTO %s (%s) VALUES %s',
			$targetTable,
			implode(',', $columns['columnNames']),
			implode(',', $valuesData['values'])
		);

		$this->executeReplaceQuery($sql, $valuesData['count']);
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
