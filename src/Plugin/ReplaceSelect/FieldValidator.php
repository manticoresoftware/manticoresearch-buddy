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
		$this->loadTargetFields($targetTable);

		// 2. Test SELECT query and get sample data
		$testQuery = "($selectQuery) LIMIT 1";
		$result = $this->client->sendRequest($testQuery);
		$rawResult = $result->getResult();
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Invalid SELECT query: ' . $result->getError()
			);
		}

		$resultData = $this->validateAndExtractResultData($rawResult);
		if ($resultData === null) {
			// No data returned - validate structure using query metadata
			$this->validateEmptyResult($selectQuery);
			return;
		}

		[$selectFields, $sampleData] = $resultData;

		// 3. Validate mandatory ID field
		$this->validateMandatoryId($selectFields);

		// 4. Validate field existence and type compatibility
		$this->validateFieldExistence($selectFields);
		$this->validateFieldTypes($selectFields, $sampleData);

		// 5. Validate stored properties for text fields
		$this->validateStoredFields($selectFields);

		if (!Config::isDebugEnabled()) {
			return;
		}

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
			$this->validateMandatoryId($extractedFields);
			$this->validateFieldExistence($extractedFields);
			$this->validateStoredFields($extractedFields);
			return;
		}

		// Option 2: Use DESCRIBE or EXPLAIN if available
		$describeQuery = "DESCRIBE ($selectQuery)";
		$descResult = $this->client->sendRequest($describeQuery);

		if (!$descResult->hasError()) {
			// Parse DESCRIBE result to get field information
			$this->validateFromDescribeResult($descResult->getResult());
			return;
		}

		// Option 3: Execute query with LIMIT 0 to get structure without data
		$structureQuery = "($selectQuery) LIMIT 0";
		$structResult = $this->client->sendRequest($structureQuery);

		if ($structResult->hasError()) {
			throw ManticoreSearchClientError::create(
				'Cannot validate SELECT query structure: ' . $structResult->getError()
			);
		}

		// Even with LIMIT 0, we should get column information
		$resultMeta = $structResult->getResult();
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
				continue;
			}

			$sourceValue = $sampleData[$fieldName];
			/** @var array{type: string, properties: string} $fieldInfo */
			$fieldInfo = $this->targetFields[$fieldName];
			$targetType = $fieldInfo['type'];

			// Check type compatibility
			if (!$this->isTypeCompatible($sourceValue, $targetType)) {
				throw ManticoreSearchClientError::create(
					"Field '$fieldName' type incompatible: cannot convert " .
					gettype($sourceValue) . " to $targetType"
				);
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
		if (!is_array($result) || !isset($result[0]['data']) || empty($result[0]['data'])) {
			return null;
		}

		$firstRow = $result[0]['data'][0];
		if (!is_array($firstRow)) {
			throw ManticoreSearchClientError::create('Invalid SELECT query result structure');
		}

		/** @var array<int,string> $selectFields */
		$selectFields = array_keys($firstRow);
		return [$selectFields, $firstRow];
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

		error_log('ReplaceSelect validation: ' . json_encode($logData));
	}

	/**
	 * Validate mandatory ID field presence
	 *
	 * @param array<int,string> $fields
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateMandatoryId(array $fields): void {
		$lowerFields = array_map(fn($fieldName) => strtolower($fieldName), $fields);
		if (!in_array('id', $lowerFields, true)) {
			throw ManticoreSearchClientError::create(
				"SELECT query must include 'id' field"
			);
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
				throw ManticoreSearchClientError::create(
					"Field '$fieldName' does not exist in target table"
				);
			}
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
				continue;
			}

			if ($fieldInfo['type'] === 'text'
				&& !str_contains($fieldInfo['properties'], 'stored')) {
				throw ManticoreSearchClientError::create(
					"Text field '$fieldName' must have 'stored' property for REPLACE operations"
				);
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
		$descResult = $this->client->sendRequest("DESC $tableName");

		if ($descResult->hasError()) {
			throw ManticoreSearchClientError::create(
				"Cannot describe target table '$tableName': " . $descResult->getError()
			);
		}

		$this->targetFields = [];
		$result = $descResult->getResult();
		if (!is_array($result[0]) || !isset($result[0]['data'])) {
			return;
		}

		foreach ($result[0]['data'] as $field) {
			if (!is_array($field) || !isset($field['Field'], $field['Type'], $field['Properties'])) {
				continue;
			}

			$this->targetFields[(string)$field['Field']] = [
				'type' => (string)$field['Type'],
				'properties' => (string)$field['Properties'],
			];
		}
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
