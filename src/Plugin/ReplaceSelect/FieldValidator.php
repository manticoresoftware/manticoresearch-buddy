<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ReplaceSelect;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

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
		if ($this->hasGroupByClause($selectQuery)) {
			throw ManticoreSearchClientError::create(
				'GROUP BY is not supported in REPLACE SELECT'
			);
		}

		// 2. Validate source and target tables are different
		$this->validateSourceAndTargetAreDifferent($selectQuery, $targetTable);

		// 3. Load target table schema in DESC order
		$this->loadTargetFieldsOrdered($targetTable);
		$targetCount = sizeof($this->targetFieldsOrdered);

		// 4. Handle column list if provided
		if (!empty($replaceColumnList)) {
			$this->validateReplaceColumnList($replaceColumnList);
		}

		// 5. Check if SELECT uses * (wildcard)
		$isSelectStar = $this->isSelectStarQuery($selectQuery);

		if ($isSelectStar) {
			// SELECT * - use name-based matching
			$this->validateSelectStarQuery($selectQuery, $replaceColumnList);
		} else {
			// Explicit field list - use position-based matching
			$this->validateExplicitSelectQuery($selectQuery, $targetCount, $replaceColumnList);
		}
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

		// Validate source and target are different
		if ($sourceTableNormalized !== $targetTableNormalized) {
			return;
		}

		throw ManticoreSearchClientError::create(
			'Source and target tables must be different'
		);
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
	 * Strip cluster prefix from table name
	 * Converts "cluster:table" to "table", or returns "table" unchanged
	 *
	 * @param string $tableName
	 * @return string Table name without cluster prefix
	 */
	private function stripClusterPrefix(string $tableName): string {
		if (preg_match('/^\w+:(\w+)$/', $tableName, $matches)) {
			return $matches[1];
		}
		return $tableName;
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
				continue;
			}

			$fieldName = (string)$field['Field'];
			$fieldType = (string)$field['Type'];
			$this->targetFieldsOrdered[$position] = [
				'name' => $fieldName,
				'type' => $fieldType,
				'properties' => (string)$field['Properties'],
			];
			$position++;
		}
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
	 *
	 * @return void
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function validateSelectStarQuery(
		string $selectQuery,
		?array $replaceColumnList = null
	): void {
		// Extract source table name from query
		$sourceTable = $this->extractSourceTableName($selectQuery);

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
	 *
	 * @return array<string,array<string,mixed>> Source fields keyed by name
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function loadSourceTableFields(string $sourceTable): array {
		$sourceFields = [];
		$descResult = $this->client->sendRequest("DESC $sourceTable");

		if ($descResult->hasError()) {
			throw ManticoreSearchClientError::create(
				"Source table '$sourceTable' does not exist"
			);
		}

		$result = $descResult->getResult()->toArray();
		if (empty($result) || !is_array($result[0])) {
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
	 * Validate field types by name matching
	 *
	 * @param array<int,FieldInfo> $fieldsToValidate
	 * @param array<string,array<string,mixed>> $sourceFields
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateFieldTypesByName(
		array $fieldsToValidate,
		array $sourceFields
	): void {
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

			// Type mismatch check (both must have same type for SELECT *)
			if (!$this->areTypesEquivalent($sourceType, $targetFieldType)) {
				$displaySourceType = $this->normalizeTypeForDisplay($sourceType);
				$displayTargetType = $this->normalizeTypeForDisplay($targetFieldType);
				throw ManticoreSearchClientError::create(
					"Column type mismatch: '$targetFieldName' is ".
					"$displaySourceType but target expects $displayTargetType"
				);
			}
		}
	}

	/**
	 * Check if two types are equivalent for validation purposes
	 *
	 * Supports the following equivalences:
	 * - 'int' and 'uint' (same logical type, different contexts)
	 * - 'string' and 'text' (text field variants)
	 * - 'int'/'uint' to 'bigint' (safe widening conversions)
	 *
	 * This improved version uses a mapping for better scalability and readability,
	 * handles case insensitivity by normalizing to lowercase, and trims whitespace
	 * for robustness.
	 *
	 * @param string $sourceType Source field type
	 * @param string $targetType Target field type
	 * @return bool True if types are compatible
	 */
	private function areTypesEquivalent(string $sourceType, string $targetType): bool {
		// Normalize inputs: trim and convert to lowercase for case-insensitivity
		$sourceType = strtolower(trim($sourceType));
		$targetType = strtolower(trim($targetType));

		// Define equivalence groups (source => allowed targets)
		$equivalences = [
			'int' => ['int', 'uint', 'bigint'],
			'uint' => ['int', 'uint', 'bigint'],
			'string' => ['string', 'text'],
			'text' => ['string', 'text'],
			'bigint' => ['bigint'], // No widening from bigint assumed
		];

		// If source type not in map, only direct match allowed
		$allowedTargets = $equivalences[$sourceType] ?? [$sourceType];

		return in_array($targetType, $allowedTargets, true);
	}

	/**
	 * Normalize Manticore internal type names to user-friendly display names
	 *
	 * Manticore internally uses 'uint' for integer types, but users expect
	 * familiar SQL type names like 'int' in error messages.
	 *
	 * @param string $type Manticore internal type name
	 * @return string User-friendly type name for display
	 */
	private function normalizeTypeForDisplay(string $type): string {
		return match ($type) {
			'uint' => 'int',
			default => $type
		};
	}

	/**
	 * Verify SELECT query is valid and returns data
	 *
	 * @param string $selectQuery
	 *
	 * @return void
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function verifySelectQueryReturnsData(string $selectQuery): void {
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
		if (empty($sampleData)) {
			throw ManticoreSearchClientError::create(
				'Source table has no data. Add test data or use a table with existing records.'
			);
		}
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
	 * Validate explicit SELECT field list with position-based matching
	 *
	 * @param string $selectQuery
	 * @param int $targetCount
	 * @param array<int,string>|null $replaceColumnList Optional column list from REPLACE INTO
	 *
	 * @return void
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function validateExplicitSelectQuery(
		string $selectQuery,
		int $targetCount,
		?array $replaceColumnList = null
	): void {
		// Extract field names from SELECT clause
		$selectFieldNames = $this->extractSelectFieldNames($selectQuery);
		$selectCount = sizeof($selectFieldNames);

		// Validate field count matches
		if ($replaceColumnList !== null) {
			// When column list is specified, SELECT field count must match column list count
			$expectedCount = sizeof($replaceColumnList);
			if ($selectCount !== $expectedCount) {
				throw ManticoreSearchClientError::create(
					"Column count mismatch: SELECT returns $selectCount fields but column list has $expectedCount"
				);
			}
		} else {
			// When no column list, SELECT field count must match target table field count
			$this->validateFieldCountMatches($selectCount, $targetCount);
		}

		// Validate 'id' field is at position 0 in target (only if not using column list)
		if ($replaceColumnList === null) {
			$this->validateIdFieldAtPosition();
		}

		// Execute SELECT LIMIT 1 to verify the query is valid and get sample data
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
		if (empty($sampleData)) {
			throw ManticoreSearchClientError::create(
				'SELECT query returns no data. Use a query that returns at least one row.'
			);
		}
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

		return $field;
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
	 * Get target fields information (ordered by position from DESC)
	 *
	 * @return array<int,FieldInfo>
	 */
	public function getTargetFields(): array {
		return $this->targetFieldsOrdered;
	}
}
