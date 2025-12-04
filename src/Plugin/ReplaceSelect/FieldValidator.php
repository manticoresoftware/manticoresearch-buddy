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
 * Uses position-based field mapping:
 * - Validates SELECT field count matches target table field count
 * - Maps SELECT result fields to target by position, not by name
 * - Allows functions, expressions, and complex SELECT queries
 * - Requires 'id' field at position 0 in target table
 * - Rejects GROUP BY queries explicitly
 */
final class FieldValidator {
	private Client $client;
	/** @var array<int,array<string,mixed>> Ordered by position from DESC */
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
	 * Position-based validation:
	 * 1. Rejects GROUP BY queries
	 * 2. Loads target fields in DESC order
	 * 3. Executes SELECT LIMIT 1 to get sample data
	 * 4. Validates field count matches
	 * 5. Validates 'id' is at position 0 in target
	 * 6. Validates type compatibility by position
	 *
	 * @param string $selectQuery
	 * @param string $targetTable
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function validateCompatibility(string $selectQuery, string $targetTable): void {
		// 1. Check GROUP BY clause - not supported
		Buddy::debug('Checking for GROUP BY clause');
		if ($this->hasGroupByClause($selectQuery)) {
			throw ManticoreSearchClientError::create(
				'GROUP BY clause is not supported in REPLACE SELECT operations'
			);
		}

		// 2. Load target table schema in DESC order
		Buddy::debug("Loading schema for target table: $targetTable");
		$this->loadTargetFieldsOrdered($targetTable);
		$targetCount = count($this->targetFieldsOrdered);
		Buddy::debug("Target table schema loaded. Fields count: $targetCount");

		// 3. Test SELECT query and get sample data
		Buddy::debug("Testing SELECT query: $selectQuery");

		// Strip existing LIMIT clause to avoid syntax errors
		$baseQuery = preg_replace('/\s+LIMIT\s+\d+\s*(?:OFFSET\s+\d+)?\s*$/i', '', $selectQuery);
		if ($baseQuery !== $selectQuery) {
			Buddy::debug('Original query had LIMIT clause, removing it');
		}

		// Append LIMIT 1 to get sample data
		$testQuery = "$baseQuery LIMIT 1";
		Buddy::debug("Test query with LIMIT 1: $testQuery");

		$result = $this->client->sendRequest($testQuery);

		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Invalid SELECT query: ' . $result->getError()
			);
		}

		$rawResult = $result->getResult()->toArray();
		Buddy::debug('SELECT result obtained');

		// 4. Extract sample data
		$sampleData = $this->extractSampleData($rawResult);
		if ($sampleData === null) {
			throw ManticoreSearchClientError::create(
				'Cannot determine SELECT query field structure - no sample data available'
			);
		}

		// 5. Validate field count matches
		$selectCount = count($sampleData);
		Buddy::debug("SELECT returns $selectCount fields, target has $targetCount");
		$this->validateFieldCountMatches($selectCount, $targetCount);

		// 6. Validate 'id' field is at position 0 in target
		$this->validateIdFieldAtPosition();

		// 7. Validate type compatibility by position
		Buddy::debug('Validating type compatibility by position');
		$this->validateTypeCompatibilityByPosition($sampleData);
		Buddy::debug('Type compatibility validation passed');

		$this->logValidationResults($sampleData);
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

		// Try wrapped format (has 'data' key)
		if (isset($firstElement['data']) && is_array($firstElement['data'])
			&& !empty($firstElement['data']) && is_array($firstElement['data'][0])) {
			return array_values($firstElement['data'][0]);
		}

		// Try unwrapped format (first element is direct data row)
		if (!isset($firstElement['error'], $firstElement['data'], $firstElement['columns'])) {
			return array_values($firstElement);
		}

		return null;
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
				"SELECT returns $selectCount fields but target expects $targetCount. "
				. 'Ensure SELECT column count matches target table structure.'
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
			throw ManticoreSearchClientError::create('Target table has no fields');
		}

		$firstField = $this->targetFieldsOrdered[0];
		if ($firstField['name'] !== 'id') {
			throw ManticoreSearchClientError::create(
				"Target table must have 'id' as first field. "
				. "Found: {$firstField['name']}"
			);
		}
	}

	/**
	 * Validate type compatibility by position
	 *
	 * @param array<int,mixed> $sampleData
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function validateTypeCompatibilityByPosition(array $sampleData): void {
		foreach ($sampleData as $index => $value) {
			if (!isset($this->targetFieldsOrdered[$index])) {
				throw ManticoreSearchClientError::create(
					"Field at position $index not found in target table"
				);
			}

			$targetType = $this->targetFieldsOrdered[$index]['type'];
			if (!$this->isTypeCompatible($value, $targetType)) {
				$fieldName = $this->targetFieldsOrdered[$index]['name'];
				throw ManticoreSearchClientError::create(
					"Field '$fieldName' at position $index: "
					. "cannot convert " . gettype($value) . " to $targetType"
				);
			}
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
	 * @param array<int,mixed> $sampleData
	 * @return void
	 */
	private function logValidationResults(array $sampleData): void {
		$logData = [
			'fields_count' => count($sampleData),
			'sample_types' => array_map('gettype', $sampleData),
			'target_field_names' => array_map(
				fn($f) => $f['name'],
				$this->targetFieldsOrdered
			),
		];

		Buddy::debug('ReplaceSelect validation results: ' . json_encode($logData));
	}

	/**
	 * Load target table field information in DESC order
	 *
	 * @param string $tableName
	 *
	 * @return void
	 * @throws ManticoreSearchClientError| ManticoreSearchResponseError
	 */
	private function loadTargetFieldsOrdered(string $tableName): void {
		Buddy::debug("Executing DESC $tableName");
		$descResult = $this->client->sendRequest("DESC $tableName");

		if ($descResult->hasError()) {
			throw ManticoreSearchClientError::create(
				"Cannot describe target table '$tableName': " . $descResult->getError()
			);
		}

		$this->targetFieldsOrdered = [];
		$result = $descResult->getResult()->toArray();

		if (!is_array($result[0]) || !isset($result[0]['data'])) {
			throw ManticoreSearchClientError::create("DESC result has no data for table '$tableName'");
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
	 * @return array<int,array<string,mixed>>
	 */
	public function getTargetFields(): array {
		return $this->targetFieldsOrdered;
	}
}
