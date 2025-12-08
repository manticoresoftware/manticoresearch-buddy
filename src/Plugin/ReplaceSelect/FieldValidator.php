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
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Field compatibility validator for REPLACE SELECT operations
 *
 * @phpstan-type FieldInfo array{name: string, type: string, properties: string}
 *
 * Validation process:
 * 1. Check field count matches between SELECT and target
 * 2. DESC both source and target tables to get field types
 * 3. Extract field names from SELECT clause (handle aliases with AS)
 * 4. Match field names to source DESC to find their types
 * 5. Check for type mismatches with target
 * 6. Handle functions: if field is function anychar(.*), use position-based type from target
 * 7. On type mismatch in functions, try casting and let insert handle errors
 */
final class FieldValidator {
	private Client $client;
	/** @var array<int,FieldInfo> Ordered by position from DESC */
	private array $targetFieldsOrdered = [];

	/**
	 * Constructor
	 *
	 * @param Client $client
	 */
	public function __construct(Client $client) {
		$this->client = $client;
	}

	/**
	 * Validate schema compatibility between source and target
	 *
	 * Supports two syntaxes:
	 * 1. REPLACE INTO target SELECT ... (all target fields required)
	 * 2. REPLACE INTO target (col1, col2) SELECT ... (only specified columns)
	 *
	 * @param string $selectQuery
	 * @param string $targetTable
	 * @param array<int,string>|null $replaceColumnList Column list from REPLACE INTO (col1, col2)
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function validateCompatibility(
		string $selectQuery,
		string $targetTable,
		?array $replaceColumnList = null
	): void {
		// 1. Check GROUP BY clause - not supported
		Buddy::debug('Checking for GROUP BY clause');
		if ($this->hasGroupByClause($selectQuery)) {
			throw ManticoreSearchClientError::create(
				'GROUP BY is not supported in REPLACE SELECT'
			);
		}

		// 2. Validate source and target tables are different
		Buddy::debug('Validating source and target tables are different');
		$this->validateSourceAndTargetAreDifferent($selectQuery, $targetTable);
		Buddy::debug('Validation passed: source and target tables are different');

		// 3. Load target table schema in DESC order
		Buddy::debug("Loading schema for target table: $targetTable");
		$this->loadTargetFieldsOrdered($targetTable);
		$targetCount = sizeof($this->targetFieldsOrdered);
		Buddy::debug("Target table schema loaded. Fields count: $targetCount");

		// 4. Handle column list if provided
		if ($replaceColumnList !== null && !empty($replaceColumnList)) {
			Buddy::debug('Column list specified: ' . implode(', ', $replaceColumnList));
			$this->validateReplaceColumnList($replaceColumnList);
			// When column list is provided, we only need to match those columns
			// The validation will happen per column
		}

		// 5. Check if SELECT uses * (wildcard)
		$isSelectStar = $this->isSelectStarQuery($selectQuery);

		if ($isSelectStar) {
			// SELECT * - use name-based matching
			Buddy::debug('Detected SELECT * - using name-based field matching');
			$this->validateSelectStarQuery($selectQuery, $replaceColumnList);
		} else {
			// Explicit field list - use position-based matching
			Buddy::debug('Detected explicit field list - using position-based field matching');
			$this->validateExplicitSelectQuery($selectQuery, $targetCount, $replaceColumnList);
		}

		$this->logValidationResults([]);
	}

	/**
	 * Validate that source and target tables are different
	 *
	 * @param string $selectQuery
	 * @param string $targetTable
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateSourceAndTargetAreDifferent(
		string $selectQuery,
		string $targetTable
	): void {
		// Extract source table from SELECT query
		$sourceTable = $this->extractSourceTableName($selectQuery);

		// Normalize both tables: strip cluster prefix if present
		// Format: [cluster:]table
		$sourceTableNormalized = $this->stripClusterPrefix($sourceTable);
		$targetTableNormalized = $this->stripClusterPrefix($targetTable);

		Buddy::debug(
			"Source table normalized: $sourceTableNormalized, "
			. "Target table normalized: $targetTableNormalized"
		);

		// Validate source and target are different
		if ($sourceTableNormalized !== $targetTableNormalized) {
			return;
		}

		throw ManticoreSearchClientError::create(
			'Source and target tables must be different'
		);
	}

	/**
	 * Strip cluster prefix from table name
	 * Converts "cluster:table" to "table", or returns "table" unchanged
	 *
	 * @param string $tableName
	 * @return string Table name without cluster prefix
	 */
	private function stripClusterPrefix(string $tableName): string {
		if (preg_match('/^[\w]+:([\w]+)$/', $tableName, $matches)) {
			return $matches[1];
		}
		return $tableName;
	}

	/**
	 * Extract source table name from SELECT query
	 *
	 * @param string $selectQuery
	 * @return string Source table name
	 * @throws ManticoreSearchClientError
	 */
	private function extractSourceTableName(string $selectQuery): string {
		if (!preg_match('/FROM\s+(\S+)/i', $selectQuery, $matches)) {
			throw ManticoreSearchClientError::create(
				'Invalid SELECT query: missing FROM clause'
			);
		}
		return $matches[1];
	}

	/**
	 * Validate that column list contains valid target table column names
	 *
	 * @param array<int,string> $replaceColumnList
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateReplaceColumnList(array $replaceColumnList): void {
		// Build map of target field names
		$targetFieldNames = array_map(fn($f) => $f['name'], $this->targetFieldsOrdered);
		$targetFieldNameSet = array_flip($targetFieldNames);

		foreach ($replaceColumnList as $columnName) {
			if (!isset($targetFieldNameSet[$columnName])) {
				throw ManticoreSearchClientError::create(
					"Column '$columnName' does not exist in target table"
				);
			}
		}

		Buddy::debug('Column list validation passed');
	}

	/**
	 * Check if SELECT query uses * (SELECT *)
	 *
	 * @param string $selectQuery
	 * @return bool
	 */
	private function isSelectStarQuery(string $selectQuery): bool {
		return (bool)preg_match('/SELECT\s+\*\s+FROM/i', $selectQuery);
	}

	/**
	 * Validate SELECT * query with name-based field matching
	 * Expands * to all source fields and matches by name to target
	 *
	 * @param string $selectQuery
	 * @param array<int,string>|null $replaceColumnList Optional column list from REPLACE INTO
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateSelectStarQuery(
		string $selectQuery,
		?array $replaceColumnList = null
	): void {
		// Extract source table name from query
		$sourceTable = $this->extractSourceTableName($selectQuery);
		Buddy::debug("Source table detected: $sourceTable");

		// Load source table fields
		$sourceFields = $this->loadSourceTableFields($sourceTable);

		// Validate field counts match
		$sourceCount = sizeof($sourceFields);
		$this->validateSourceFieldCountMatches($sourceCount, $replaceColumnList);

		// Determine which fields to validate
		$fieldsToValidate = $this->determineFieldsToValidate($replaceColumnList);

		// Validate 'id' field is at position 0 in target (only if not using column list)
		if ($replaceColumnList === null) {
			$this->validateIdFieldAtPosition();
		}

		// Match source fields to target by NAME
		$this->validateFieldTypesByName($fieldsToValidate, $sourceFields);

		// Execute SELECT * LIMIT 1 to verify the query is valid and returns data
		$this->verifySelectQueryReturnsData($selectQuery);
	}

	/**
	 * Load source table field information
	 *
	 * @param string $sourceTable
	 * @return array<string,array<string,mixed>> Source fields keyed by name
	 * @throws ManticoreSearchClientError
	 */
	private function loadSourceTableFields(string $sourceTable): array {
		Buddy::debug("Loading schema for source table: $sourceTable");
		$sourceFields = [];
		$descResult = $this->client->sendRequest("DESC $sourceTable");

		if ($descResult->hasError()) {
			throw ManticoreSearchClientError::create(
				"Source table '$sourceTable' does not exist"
			);
		}

		$result = $descResult->getResult()->toArray();
		if (!is_array($result) || empty($result) || !is_array($result[0])) {
			throw ManticoreSearchClientError::create(
				"Failed to read source table '$sourceTable' structure"
			);
		}

		$descData = $result[0]['data'] ?? null;
		if (!is_array($descData) || empty($descData)) {
			throw ManticoreSearchClientError::create(
				"Source table '$sourceTable' has no columns"
			);
		}

		// Build source fields map (name -> type)
		foreach ($descData as $field) {
			if (!is_array($field) || !isset($field['Field'], $field['Type'])) {
				continue;
			}
			$sourceFields[(string)$field['Field']] = [
				'type' => (string)$field['Type'],
				'properties' => (string)($field['Properties'] ?? ''),
			];
		}

		return $sourceFields;
	}

	/**
	 * Validate source field count matches expected count
	 *
	 * @param int $sourceCount
	 * @param array<int,string>|null $replaceColumnList
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateSourceFieldCountMatches(
		int $sourceCount,
		?array $replaceColumnList
	): void {
		$targetCount = sizeof($this->targetFieldsOrdered);
		Buddy::debug("Source table has $sourceCount fields, target has $targetCount fields");

		if ($replaceColumnList !== null) {
			return;
		}

		if ($sourceCount === $targetCount) {
			return;
		}

		throw ManticoreSearchClientError::create(
			"Column count mismatch: SELECT returns $sourceCount columns but target table has $targetCount"
		);
	}

	/**
	 * Determine which target fields to validate based on column list
	 *
	 * @param array<int,string>|null $replaceColumnList
	 * @return array<int,FieldInfo> Fields to validate
	 */
	private function determineFieldsToValidate(?array $replaceColumnList): array {
		if ($replaceColumnList === null) {
			// Validate all target fields
			return $this->targetFieldsOrdered;
		}

		// When column list is specified, validate only those columns
		Buddy::debug('Validating only specified columns: ' . implode(', ', $replaceColumnList));
		$fieldsToValidate = [];
		foreach ($replaceColumnList as $colName) {
			// Find the target field with this name
			foreach ($this->targetFieldsOrdered as $position => $field) {
				if ($field['name'] === $colName) {
					$fieldsToValidate[$position] = $field;
					break;
				}
			}
		}

		return $fieldsToValidate;
	}

	/**
	 * Validate field types by name matching
	 *
	 * @param array<int,FieldInfo> $fieldsToValidate
	 * @param array<string,array<string,mixed>> $sourceFields
	 * @param string $sourceTable
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateFieldTypesByName(
		array $fieldsToValidate,
		array $sourceFields
	): void {
		Buddy::debug('Matching source fields to target by name');
		foreach ($fieldsToValidate as $targetField) {
			if (!is_string($targetField['name'])) {
				throw ManticoreSearchClientError::create(
					'Invalid target table structure'
				);
			}

			if (!is_string($targetField['type'])) {
				throw ManticoreSearchClientError::create(
					'Invalid target table structure'
				);
			}

			$targetFieldName = $targetField['name'];
			$targetFieldType = $targetField['type'];

			if (!isset($sourceFields[$targetFieldName])) {
				throw ManticoreSearchClientError::create(
					"Source table missing column '$targetFieldName'"
				);
			}

			$sourceType = $sourceFields[$targetFieldName]['type'];

			if (!is_string($sourceType)) {
				throw ManticoreSearchClientError::create(
					'Invalid source table structure'
				);
			}

			Buddy::debug(
				"Field '$targetFieldName': source type=$sourceType, target type=$targetFieldType"
			);

			// Type mismatch check (both must have same type for SELECT *)
			if ($sourceType !== $targetFieldType) {
				throw ManticoreSearchClientError::create(
					"Column type mismatch: '$targetFieldName' is $sourceType but target expects $targetFieldType"
				);
			}
		}
	}

	/**
	 * Verify SELECT query is valid and returns data
	 *
	 * @param string $selectQuery
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function verifySelectQueryReturnsData(string $selectQuery): void {
		Buddy::debug('Verifying SELECT * query is valid');
		$baseQuery = preg_replace('/\s+LIMIT\s+\d+\s*(?:OFFSET\s+\d+)?\s*$/i', '', $selectQuery);
		$testQuery = "$baseQuery LIMIT 1";
		$result = $this->client->sendRequest($testQuery);

		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Invalid SELECT query: ' . $result->getError()
			);
		}

		// Verify SELECT returns some data
		$sampleData = $this->extractSampleData($result->getResult()->toArray());
		if ($sampleData === null || empty($sampleData)) {
			throw ManticoreSearchClientError::create(
				'Source table has no data. Add test data or use a table with existing records.'
			);
		}

		Buddy::debug('SELECT * validation passed');
	}

	/**
	 * Validate explicit SELECT field list with position-based matching
	 *
	 * @param string $selectQuery
	 * @param int $targetCount
	 * @param array<int,string>|null $replaceColumnList Optional column list from REPLACE INTO
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateExplicitSelectQuery(
		string $selectQuery,
		int $targetCount,
		?array $replaceColumnList = null
	): void {
		// Extract field names from SELECT clause
		$selectFieldNames = $this->extractSelectFieldNames($selectQuery);
		$selectCount = sizeof($selectFieldNames);
		Buddy::debug("SELECT returns $selectCount fields: " . implode(', ', $selectFieldNames));

		// Validate field count matches
		if ($replaceColumnList !== null) {
			// When column list is specified, SELECT field count must match column list count
			$expectedCount = sizeof($replaceColumnList);
			if ($selectCount !== $expectedCount) {
				throw ManticoreSearchClientError::create(
					"Column count mismatch: SELECT returns $selectCount fields but column list has $expectedCount"
				);
			}
			Buddy::debug("Field count matches column list specification: $expectedCount");
		} else {
			// When no column list, SELECT field count must match target table field count
			$this->validateFieldCountMatches($selectCount, $targetCount);
		}

		// Validate 'id' field is at position 0 in target (only if not using column list)
		if ($replaceColumnList === null) {
			$this->validateIdFieldAtPosition();
		}

		// Execute SELECT LIMIT 1 to verify the query is valid and get sample data
		Buddy::debug('Verifying SELECT query is valid');
		$baseQuery = preg_replace('/\s+LIMIT\s+\d+\s*(?:OFFSET\s+\d+)?\s*$/i', '', $selectQuery);
		$testQuery = "$baseQuery LIMIT 1";
		$result = $this->client->sendRequest($testQuery);

		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Invalid SELECT query: ' . $result->getError()
			);
		}

		// Verify SELECT returns some data for type validation
		$sampleData = $this->extractSampleData($result->getResult()->toArray());
		if ($sampleData === null || empty($sampleData)) {
			throw ManticoreSearchClientError::create(
				'SELECT query returns no data. Use a query that returns at least one row.'
			);
		}
		Buddy::debug('SELECT query validation passed with sample data');

		// Validate field type compatibility (position-based with function handling)
		Buddy::debug('Validating field type compatibility');
		$this->validateSelectFieldTypes($selectFieldNames);
		Buddy::debug('Field type validation passed');
	}

	/**
	 * Extract sample data from SELECT result
	 *
	 * @param array<mixed> $result
	 * @return array<int,mixed>|null
	 */
	private function extractSampleData(array $result): ?array {
		if (empty($result) || !isset($result[0])) {
			return null;
		}

		$firstElement = $result[0];
		if (!is_array($firstElement)) {
			return null;
		}

		// Try wrapped format (has 'data' key with rows)
		if (isset($firstElement['data']) && is_array($firstElement['data'])) {
			// If data is empty, return null (no sample data)
			if (empty($firstElement['data'])) {
				return null;
			}
			// Data has rows, return first row
			if (is_array($firstElement['data'][0])) {
				return array_values($firstElement['data'][0]);
			}
		}

		// Try unwrapped format (first element is direct data row)
		// Check that it doesn't look like a response structure
		if (!isset($firstElement['error']) && !isset($firstElement['data']) && !isset($firstElement['columns'])) {
			return array_values($firstElement);
		}

		return null;
	}

	/**
	 * Extract field names from SELECT clause
	 * Handles aliases with AS keyword
	 *
	 * @param string $selectQuery
	 * @return array<int,string> Field names in order
	 * @throws ManticoreSearchClientError
	 */
	private function extractSelectFieldNames(string $selectQuery): array {
		// Extract the SELECT ... FROM part
		if (!preg_match('/SELECT\s+(.*?)\s+FROM\s+/is', $selectQuery, $matches)) {
			throw ManticoreSearchClientError::create(
				'Invalid SELECT query: cannot parse field list'
			);
		}

		$fieldList = $matches[1];

		// Split by comma, respecting parentheses
		$fields = $this->splitSelectFieldsByComma($fieldList);

		// Resolve actual field names from expressions
		$fieldNames = [];
		foreach ($fields as $field) {
			$fieldNames[] = $this->resolveFieldName($field);
		}

		if (empty($fieldNames)) {
			throw ManticoreSearchClientError::create('SELECT clause has no fields');
		}

		return $fieldNames;
	}

	/**
	 * Split SELECT field list by comma, respecting parentheses
	 *
	 * @param string $fieldList
	 * @return array<int,string> Raw field expressions
	 */
	private function splitSelectFieldsByComma(string $fieldList): array {
		$fields = [];
		$depth = 0;
		$current = '';

		for ($i = 0; $i < strlen($fieldList); $i++) {
			$char = $fieldList[$i];

			if ($char === '(') {
				$depth++;
			} elseif ($char === ')') {
				$depth--;
			} elseif ($char === ',' && $depth === 0) {
				$fields[] = trim($current);
				$current = '';
				continue;
			}

			$current .= $char;
		}

		if ($current !== '') {
			$fields[] = trim($current);
		}

		return $fields;
	}

	/**
	 * Resolve field name from expression, handling aliases and functions
	 *
	 * @param string $field Raw field expression
	 * @return string Resolved field name
	 */
	private function resolveFieldName(string $field): string {
		// Handle "field AS alias" - we use the alias as the field name
		if (preg_match('/\s+AS\s+(\w+)$/i', $field, $matches)) {
			return $matches[1];
		}

		// For simple field names
		if (preg_match('/^\w+$/', $field)) {
			return $field;
		}

		// For functions or expressions - return the whole expression
		// This will be handled specially during type checking
		return $field;
	}

	/**
	 * Validate SELECT field types against target types
	 * Handles both regular fields and functions
	 *
	 * @param array<int,string> $selectFieldNames
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateSelectFieldTypes(array $selectFieldNames): void {
		foreach ($selectFieldNames as $index => $fieldName) {
			$targetField = $this->targetFieldsOrdered[$index] ?? null;
			if ($targetField === null) {
				throw ManticoreSearchClientError::create(
					'Target table has insufficient columns for SELECT query'
				);
			}

			if (!is_string($targetField['type'])) {
				throw ManticoreSearchClientError::create(
					'Invalid target table structure'
				);
			}
			$targetType = $targetField['type'];

			// Check if this is a function call (e.g., UPPER(name), COUNT(*), YEAR(date))
			if (preg_match('/^\w+\s*\(.*\)$/i', $fieldName)) {
				// It's a function - we'll use target type for casting at insert time
				Buddy::debug(
					"Field '$fieldName' at position $index is a function. "
					. "Will cast to target type: $targetType"
				);
			} else {
				// It's a regular field reference
				Buddy::debug(
					"Field '$fieldName' at position $index "
					. "will be cast to target type: $targetType"
				);
			}
		}
	}

	/**
	 * Check if source value is compatible with target type
	 * Used by tests and type validation
	 *
	 * @param mixed $sourceValue
	 * @param string $targetType
	 * @return bool
	 */
	public function isTypeCompatible(mixed $sourceValue, string $targetType): bool {
		$sourceType = gettype($sourceValue);

		return match ($targetType) {
			'int', 'bigint' => in_array($sourceType, ['integer', 'string']) && is_numeric($sourceValue),
			'float' => in_array($sourceType, ['double', 'integer', 'string']) && is_numeric($sourceValue),
			'bool' => in_array($sourceType, ['boolean', 'integer', 'string']),
			'text', 'string', 'json' => true,
			'mva', 'mva64' => $sourceType === 'array' || (is_string($sourceValue) && str_contains($sourceValue, ',')),
			'float_vector' => $sourceType === 'array' || (is_string($sourceValue) && str_contains($sourceValue, ',')),
			'timestamp' => in_array($sourceType, ['integer', 'string'])
				&& ($sourceType === 'integer' || (is_string($sourceValue) && strtotime($sourceValue) !== false)),
			default => true
		};
	}

	/**
	 * Check if SELECT query contains GROUP BY clause
	 *
	 * @param string $selectQuery
	 * @return bool
	 */
	private function hasGroupByClause(string $selectQuery): bool {
		return (bool)preg_match('/\s+GROUP\s+BY\s+/i', $selectQuery);
	}

	/**
	 * Validate SELECT field count matches target field count
	 *
	 * @param int $selectCount
	 * @param int $targetCount
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateFieldCountMatches(int $selectCount, int $targetCount): void {
		if ($selectCount !== $targetCount) {
			throw ManticoreSearchClientError::create(
				"Column count mismatch: SELECT returns $selectCount fields but target expects $targetCount"
			);
		}
	}

	/**
	 * Validate 'id' field exists at position 0 in target table
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateIdFieldAtPosition(): void {
		if (empty($this->targetFieldsOrdered)) {
			throw ManticoreSearchClientError::create('Target table has no columns');
		}

		$firstField = $this->targetFieldsOrdered[0];
		if (!is_string($firstField['name'])) {
			throw ManticoreSearchClientError::create(
				'Invalid target table structure'
			);
		}

		if ($firstField['name'] !== 'id') {
			throw ManticoreSearchClientError::create(
				"Target table must have 'id' as first column"
			);
		}
	}

	/**
	 * Log validation results for debugging
	 *
	 * @param array<int,string> $selectFieldNames
	 * @return void
	 */
	private function logValidationResults(array $selectFieldNames): void {
		$logData = [
			'select_fields' => $selectFieldNames,
			'target_fields' => array_map(
				fn($f) => $f['name'] . '(' . $f['type'] . ')',
				$this->targetFieldsOrdered
			),
		];

		Buddy::debug('ReplaceSelect validation results: ' . json_encode($logData));
	}

	/**
	 * Load target table field information in DESC order
	 *
	 * Loads and structures field information into FieldInfo array format
	 * for type-safe access throughout the validator.
	 *
	 * @param string $tableName
	 *
	 * @return void
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function loadTargetFieldsOrdered(string $tableName): void {
		Buddy::debug("Executing DESC $tableName");
		$descResult = $this->client->sendRequest("DESC $tableName");

		if ($descResult->hasError()) {
			throw ManticoreSearchClientError::create(
				"Target table '$tableName' does not exist"
			);
		}

		$this->targetFieldsOrdered = [];
		$result = $descResult->getResult()->toArray();

		if (!is_array($result[0]) || !isset($result[0]['data'])) {
			throw ManticoreSearchClientError::create("Failed to read target table '$tableName' structure");
		}

		$position = 0;
		foreach ($result[0]['data'] as $field) {
			if (!is_array($field) || !isset($field['Field'], $field['Type'], $field['Properties'])) {
				Buddy::debug('Skipping field with invalid structure: ' . json_encode($field));
				continue;
			}

			$fieldName = (string)$field['Field'];
			$fieldType = (string)$field['Type'];
			$this->targetFieldsOrdered[$position] = [
				'name' => $fieldName,
				'type' => $fieldType,
				'properties' => (string)$field['Properties'],
			];
			Buddy::debug("Loaded field at position $position: $fieldName ($fieldType)");
			$position++;
		}

		Buddy::debug('Total fields loaded: ' . sizeof($this->targetFieldsOrdered));
	}

	/**
	 * Get target fields information (ordered by position from DESC)
	 *
	 * @return array<int,FieldInfo>
	 */
	public function getTargetFields(): array {
		return $this->targetFieldsOrdered;
	}
}
