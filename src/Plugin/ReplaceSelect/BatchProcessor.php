<?php declare(strict_types=1);

/*
 * Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 3 or any later
 * version. You should have received a copy of the GPL license along with this
 * program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ReplaceSelect;

use Exception;
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
 *
 * @phpstan-type FieldInfo array{name: string, type: string, properties: string}
 */
final class BatchProcessor
{
	use StringFunctionsTrait;

	private Client $client;
	private Payload $payload;
	/** @var array<int,FieldInfo> Target fields ordered by position from DESC */
	private array $targetFieldsOrdered;
	private int $batchSize;
	/** @var int<1,max> */
	private int $chunkSize;
	private int $totalProcessed = 0;
	private int $batchesProcessed = 0;
	private int $chunksProcessed = 0;
	private float $processingStartTime;
	/** @var array<int,array<string,mixed>> */
	private array $statistics = [];
	/** Base query template with LIMIT/OFFSET removed, parsed once during construction */
	private string $baseQueryTemplate;

	/**
	 * Constructor
	 *
	 * @param Client $client
	 * @param Payload $payload
	 * @param array<int,FieldInfo> $targetFieldsOrdered Position-based fields from DESC
	 * @param int $batchSize
	 * @throws ManticoreSearchClientError
	 */
	public function __construct(
		Client $client,
		Payload $payload,
		array $targetFieldsOrdered,
		int $batchSize
	) {
		$this->client = $client;
		$this->payload = $payload;
		$this->targetFieldsOrdered = $targetFieldsOrdered;
		$this->processingStartTime = microtime(true);
		$this->batchSize = $batchSize;
		$this->chunkSize = max(1, (int)($batchSize / swoole_cpu_num()));
		$this->baseQueryTemplate = $this->prepareBaseQueryTemplate();
	}

	/**
	 * Prepare base query template by removing existing LIMIT/OFFSET clauses
	 * and adding ORDER BY if not present
	 *
	 * @return string Clean base query template
	 * @throws ManticoreSearchClientError
	 */
	private function prepareBaseQueryTemplate(): string {
		$baseQuery = $this->payload->selectQuery;

		// Remove LIMIT/OFFSET clauses and store them for later
		$limitOffsetMatch = [];
		if (preg_match('/(\s+LIMIT\s+\d+\s*(?:OFFSET\s+\d+)?)\s*$/i', $baseQuery, $limitOffsetMatch)) {
			$baseQuery = preg_replace('/\s+LIMIT\s+\d+\s*(?:OFFSET\s+\d+)?\s*$/i', '', $baseQuery);
		}

		if ($baseQuery === null) {
			throw ManticoreSearchClientError::create(
				'Invalid SELECT query format'
			);
		}

		// Add ORDER BY id ASC if no ORDER BY clause is present
		if (!$this->payload->hasOrderBy) {
			$baseQuery .= ' ORDER BY id ASC';
		}

		// Note: Do not add back the original LIMIT/OFFSET clauses to the base template.
		// The user's LIMIT/OFFSET are stored in $payload->selectLimit and $payload->selectOffset
		// and will be enforced by the batch processing logic, not embedded in the query template.

		return $baseQuery;
	}

	/**
	 * Execute the batch processing
	 *
	 * @return int Total number of records processed
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function execute(): int {
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
			$currentBatchSize = $this->calculateCurrentBatchSize(
				$batchSize,
				$this->totalProcessed,
				$userLimit
			);

			$batchQuery = "{$this->baseQueryTemplate} LIMIT $currentBatchSize OFFSET $offset";

			if ($offset >= 1000) {
				$batchQuery .= ' OPTION max_matches='.($currentBatchSize + $offset);
			}

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
		} while (sizeof($batch) === $batchSize);

		$this->logProcessingStatistics();
		return $this->totalProcessed;
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
			Buddy::debug("User LIMIT reached ($userLimit), stopping batch processing");
			return true;
		}

		return false;
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

		return max(0, $userLimit - $processed);
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
			$errorMsg = 'SELECT query failed: ' . $result->getError();
			throw ManticoreSearchClientError::create($errorMsg);
		}

		/** @var array<int,array<string,mixed>> $data */
		$data = $result->getResult()->toArray();

		// Validate data structure
		if (!isset($data[0])) {
			$errorMsg = 'Invalid query response format';
			throw ManticoreSearchClientError::create($errorMsg);
		}

		// Data is guaranteed to be array and have [0] at this point
		$firstElement = $data[0];

		// Try wrapped format first (standard Manticore response)
		if (isset($firstElement['data']) && is_array($firstElement['data'])) {
			return $firstElement['data'];
		}

		// Try unwrapped format (data rows directly)
		if (!isset($firstElement['error']) && !isset($firstElement['data'])
			&& !isset($firstElement['columns'])) {
			return $data;
		}

		$errorMsg = 'Invalid query response structure';
		throw ManticoreSearchClientError::create($errorMsg);
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
		return $this->shouldStopProcessing($consecutiveEmptyBatches, $maxEmptyBatches);
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
		$consecutiveEmptyBatches = 0;
		$this->processBatch($batch);
		$this->recordBatchStatistics($batch, $batchStartTime);
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

		// Pass raw associative arrays to executeReplaceBatch for processing
		$this->executeReplaceBatch($batch);
	}

	/**
	 * Process a single row for field type compatibility
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
	 * Process row with specified column list
	 *
	 * @param array<int,mixed> $values Indexed values array from row
	 * @return array<int,mixed> Processed values indexed by target position
	 * @throws ManticoreSearchClientError
	 */
	private function processRowWithColumnList(array $values): array {
		$columnList = $this->payload->replaceColumnList;

		if ($columnList === null) {
			throw ManticoreSearchClientError::create(
				'Missing column list for REPLACE operation'
			);
		}

		$expectedCount = sizeof($columnList);
		if (sizeof($values) !== $expectedCount) {
			throw ManticoreSearchClientError::create(
				'Column count mismatch: row has ' . sizeof($values) . ' values but expected ' . $expectedCount
			);
		}

		// Build column name to position map
		$columnToFieldMap = $this->buildColumnToFieldMap();

		// Process each column
		$processed = [];
		foreach ($columnList as $selectIndex => $columnName) {
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
			throw ManticoreSearchClientError::create(
				"Column '$columnName' does not exist in target table"
			);
		}

		$targetPosition = $columnToFieldMap[$columnName];
		$fieldInfo = $this->targetFieldsOrdered[$targetPosition];

		if (!is_string($fieldInfo['type'])) {
			throw ManticoreSearchClientError::create(
				'Invalid target table structure'
			);
		}

		$fieldType = $fieldInfo['type'];
		$value = $values[$selectIndex] ?? null;

		try {
			$processed[$targetPosition] = $this->morphValuesByFieldType($value, $fieldType);
		} catch (Exception $e) {
			$errorMsg = "Invalid data in column '$columnName': " . $e->getMessage();
			throw ManticoreSearchClientError::create($errorMsg);
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
			$targetCount = sizeof($this->targetFieldsOrdered);
			throw ManticoreSearchClientError::create(
				'Column count mismatch: row has ' . sizeof($values) . " values but target has $targetCount"
			);
		}

		// Process each value by position
		$processed = [];
		foreach ($values as $index => $value) {
			$this->processFieldAtPosition($index, $value, $processed);
		}

		// Ensure ID field is present (must be at position 0)
		if (!isset($processed[0])) {
			throw ManticoreSearchClientError::create(
				"Missing 'id' column in data"
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

			/** @var string $fieldName */
			$fieldName = $fieldInfo['name'];
			/** @var string $fieldType */
			$fieldType = $fieldInfo['type'];

			$processed[$index] = $this->morphValuesByFieldType($value, $fieldType);
		} catch (Exception $e) {
			$fieldInfo = $this->targetFieldsOrdered[$index] ?? [];
			$fieldName = is_string($fieldInfo['name'] ?? null) ? $fieldInfo['name'] : "position_$index";
			$errorMsg = "Invalid data in column '$fieldName': " . $e->getMessage();
			throw ManticoreSearchClientError::create($errorMsg);
		}
	}

	/**
	 * Execute REPLACE operations using bulk JSON API for a batch of records
	 *
	 * @param array<int,array<string,mixed>> $batch Array of associative row arrays
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function executeReplaceBatch(array $batch): void {
		if (empty($batch)) {
			return;
		}

		// Process each row for field type compatibility (convert associative to indexed)
		$processedBatch = [];
		foreach ($batch as $row) {
			$processedBatch[] = $this->processRow($row);
		}

		// Split batch into chunks for parallel processing
		$chunks = array_chunk($processedBatch, $this->chunkSize);
		$requests = [];

		foreach ($chunks as $chunk) {
			$bulkJson = $this->buildChunkBulk($chunk);
			$requests[] = $this->buildRequest($bulkJson);
		}

		$this->executeReplaceChunks($requests);
	}

	/**
	 * Build JSON bulk operations for a single chunk
	 *
	 * @param array<int,array<int,mixed>> $chunk Chunk of rows to convert to bulk JSON
	 * @return string JSON bulk operations (NDJSON format)
	 * @throws ManticoreSearchClientError
	 */
	private function buildChunkBulk(array $chunk): string {
		$targetTable = $this->payload->getTargetTableWithCluster();
		$columns = $this->determineReplaceColumns();

		$bulkLines = [];
		foreach ($chunk as $row) {
			// Row is already processed by processBatch(), use directly
			$processedRow = $row;

			// Extract ID field (required for bulk operations)
			$id = $this->extractIdFromRow($processedRow, $columns);

			// Build document data (exclude ID field)
			$doc = $this->buildDocumentFromRow($processedRow, $columns);

			// Create bulk operation JSON
			$operation = [
				'replace' => [
					'id' => $id,
					'table' => $targetTable,
					'doc' => $doc,
				],
			];

			$bulkLines[] = json_encode($operation);
		}

		return implode(PHP_EOL, $bulkLines) . PHP_EOL;
	}

	/**
	 * Determine columns and positions for REPLACE statement
	 *
	 * @return array{columnNames: array<int,string>, columnPositions: array<int,int>|null}
	 * @throws ManticoreSearchClientError
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
	 * @throws ManticoreSearchClientError
	 */
	private function determineColumnsFromList(): array {
		$columnList = $this->payload->replaceColumnList;

		if ($columnList === null) {
			throw ManticoreSearchClientError::create(
				'Internal error: missing column list'
			);
		}

		// Build map from column name to target field position
		$columnToPositionMap = [];
		foreach ($this->targetFieldsOrdered as $position => $fieldInfo) {
			if (!is_string($fieldInfo['name'])) {
				throw ManticoreSearchClientError::create(
					'Invalid target table structure'
				);
			}
			$columnToPositionMap[$fieldInfo['name']] = $position;
		}

		// Use only the specified columns in the order they were specified
		$columnPositions = array_map(
			fn($colName) => $columnToPositionMap[$colName],
			$columnList
		);

		return [
			'columnNames' => $columnList,
			'columnPositions' => $columnPositions,
		];
	}

	/**
	 * Determine columns from target fields (all fields)
	 *
	 * @return array{columnNames: array<int,string>, columnPositions: null}
	 * @throws ManticoreSearchClientError
	 */
	private function determineColumnsFromTargetFields(): array {
		$columnNames = [];
		foreach ($this->targetFieldsOrdered as $fieldInfo) {
			if (!is_string($fieldInfo['name'])) {
				throw ManticoreSearchClientError::create(
					'Invalid target table structure'
				);
			}
			$columnNames[] = $fieldInfo['name'];
		}

		return [
			'columnNames' => $columnNames,
			'columnPositions' => null,
		];
	}

	/**
	 * Extract ID field from processed row
	 *
	 * @param array<int,mixed> $processedRow
	 * @param array{columnNames: array<int,string>, columnPositions: array<int,int>|null} $columns
	 * @return int|string
	 * @throws ManticoreSearchClientError
	 */
	private function extractIdFromRow(array $processedRow, array $columns): int|string {
		// Find ID field position in the processed row
		// The processed row is indexed by select position (0, 1, 2, ...)
		$idPosition = array_search('id', $columns['columnNames'], true);

		if ($idPosition === false) {
			throw ManticoreSearchClientError::create('ID field is required for bulk operations');
		}

		$id = $processedRow[$idPosition] ?? null;
		if ($id === null) {
			throw ManticoreSearchClientError::create('ID field cannot be null in bulk operations');
		}

		if (!is_int($id) && !is_string($id)) {
			throw ManticoreSearchClientError::create('ID field must be int or string');
		}

		return $id;
	}

	/**
	 * Build document data from processed row (excluding ID field)
	 *
	 * @param array<int,mixed> $processedRow
	 * @param array{columnNames: array<int,string>, columnPositions: array<int,int>|null} $columns
	 * @return array<string,mixed>
	 */
	private function buildDocumentFromRow(array $processedRow, array $columns): array {
		$doc = [];

		if ($columns['columnPositions'] !== null) {
			// Column list case: use target positions from columnPositions array
			foreach ($columns['columnNames'] as $i => $columnName) {
				if ($columnName === 'id') {
					continue;
				}
				// Exclude ID field from document
				$targetPosition = $columns['columnPositions'][$i];
				$sqlValue = $processedRow[$targetPosition] ?? null;
				$doc[$columnName] = $this->convertSqlValueToJsonValue($sqlValue, $columnName, $columns);
			}
		} else {
			// No column list case: use sequential positions
			foreach ($columns['columnNames'] as $position => $columnName) {
				if ($columnName === 'id') {
					continue;
				}
				// Exclude ID field from document
				$sqlValue = $processedRow[$position] ?? null;
				$doc[$columnName] = $this->convertSqlValueToJsonValue($sqlValue, $columnName, $columns);
			}
		}

		return $doc;
	}

	/**
	 * Convert SQL-formatted value to JSON-compatible value
	 *
	 * @param mixed $sqlValue SQL-formatted value from morphValuesByFieldType()
	 * @param string $columnName Column name to determine field type
	 * @param array{columnNames: array<int,string>, columnPositions: array<int,int>|null} $columns Column metadata
	 * @return mixed JSON-compatible value
	 */
	private function convertSqlValueToJsonValue(mixed $sqlValue, string $columnName, array $columns): mixed {
		if ($sqlValue === null) {
			return null;
		}

		// Handle MVA arrays (already converted)
		if (is_array($sqlValue)) {
			return $sqlValue;
		}

		// Convert MVA SQL format to arrays
		if (is_string($sqlValue)) {
			if (str_starts_with($sqlValue, '(') && str_ends_with($sqlValue, ')')) {
				return $this->convertMvaSqlFormatToArray($sqlValue);
			}

			// Convert SQL-quoted strings to JSON strings
			if (str_starts_with($sqlValue, "'") && str_ends_with($sqlValue, "'")) {
				return $this->convertSqlStringToJsonString($sqlValue);
			}
		}

		// Convert based on field type
		$fieldType = $this->getFieldTypeForColumn($columnName, $columns);
		return $this->convertValueByFieldType($sqlValue, $fieldType);
	}

	/**
	 * Convert MVA SQL format to array
	 *
	 * @param string $sqlValue SQL-formatted MVA like '(1,2,3)'
	 * @return array<int,int> Array of integers
	 */
	private function convertMvaSqlFormatToArray(string $sqlValue): array {

		$mvaStr = trim($sqlValue, '()');
		if ($mvaStr !== '') {
			return array_map('intval', explode(',', $mvaStr));
		}

		return [];
	}

	/**
	 * Convert SQL-formatted string to JSON string
	 *
	 * @param mixed $sqlValue SQL-formatted string like "'value'" or "''"
	 * @return string JSON-compatible string
	 */
	private function convertSqlStringToJsonString(mixed $sqlValue): string {
		if (!is_string($sqlValue)) {
			if (is_scalar($sqlValue)) {
				return (string)$sqlValue;
			}
			return '';
		}

		// Remove SQL quotes and unescape
		$contentLength = strlen($sqlValue) - 2; // Remove 2 quote characters
		if ($contentLength > 0) {
			$content = substr($sqlValue, 1, $contentLength);
			$unescaped = stripslashes($content); // Unescape SQL escaping
			// Check if the unescaped content represents an empty string
			if ($unescaped === '' || $unescaped === "''" || $unescaped === "''''") {
				return ''; // Empty string
			}
			return $unescaped;
		}

		return ''; // Empty string for quoted empty strings like "''"
	}

	/**
	 * Get field type for a column name
	 *
	 * @param string $columnName Column name
	 * @param array{columnNames: array<int,string>, columnPositions: array<int,int>|null} $columns Column metadata
	 * @return string Field type or 'string' if not found
	 */
	private function getFieldTypeForColumn(string $columnName, array $columns): string {
		// Find the target position for this column
		if ($columns['columnPositions'] !== null) {
			$position = array_search($columnName, $columns['columnNames'], true);
			if ($position === false || !isset($columns['columnPositions'][$position])) {
				return 'string'; // Default fallback
			}

			$targetPosition = $columns['columnPositions'][$position];
		} else {
			$targetPosition = array_search($columnName, $columns['columnNames'], true);
			if ($targetPosition === false) {
				return 'string'; // Default fallback
			}
		}

		// Get field type from target fields
		$fieldInfo = $this->targetFieldsOrdered[$targetPosition] ?? null;
		return $fieldInfo['type'] ?? 'string';
	}

	/**
	 * Convert value based on field type
	 *
	 * @param mixed $sqlValue The value to convert
	 * @param string $fieldType Field type from schema
	 * @return mixed Converted value
	 */
	private function convertValueByFieldType(mixed $sqlValue, string $fieldType): mixed {
		return match ($fieldType) {
			'float' => is_numeric($sqlValue) ? (float)$sqlValue : 0.0,
			'int', 'bigint' => is_numeric($sqlValue) ? (int)$sqlValue : 0,
			'uint' => is_numeric($sqlValue) ? (int)$sqlValue : 0,
			'bool' => is_numeric($sqlValue) ? ($sqlValue !== 0)
				: (is_string($sqlValue) && strtolower($sqlValue) === 'true'),
			'timestamp' => is_numeric($sqlValue) ? (int)$sqlValue : 0,
			'json' => $this->convertSqlJsonToJsonValue($sqlValue),
			default => $sqlValue
		};
	}

	/**
	 * Convert SQL-formatted JSON string to JSON value
	 *
	 * @param mixed $sqlValue SQL-formatted JSON string like "'{"key":"value"}'"
	 * @return mixed Decoded JSON value or original string if decoding fails
	 */
	private function convertSqlJsonToJsonValue(mixed $sqlValue): mixed {
		if (!is_string($sqlValue)) {
			return $sqlValue;
		}

		// Remove SQL quotes first
		if (str_starts_with($sqlValue, "'") && str_ends_with($sqlValue, "'")) {
			$contentLength = strlen($sqlValue) - 2;
			if ($contentLength > 0) {
				$jsonStr = substr($sqlValue, 1, $contentLength);
				$decoded = json_decode(stripslashes($jsonStr), true);
				return $decoded !== null ? $decoded : stripslashes($jsonStr);
			}

			return null; // Empty JSON string
		}

		return $sqlValue;
	}

	/**
	 * Build request object for sendMultiRequest
	 *
	 * @param string $jsonBulk JSON bulk operations to send
	 * @return array{url: string, path: string, request: string}
	 */
	private function buildRequest(string $jsonBulk): array {
		return [
			// Use empty string if URL is not directly accessible
			'url' => '',
			'path' => 'bulk',
			'request' => $jsonBulk,
		];
	}

	/**
	 * Execute multiple chunks in parallel using sendMultiRequest
	 *
	 * @param array<int,array{url: string, path: string, request: string}> $requests List of chunk requests
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function executeReplaceChunks(array $requests): void {
		if (empty($requests)) {
			return;
		}

		$responses = $this->client->sendMultiRequest($requests);

		// Error handling for each response
		$failedChunks = [];

		foreach ($responses as $index => $response) {
			$current = $response->getResult()->toArray();

			// Check for explicit errors first
			if ($response->hasError()) {
				$failedChunks[] = [
					'index' => $index,
					'error' => $response->getError(),
					'request' => $requests[$index]['request'],
				];
				continue;
			}

			// Validate bulk response structure
			if (!isset($current['errors'])) {
				$failedChunks[] = [
					'index' => $index,
					'error' => 'Invalid bulk response structure: missing errors field',
					'request' => $requests[$index]['request'],
				];
				continue;
			}

			// Check for bulk operation errors
			if (!$current['errors']) {
				continue;
			}

			$failedChunks[] = [
				'index' => $index,
				'error' => 'Bulk operation failed',
				'request' => $requests[$index]['request'],
			];
		}

		// If any chunks failed, throw an error
		if (!empty($failedChunks)) {
			// Throw the first error as representative
			throw ManticoreSearchClientError::create(
				"Chunk insert failed: {$failedChunks[0]['error']}"
			);
		}

		$this->chunksProcessed += sizeof($requests);
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
	}

	/**
	 * Log processing statistics for debugging
	 *
	 * @return void
	 */
	private function logProcessingStatistics(): void {
		if (empty($this->statistics)) {
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
