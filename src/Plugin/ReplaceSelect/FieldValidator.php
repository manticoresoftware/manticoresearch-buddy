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
 */
final class FieldValidator {
	private Client $client;
	/** @var array<string,array<string,mixed>> */
	private array $targetFields = [];

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
	 * @param string $selectQuery
	 * @param string $targetTable
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function validateCompatibility(string $selectQuery, string $targetTable): void {
		// 1. Load target table schema first
		Buddy::debug("Loading schema for target table: $targetTable");
		$this->loadTargetFields($targetTable);
		Buddy::debug('Target table schema loaded. Fields: ' . implode(', ', array_keys($this->targetFields)));

		// 2. Test SELECT query and get sample data
		Buddy::debug("Testing SELECT query: $selectQuery");

		// Strip existing LIMIT clause to avoid syntax errors
		$baseQuery = preg_replace('/\s+LIMIT\s+\d+\s*(?:OFFSET\s+\d+)?\s*$/i', '', $selectQuery);
		if ($baseQuery !== $selectQuery) {
			Buddy::debug('Original query had LIMIT clause, removing it');
			Buddy::debug("Base query without LIMIT: $baseQuery");
		}

		// Append LIMIT 1 to get sample data
		$testQuery = "$baseQuery LIMIT 1";
		Buddy::debug("Test query with LIMIT 1: $testQuery");

		$result = $this->client->sendRequest($testQuery);

		if ($result->hasError()) {
			$errorMsg = 'Invalid SELECT query: ' . $result->getError();
			Buddy::debug("SELECT query validation failed: $errorMsg");
			throw ManticoreSearchClientError::create($errorMsg);
		}

		$rawResult = $result->getResult()->toArray();

		$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
		Buddy::debug('SELECT result full structure: ' . json_encode($rawResult, $jsonFlags));

		Buddy::debug('SELECT query validation successful');

		$resultData = $this->validateAndExtractResultData($rawResult);
		if ($resultData === null) {
			// No data returned - validate structure using query metadata
			$this->validateEmptyResult($selectQuery);
			return;
		}

		[$selectFields, $sampleData] = $resultData;

		// 3. Validate mandatory ID field
		Buddy::debug('Validating mandatory ID field');
		$this->validateMandatoryId($selectFields);
		Buddy::debug('Mandatory ID field validation passed');

		// 4. Validate field existence and type compatibility
		Buddy::debug('Validating field existence');
		$this->validateFieldExistence($selectFields);
		Buddy::debug('Field existence validation passed');

		Buddy::debug('Validating field type compatibility');
		$this->validateFieldTypes($selectFields, $sampleData);
		Buddy::debug('Field type validation passed');

		// 5. Validate stored properties for text fields
		Buddy::debug('Validating stored properties for text fields');
		$this->validateStoredFields($selectFields);
		Buddy::debug('Stored properties validation passed');

		$this->logValidationResults($selectFields, $sampleData);
	}


	/**
	 * Validate when SELECT returns no data
	 *
	 * @param string $selectQuery
	 *
	 * @return void
	 * @throws ManticoreSearchClientError| ManticoreSearchResponseError
	 */
	private function validateEmptyResult(string $selectQuery): void {
		// When SELECT returns no data, we need alternative validation
		// Option 1: Try to extract field list from SELECT clause using regex
		$extractedFields = $this->extractFieldsFromSelectClause($selectQuery);

		if (!empty($extractedFields)) {
			Buddy::debug('Extracted fields from SELECT clause: ' . implode(', ', $extractedFields));
			$this->validateMandatoryId($extractedFields);
			$this->validateFieldExistence($extractedFields);
			$this->validateStoredFields($extractedFields);
			return;
		}

		Buddy::debug('Could not extract fields from SELECT clause, trying alternative methods');

		// Option 2: Use DESCRIBE or EXPLAIN if available
		$describeQuery = "DESCRIBE ($selectQuery)";
		Buddy::debug("Attempting DESCRIBE query: $describeQuery");
		$descResult = $this->client->sendRequest($describeQuery);

		if (!$descResult->hasError()) {
			Buddy::debug('DESCRIBE query succeeded, validating from results');
			// Parse DESCRIBE result to get field information
			$this->validateFromDescribeResult($descResult->getResult()->toArray());
			return;
		}

		Buddy::debug('DESCRIBE failed, trying LIMIT 0 approach');

		// Option 3: Execute query with LIMIT 0 to get structure without data
		// Strip existing LIMIT clause to avoid syntax errors
		$baseQuery = preg_replace('/\s+LIMIT\s+\d+\s*(?:OFFSET\s+\d+)?\s*$/i', '', $selectQuery);
		$structureQuery = "$baseQuery LIMIT 0";
		Buddy::debug("Testing query structure: $structureQuery");
		$structResult = $this->client->sendRequest($structureQuery);

		if ($structResult->hasError()) {
			throw ManticoreSearchClientError::create(
				'Cannot validate SELECT query structure: ' . $structResult->getError()
			);
		}

		// Even with LIMIT 0, we should get column information
		$resultMeta = $structResult->getResult()->toArray();
		if (!is_array($resultMeta[0]) || !isset($resultMeta[0]['columns'])) {
			throw ManticoreSearchClientError::create(
				'Cannot determine SELECT query field structure - no sample data available'
			);
		}

		/** @var array<int, string> $fields */
		$fields = array_keys($resultMeta[0]['columns']);
		$this->validateMandatoryId($fields);
		$this->validateFieldExistence($fields);
		$this->validateStoredFields($fields);
	}

	/**
	 * Extract field names from SELECT clause
	 *
	 * @param string $selectQuery
	 * @return array<int,string>
	 */
	private function extractFieldsFromSelectClause(string $selectQuery): array {
		// Extract field list from "SELECT field1, field2, field3 FROM ..."
		$pattern = '/SELECT\s+(.*?)\s+FROM/i';
		if (!preg_match($pattern, $selectQuery, $matches)) {
			return [];
		}

		$fieldList = trim($matches[1]);

		// Handle SELECT * case
		if ($fieldList === '*') {
			return []; // Cannot determine fields from *
		}

		// Split fields and clean them
		$fields = array_map('trim', explode(',', $fieldList));
		$cleanFields = [];

		foreach ($fields as $field) {
			// Ensure field is a string
			if (!is_string($field)) {
				Buddy::debug('Skipping non-string field: ' . json_encode($field));
				continue;
			}

			// Remove aliases: "field AS alias" -> "field"
			if (preg_match('/(.+?)\s+AS\s+/i', $field, $matches)) {
				$field = trim($matches[1]);
			}

			// Remove table prefixes: "table.field" -> "field"
			if (str_contains($field, '.')) {
				$parts = explode('.', $field);
				$field = trim(end($parts));
			}

			// Remove quotes and backticks
			$field = trim($field, '`"\' ');

			if (empty($field) || in_array(strtolower($field), ['count', 'sum', 'avg', 'min', 'max'])) {
				continue;
			}

			$cleanFields[] = $field;
		}

		return $cleanFields;
	}

	/**
	 * Validate from DESCRIBE result
	 *
	 * @param mixed $result
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateFromDescribeResult(mixed $result): void {
		// This would need implementation based on DESCRIBE output format
		// For now, throw an error as fallback
		unset($result); // Suppress unused parameter warning
		throw ManticoreSearchClientError::create(
			'DESCRIBE-based validation not yet implemented'
		);
	}

	/**
	 * Validate field types for compatibility
	 *
	 * @param array<int,string> $selectFields
	 * @param array<string,mixed> $sampleData
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateFieldTypes(array $selectFields, array $sampleData): void {
		foreach ($selectFields as $fieldName) {
			if (!isset($this->targetFields[$fieldName]) || !isset($sampleData[$fieldName])) {
				Buddy::debug("Skipping type check for field '$fieldName' (not in sample data)");
				continue;
			}

			$sourceValue = $sampleData[$fieldName];
			/** @var array{type: string, properties: string} $fieldInfo */
			$fieldInfo = $this->targetFields[$fieldName];
			$targetType = $fieldInfo['type'];

			Buddy::debug("Type check for '$fieldName': " . gettype($sourceValue) . " -> $targetType");

			// Check type compatibility
			if (!$this->isTypeCompatible($sourceValue, $targetType)) {
				$errorMsg = "Field '$fieldName' type incompatible: cannot convert " .
					gettype($sourceValue) . " to $targetType";
				Buddy::debug("Type validation failed: $errorMsg");
				throw ManticoreSearchClientError::create($errorMsg);
			}
		}
	}

	/**
	 * Validate and extract result data from client response
	 *
	 * @param mixed $result
	 * @return array{0: array<int,string>, 1: array<string,mixed>}|null
	 */
	private function validateAndExtractResultData(mixed $result): ?array {
		Buddy::debug('=== validateAndExtractResultData called ===');
		Buddy::debug('Input $result type: ' . gettype($result));

		if (!is_array($result) || !isset($result[0]) || !is_array($result[0])) {
			Buddy::debug('CHECK FAILED: $result is not valid array structure');
			return null;
		}

		// Try wrapped format first (standard Manticore response)
		$wrappedResult = $this->extractFromWrappedFormat($result[0]);
		if ($wrappedResult !== null) {
			return $wrappedResult;
		}

		// Fallback to unwrapped format
		$unwrappedResult = $this->extractFromUnwrappedFormat($result[0]);
		if ($unwrappedResult !== null) {
			return $unwrappedResult;
		}

		Buddy::debug('No data found in response - no wrapped or unwrapped data');
		Buddy::debug('result[0] keys: ' . json_encode(array_keys($result[0])));
		return null;
	}

	/**
	 * Extract result from wrapped format (has 'data' key)
	 *
	 * @param array<mixed> $resultWrapper
	 * @return array{0: array<int,string>, 1: array<string,mixed>}|null
	 */
	private function extractFromWrappedFormat(array $resultWrapper): ?array {
		if (!isset($resultWrapper['data'])
			|| !is_array($resultWrapper['data'])
			|| empty($resultWrapper['data'])) {
			return null;
		}

		Buddy::debug('Using wrapped format with "data" key');

		$firstRow = $resultWrapper['data'][0];
		if (!is_array($firstRow)) {
			$errorMsg = 'Invalid SELECT result: data row is not array, got ' . gettype($firstRow);
			Buddy::debug("CHECK FAILED: $errorMsg");
			throw ManticoreSearchClientError::create($errorMsg);
		}

		$selectFields = $this->extractFieldNamesFromResult($firstRow);
		$this->validateFieldNamesAreStrings($selectFields);

		Buddy::debug('Extracted ' . sizeof($selectFields) . ' fields from wrapped format');
		return [$selectFields, $firstRow];
	}

	/**
	 * Extract result from unwrapped format (result[0] is a data row)
	 *
	 * @param array<mixed> $firstRow
	 * @return array{0: array<int,string>, 1: array<string,mixed>}|null
	 */
	private function extractFromUnwrappedFormat(array $firstRow): ?array {
		// Detect unwrapped format: no wrapper indicator keys
		if (isset($firstRow['error']) || isset($firstRow['data']) || isset($firstRow['columns'])) {
			return null;
		}

		if (empty($firstRow)) {
			return null;
		}

		Buddy::debug('Using unwrapped format - result[0] is a direct data row');

		$selectFields = $this->extractFieldNamesFromResult($firstRow);
		$this->validateFieldNamesAreStrings($selectFields);

		Buddy::debug('Extracted ' . sizeof($selectFields) . ' fields from unwrapped format');
		return [$selectFields, $firstRow];
	}

	/**
	 * Extract field names from first row data keys
	 *
	 * @param array<mixed> $firstRow
	 * @return array<int,mixed>
	 */
	private function extractFieldNamesFromResult(array $firstRow): array {
		Buddy::debug('Extracting field names from first row data keys');
		$selectFields = array_keys($firstRow);

		Buddy::debug('Extracted field names: ' . json_encode($selectFields, JSON_PRETTY_PRINT));
		return $selectFields;
	}

	/**
	 * Validate that all field names are strings
	 *
	 * @param array<int,mixed> $selectFields
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateFieldNamesAreStrings(array $selectFields): void {
		foreach ($selectFields as $idx => $field) {
			if (!is_string($field)) {
				$type = gettype($field);
				$encoded = json_encode($field);
				$errorMsg = "Field name at index $idx is not a string: $encoded (type: $type)";
				Buddy::debug("Field extraction error: $errorMsg");
				throw ManticoreSearchClientError::create($errorMsg);
			}
			Buddy::debug("Field[$idx]: type=" . gettype($field) . ', value=' . json_encode($field));
		}
	}

	/**
	 * Check if source value is compatible with target type
	 *
	 * @param mixed $sourceValue
	 * @param string $targetType
	 * @return bool
	 */
	private function isTypeCompatible(mixed $sourceValue, string $targetType): bool {
		$sourceType = gettype($sourceValue);

		return match ($targetType) {
			'int', 'bigint' => in_array($sourceType, ['integer', 'string']) && is_numeric($sourceValue),
			'float' => in_array($sourceType, ['double', 'integer', 'string']) && is_numeric($sourceValue),
			'bool' => in_array($sourceType, ['boolean', 'integer', 'string']),
			'text', 'string', 'json' => true, // Most types can be converted to text
			'mva', 'mva64' => $sourceType === 'array' || (is_string($sourceValue) && str_contains($sourceValue, ',')),
			'float_vector' => $sourceType === 'array' || (is_string($sourceValue) && str_contains($sourceValue, ',')),
			'timestamp' => in_array($sourceType, ['integer', 'string'])
				&& ($sourceType === 'integer' || (is_string($sourceValue) && strtotime($sourceValue) !== false)),
			default => true
		};
	}

	/**
	 * Log validation results for debugging
	 *
	 * @param array<int,string> $fields
	 * @param array<string,mixed> $sampleData
	 * @return void
	 */

	private function logValidationResults(array $fields, array $sampleData): void {
		/** @var array<string,array{type: string, properties: string}> $targetFields */
		$targetFields = $this->targetFields;
		$logData = [
			'fields_validated' => $fields,
			'sample_types' => array_map('gettype', $sampleData),
			'target_types' => array_intersect_key(
				array_column($targetFields, 'type'),
				array_flip(
					array_map(
						fn($fieldName) => $fieldName, $fields
					)
				)
			),
		];

		Buddy::debug('ReplaceSelect validation results: ' . json_encode($logData));
	}

	/**
	 * Validate mandatory ID field presence
	 *
	 * @param array<int,string> $fields
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateMandatoryId(array $fields): void {
		Buddy::debug('Fields in SELECT: ' . implode(', ', $fields));

		// Ensure all fields are strings
		$stringFields = [];
		foreach ($fields as $field) {
			if (!is_string($field)) {
				$type = gettype($field);
				$encoded = json_encode($field);
				Buddy::debug("Warning: Field is not a string: $encoded (type: $type)");
				continue;
			}
			$stringFields[] = $field;
		}

		if (empty($stringFields)) {
			$errorMsg = 'No valid string field names found';
			Buddy::debug("ID field validation failed: $errorMsg");
			throw ManticoreSearchClientError::create($errorMsg);
		}

		$lowerFields = array_map(fn($fieldName) => strtolower($fieldName), $stringFields);
		if (!in_array('id', $lowerFields, true)) {
			$errorMsg = "SELECT query must include 'id' field. Found: " . implode(', ', $stringFields);
			Buddy::debug("ID field validation failed: $errorMsg");
			throw ManticoreSearchClientError::create($errorMsg);
		}
	}

	/**
	 * Validate that all selected fields exist in target table
	 *
	 * @param array<int,string> $selectFields
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateFieldExistence(array $selectFields): void {
		foreach ($selectFields as $fieldName) {
			if (!isset($this->targetFields[$fieldName])) {
				$errorMsg = "Field '$fieldName' does not exist in target table. Available: "
					. implode(', ', array_keys($this->targetFields));
				Buddy::debug("Field existence validation failed: $errorMsg");
				throw ManticoreSearchClientError::create($errorMsg);
			}
			Buddy::debug("Field exists in target: $fieldName");
		}
	}

	/**
	 * Validate stored properties for text fields
	 *
	 * @param array<int,string> $selectFields
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateStoredFields(array $selectFields): void {
		foreach ($selectFields as $fieldName) {
			/** @var array{type?: string, properties?: string} $fieldInfo */
			$fieldInfo = $this->targetFields[$fieldName];
			if (!isset($fieldInfo['type'], $fieldInfo['properties'])) {
				Buddy::debug("Skipping stored check for field '$fieldName' (no type/properties info)");
				continue;
			}

			if ($fieldInfo['type'] !== 'text') {
				continue;
			}

			$props = $fieldInfo['properties'];
			Buddy::debug("Checking stored property for TEXT field: $fieldName (properties: $props)");
			if (!str_contains($fieldInfo['properties'], 'stored')) {
				$errorMsg = "Text field '$fieldName' must have 'stored' property for REPLACE operations";
				Buddy::debug("Stored property validation failed: $errorMsg");
				throw ManticoreSearchClientError::create($errorMsg);
			}
		}
	}

	/**
	 * Load target table field information
	 *
	 * @param string $tableName
	 *
	 * @return void
	 * @throws ManticoreSearchClientError| ManticoreSearchResponseError
	 */
	private function loadTargetFields(string $tableName): void {
		Buddy::debug("Executing DESC $tableName");
		$descResult = $this->client->sendRequest("DESC $tableName");

		if ($descResult->hasError()) {
			$errorMsg = "Cannot describe target table '$tableName': " . $descResult->getError();
			Buddy::debug("DESC command failed: $errorMsg");
			throw ManticoreSearchClientError::create($errorMsg);
		}

		$this->targetFields = [];
		$result = $descResult->getResult()->toArray();

		Buddy::debug('DESC result full structure: ' . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		if (!is_array($result[0]) || !isset($result[0]['data'])) {
			Buddy::debug('DESC result has no data');
			return;
		}

		foreach ($result[0]['data'] as $field) {
			if (!is_array($field) || !isset($field['Field'], $field['Type'], $field['Properties'])) {
				Buddy::debug('Skipping field with invalid structure: ' . json_encode($field));
				continue;
			}

			$fieldName = (string)$field['Field'];
			$fieldType = (string)$field['Type'];
			$this->targetFields[$fieldName] = [
				'type' => $fieldType,
				'properties' => (string)$field['Properties'],
			];
			Buddy::debug("Loaded field: $fieldName ($fieldType)");
		}

		Buddy::debug('Total fields loaded: ' . sizeof($this->targetFields));
	}

	/**
	 * Get target fields information
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function getTargetFields(): array {
		return $this->targetFields;
	}
}
