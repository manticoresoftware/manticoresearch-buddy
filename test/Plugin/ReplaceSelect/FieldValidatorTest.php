<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\FieldValidator;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use Manticoresearch\BuddyTest\Trait\ReplaceSelectTestTrait;
use PHPUnit\Framework\TestCase;

class FieldValidatorTest extends TestCase {

	use TestProtectedTrait;
	use ReplaceSelectTestTrait;

	/**
	 * Create a mock response for table schema (DESC command)
	 */
	private function createTableSchemaResponse(array $fields = null): Response {
		$defaultFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
			['Field' => 'is_active', 'Type' => 'bool', 'Properties' => ''],
			['Field' => 'tags', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'mva_tags', 'Type' => 'multi', 'Properties' => ''],
		];

		return $this->createMockResponse(true, $fields ?? $defaultFields);
	}

	private function createSelectResponse(array $rows): Response {
		return $this->createMockResponse(true, $rows);
	}

	private function createErrorResponse(string $errorMessage): Response {
		return $this->createMockResponse(false, null, $errorMessage);
	}





	// ========================================================================
	// Successful Validation Tests
	// ========================================================================

	public function testValidateCompatibilitySuccess(): void {
		echo "\nTesting successful schema compatibility validation\n";

		$mockClient = $this->createMockClient();

		// Set up call expectations and responses
		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->withConsecutive(
				['DESC target_table'],
				['SELECT id, title, price FROM source_table LIMIT 1']
			)
			->willReturnOnConsecutiveCalls(
				$this->createTableSchemaResponse(),
				$this->createSelectResponse(
					[
					['id' => 1, 'title' => 'Product A', 'price' => 99.99],
					]
				)
			);

		$validator = new FieldValidator($mockClient);

		// This should not throw any exception
		$validator->validateCompatibility(
			'SELECT id, title, price FROM source_table',
			'target_table'
		);

		// Verify target fields were loaded correctly
		$targetFields = $validator->getTargetFields();
		$this->assertArrayHasKey('id', $targetFields);
		$this->assertArrayHasKey('title', $targetFields);
		$this->assertEquals('text', $targetFields['title']['type']);
		$this->assertEquals('stored', $targetFields['title']['properties']);
	}

	public function testValidateCompatibilityWithAllFieldTypes(): void {
		echo "\nTesting schema validation with all supported field types\n";

		$mockClient = $this->createMockClient();

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse();
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[
								[
									'id' => 1,
									'title' => 'Product A',
									'price' => 99.99,
									'is_active' => 1,
									'tags' => 'tag1,tag2,tag3',
									'mva_tags' => [10, 20, 30],
								],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, title, price, is_active, tags, mva_tags FROM source_table',
			'target_table'
		);

		$this->assertTrue(true); // Test passes if no exception thrown
	}

	// ========================================================================
	// Mandatory ID Field Tests
	// ========================================================================

	public function testMissingIdFieldThrowsError(): void {
		echo "\nTesting validation failure when ID field is missing\n";

		$mockClient = $this->createMockClient();

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse();
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[
								['title' => 'Product A', 'price' => 99.99], // No ID field
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT title, price FROM source_table',
			'target_table'
		);
	}

	public function testIdFieldValidation(): void {
		echo "\nTesting ID field validation\n";

		$mockClient = $this->createMockClient();

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse();
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[
								['id' => 1, 'title' => 'Product A'],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		// Should not throw exception - testing that id field validation works
		$validator->validateCompatibility(
			'SELECT id, title FROM source_table',
			'target_table'
		);

		$this->assertTrue(true);
	}

	// ========================================================================
	// Field Existence Tests
	// ========================================================================

	public function testNonexistentFieldThrowsError(): void {
		echo "\nTesting validation failure when field doesn't exist in target\n";

		$mockClient = $this->createMockClient();

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse();
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[
								['id' => 1, 'nonexistent_field' => 'some value'],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT id, nonexistent_field FROM source_table',
			'target_table'
		);
	}

	// ========================================================================
	// Text Field STORED Property Tests
	// ========================================================================

	public function testTextFieldWithoutStoredPropertyThrowsError(): void {
		echo "\nTesting validation failure for text field without stored property\n";

		$mockClient = $this->createMockClient();

		// Create schema with text field that lacks 'stored' property
		$fieldsWithoutStored = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => ''], // Missing 'stored'
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($fieldsWithoutStored) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($fieldsWithoutStored);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[
								['id' => 1, 'title' => 'Product A'],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT id, title FROM source_table',
			'target_table'
		);
	}

	public function testTextFieldWithStoredPropertySuccess(): void {
		echo "\nTesting successful validation for text field with stored property\n";

		$mockClient = $this->createMockClient();

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse(); // Has stored
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[
								['id' => 1, 'title' => 'Product A'],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, title FROM source_table',
			'target_table'
		);

		$this->assertTrue(true);
	}

	// ========================================================================
	// Type Compatibility Tests
	// ========================================================================

	public function testIsTypeCompatibleIntegerTypes(): void {
		echo "\nTesting type compatibility for integer types\n";

		$validator = new FieldValidator($this->createMockClient());

		// Use reflection to test protected method
		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [42, 'int']);
		$this->assertTrue($isCompatible);

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [42, 'bigint']);
		$this->assertTrue($isCompatible);

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', ['42', 'int']);
		$this->assertTrue($isCompatible); // String numeric should work

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', ['not_a_number', 'int']);
		$this->assertFalse($isCompatible);
	}

	public function testIsTypeCompatibleFloatTypes(): void {
		echo "\nTesting type compatibility for float types\n";

		$validator = new FieldValidator($this->createMockClient());

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [99.99, 'float']);
		$this->assertTrue($isCompatible);

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [42, 'float']);
		$this->assertTrue($isCompatible); // Integer to float should work

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', ['99.99', 'float']);
		$this->assertTrue($isCompatible); // String numeric should work

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', ['not_a_number', 'float']);
		$this->assertFalse($isCompatible);
	}

	public function testIsTypeCompatibleTextTypes(): void {
		echo "\nTesting type compatibility for text types\n";

		$validator = new FieldValidator($this->createMockClient());

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', ['hello', 'text']);
		$this->assertTrue($isCompatible);

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [123, 'text']);
		$this->assertTrue($isCompatible); // Most types can be converted to text

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [true, 'string']);
		$this->assertTrue($isCompatible);
	}

	public function testIsTypeCompatibleBoolTypes(): void {
		echo "\nTesting type compatibility for boolean types\n";

		$validator = new FieldValidator($this->createMockClient());

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [true, 'bool']);
		$this->assertTrue($isCompatible);

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [1, 'bool']);
		$this->assertTrue($isCompatible);

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', ['true', 'bool']);
		$this->assertTrue($isCompatible);
	}

	public function testIsTypeCompatibleMvaTypes(): void {
		echo "\nTesting type compatibility for multi-value array types\n";

		$validator = new FieldValidator($this->createMockClient());

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [[1, 2, 3], 'mva']);
		$this->assertTrue($isCompatible);

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', ['1,2,3', 'mva']);
		$this->assertTrue($isCompatible); // Comma-separated string should work

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', [[1, 2, 3], 'mva64']);
		$this->assertTrue($isCompatible);

		$isCompatible = self::invokeMethod($validator, 'isTypeCompatible', ['no_commas', 'mva']);
		$this->assertFalse($isCompatible);
	}

	// ========================================================================
	// Empty Result Handling Tests
	// ========================================================================

	public function testValidateEmptyResultWithExtractedFields(): void {
		echo "\nTesting validation with empty result using field extraction\n";

		$mockClient = $this->createMockClient();

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse();
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse([]); // Empty result
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		// Should not throw exception - can extract fields from SELECT clause
		$validator->validateCompatibility(
			'SELECT id, title, price FROM source_table WHERE 1=0',
			'target_table'
		);

		$this->assertTrue(true);
	}

	public function testValidateEmptyResultCannotExtractFields(): void {
		echo "\nTesting validation with empty result and complex SELECT\n";

		$mockClient = $this->createMockClient();

		$mockClient->expects($this->exactly(4))
			->method('sendRequest')
			->withConsecutive(
				['DESC target_table'],
				['SELECT * FROM complex_join LIMIT 1'],
				['DESCRIBE (SELECT * FROM complex_join)'], // Fallback to DESCRIBE
				['SELECT * FROM complex_join LIMIT 0'] // Final fallback
			)
			->willReturnOnConsecutiveCalls(
				$this->createTableSchemaResponse(),
				$this->createSelectResponse([]), // Empty result
				$this->createErrorResponse('DESCRIBE not supported'), // DESCRIBE fails
				$this->createErrorResponse('Cannot determine structure') // LIMIT 0 fails
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT * FROM complex_join',
			'target_table'
		);
	}

	// ========================================================================
	// Field Extraction Tests
	// ========================================================================

	public function testExtractFieldsFromSelectClauseBasic(): void {
		echo "\nTesting field extraction from basic SELECT clause\n";

		$validator = new FieldValidator($this->createMockClient());

		$fields = self::invokeMethod(
			$validator,
			'extractFieldsFromSelectClause',
			['SELECT id, title, price FROM source_table']
		);

		$this->assertEquals(['id', 'title', 'price'], $fields);
	}

	public function testExtractFieldsFromSelectClauseWithAliases(): void {
		echo "\nTesting field extraction with aliases\n";

		$validator = new FieldValidator($this->createMockClient());

		$fields = self::invokeMethod(
			$validator,
			'extractFieldsFromSelectClause',
			['SELECT id, title AS product_title, price FROM source_table']
		);

		$this->assertEquals(['id', 'title', 'price'], $fields);
	}

	public function testExtractFieldsFromSelectClauseWithTablePrefix(): void {
		echo "\nTesting field extraction with table prefixes\n";

		$validator = new FieldValidator($this->createMockClient());

		$fields = self::invokeMethod(
			$validator,
			'extractFieldsFromSelectClause',
			['SELECT s.id, s.title, s.price FROM source_table s']
		);

		$this->assertEquals(['id', 'title', 'price'], $fields);
	}

	public function testExtractFieldsFromSelectClauseStar(): void {
		echo "\nTesting field extraction with SELECT *\n";

		$validator = new FieldValidator($this->createMockClient());

		$fields = self::invokeMethod(
			$validator,
			'extractFieldsFromSelectClause',
			['SELECT * FROM source_table']
		);

		$this->assertEquals([], $fields); // Cannot determine fields from *
	}

	public function testExtractFieldsFromSelectClauseWithFunctions(): void {
		echo "\nTesting field extraction filters out functions\n";

		$validator = new FieldValidator($this->createMockClient());

		$fields = self::invokeMethod(
			$validator,
			'extractFieldsFromSelectClause',
			['SELECT id, title, COUNT(*), MAX(price) FROM source_table']
		);

		$this->assertEquals(['id', 'title'], $fields); // Functions filtered out
	}

	// ========================================================================
	// Error Scenarios
	// ========================================================================

	public function testInvalidSelectQueryThrowsError(): void {
		echo "\nTesting validation failure with invalid SELECT query\n";

		$mockClient = $this->createMockClient();

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse();
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createErrorResponse('SQL syntax error');
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'INVALID SQL SYNTAX',
			'target_table'
		);
	}

	public function testTargetTableNotFoundThrowsError(): void {
		echo "\nTesting validation failure when target table doesn't exist\n";

		$mockClient = $this->createMockClient();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESC nonexistent_table')
			->willReturn($this->createErrorResponse('Table does not exist'));

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT id, title FROM source_table',
			'nonexistent_table'
		);
	}

	// ========================================================================
	// Integration Tests
	// ========================================================================

	public function testCompleteValidationWorkflow(): void {
		echo "\nTesting complete validation workflow with type checking\n";

		$mockClient = $this->createMockClient();

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse();
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[
								[
									'id' => 1,
									'title' => 'Product A',
									'price' => 99.99,
									'is_active' => true,
								],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, title, price, is_active FROM source_table',
			'target_table'
		);

		// Verify all target fields were correctly loaded
		$targetFields = $validator->getTargetFields();
		$this->assertCount(6, $targetFields); // Should have all 6 fields from schema
		$this->assertEquals('bigint', $targetFields['id']['type']);
		$this->assertEquals('text', $targetFields['title']['type']);
		$this->assertEquals('stored', $targetFields['title']['properties']);
	}
}
