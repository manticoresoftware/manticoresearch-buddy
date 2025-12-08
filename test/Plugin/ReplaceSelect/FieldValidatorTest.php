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

		// Schema with only 3 fields to match SELECT query
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		// Set up call expectations and responses
		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->withConsecutive(
				['DESC target_table'],
				['SELECT id, title, price FROM source_table LIMIT 1']
			)
			->willReturnOnConsecutiveCalls(
				$this->createTableSchemaResponse($schemaFields),
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

		// Verify target fields were loaded correctly (indexed by position)
		$targetFields = $validator->getTargetFields();
		$this->assertCount(3, $targetFields); // 3 target fields selected
		$this->assertEquals('id', $targetFields[0]['name']);
		$this->assertEquals('bigint', $targetFields[0]['type']);
		$this->assertEquals('title', $targetFields[1]['name']);
		$this->assertEquals('text', $targetFields[1]['type']);
		$this->assertEquals('stored', $targetFields[1]['properties']);
		$this->assertEquals('price', $targetFields[2]['name']);
		$this->assertEquals('float', $targetFields[2]['type']);
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

		// Schema with only 2 fields to match SELECT query: id, title
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
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
		echo "\nTesting validation failure when field count doesn't match\n";

		$mockClient = $this->createMockClient();

		// Schema with 2 fields: id, title
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						// SELECT returns 3 fields but schema only has 2
						return $this->createSelectResponse(
							[
								['id' => 1, 'title' => 'Product', 'price' => 99.99],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		// SELECT returns 3 fields but target schema only has 2
		$validator->validateCompatibility(
			'SELECT id, title, price FROM source_table',
			'target_table'
		);
	}

	// ========================================================================
	// Text Field STORED Property Tests
	// ========================================================================

	public function testTextFieldTypeCompatibility(): void {
		echo "\nTesting text field type compatibility validation\n";

		$mockClient = $this->createMockClient();

		// Create schema with text field (stored property is now optional in position-based validation)
		$fieldsSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($fieldsSchema) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($fieldsSchema);
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

		// Should pass - text fields accept string values
		$validator->validateCompatibility(
			'SELECT id, title FROM source_table',
			'target_table'
		);

		$this->assertTrue(true);
	}

	public function testTextFieldWithStoredPropertySuccess(): void {
		echo "\nTesting successful validation for text field with stored property\n";

		$mockClient = $this->createMockClient();

		// Schema with only 2 fields to match SELECT query: id, title
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
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

	public function testValidateEmptyResultThrowsError(): void {
		echo "\nTesting validation failure with empty result (no sample data)\n";

		$mockClient = $this->createMockClient();

		// Schema with only 3 fields to match SELECT query: id, title, price
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse([]); // Empty result - no sample data
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		// Empty result means no sample data for type validation
		$validator->validateCompatibility(
			'SELECT id, title, price FROM source_table WHERE 1=0',
			'target_table'
		);
	}

	public function testValidateSelectStarThrowsError(): void {
		echo "\nTesting validation failure with SELECT * (cannot determine field count)\n";

		$mockClient = $this->createMockClient();

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse();
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse([]); // Empty result for SELECT *
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		// SELECT * with empty result - cannot determine field count
		$validator->validateCompatibility(
			'SELECT * FROM complex_join',
			'target_table'
		);
	}



	// ========================================================================
	// MATCH() Clause Support Tests
	// ========================================================================

	public function testValidateCompatibilityWithMatchInWhere(): void {
		echo "\nTesting schema validation with MATCH() in WHERE clause\n";

		$mockClient = $this->createMockClient();

		// Schema with 3 fields to match SELECT query: id, title, price
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
					}
					if (str_contains($query, 'MATCH(title') && str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[
								['id' => 1, 'title' => 'Keyword Product', 'price' => 99.99],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		// Should not throw exception - MATCH() in WHERE is valid
		$validator->validateCompatibility(
			'SELECT id, title, price FROM source_table WHERE MATCH(title, \'@keyword\')',
			'target_table'
		);

		$this->assertTrue(true); // Test passes if no exception thrown
	}

	public function testValidateCompatibilityWithMatchAndAndCondition(): void {
		echo "\nTesting schema validation with MATCH() and AND condition\n";

		$mockClient = $this->createMockClient();

		// Schema with 3 fields to match SELECT query: id, title, price
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
					}
					$hasMatch = str_contains($query, 'MATCH(title');
					$hasAnd = str_contains($query, 'AND');
					$hasLimit = str_contains($query, 'LIMIT 1');
					if ($hasMatch && $hasAnd && $hasLimit) {
						return $this->createSelectResponse(
							[
								['id' => 1, 'title' => 'Keyword Product', 'price' => 149.99],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, title, price FROM source_table WHERE MATCH(title, \'@keyword\') AND price > 100',
			'target_table'
		);

		$this->assertTrue(true);
	}

	public function testValidateCompatibilityWithMatchAndMultipleFields(): void {
		echo "\nTesting schema validation with MATCH() on multiple fields\n";

		$mockClient = $this->createMockClient();

		// Schema with 3 fields to match SELECT query: id, title, price
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
					}
					$hasMatch = str_contains($query, 'MATCH(');
					$hasTitle = str_contains($query, 'title');
					$hasLimit = str_contains($query, 'LIMIT 1');
					if ($hasMatch && $hasTitle && $hasLimit) {
						return $this->createSelectResponse(
							[
								['id' => 1, 'title' => 'Keyword Product', 'price' => 99.99],
							]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, title, price FROM source_table WHERE MATCH(\'title\', \'@search_term\')',
			'target_table'
		);

		$this->assertTrue(true);
	}

	public function testValidateCompatibilityWithInvalidMatchSyntax(): void {
		echo "\nTesting validation failure with invalid MATCH() syntax\n";

		$mockClient = $this->createMockClient();

		// Schema with 2 fields to match SELECT query: id, title
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
					}
					if (str_contains($query, 'MATCH(title)') && str_contains($query, 'LIMIT 1')) {
						// Invalid MATCH syntax - missing query parameter
						return $this->createErrorResponse('ERROR 1064: Syntax error in MATCH expression');
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT id, title FROM source_table WHERE MATCH(title)',
			'target_table'
		);
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

		// Schema with only 4 fields to match SELECT query: id, title, price, is_active
		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
			['Field' => 'is_active', 'Type' => 'bool', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
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

		// Verify all target fields were correctly loaded (position-indexed)
		$targetFields = $validator->getTargetFields();
		$this->assertCount(4, $targetFields); // 4 selected target fields
		$this->assertEquals('id', $targetFields[0]['name']);
		$this->assertEquals('bigint', $targetFields[0]['type']);
		$this->assertEquals('title', $targetFields[1]['name']);
		$this->assertEquals('text', $targetFields[1]['type']);
		$this->assertEquals('stored', $targetFields[1]['properties']);
		$this->assertEquals('price', $targetFields[2]['name']);
		$this->assertEquals('float', $targetFields[2]['type']);
		$this->assertEquals('is_active', $targetFields[3]['name']);
		$this->assertEquals('bool', $targetFields[3]['type']);
	}

	// ========================================================================
	// Column List Support Tests
	// ========================================================================

	public function testValidateCompatibilityWithColumnList(): void {
		echo "\nTesting REPLACE INTO with column list: REPLACE INTO target (col1, col2) SELECT ...\n";

		$mockClient = $this->createMockClient();

		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->withConsecutive(
				['DESC target_table'],
				['SELECT title, price FROM source_table LIMIT 1']
			)
			->willReturnOnConsecutiveCalls(
				$this->createTableSchemaResponse($schemaFields),
				$this->createSelectResponse(
					[
						['title' => 'Product A', 'price' => 99.99],
					]
				)
			);

		$validator = new FieldValidator($mockClient);

		// Column list with only 2 columns (not all target fields)
		$validator->validateCompatibility(
			'SELECT title, price FROM source_table',
			'target_table',
			['title', 'price']
		);

		$this->assertTrue(true); // Test passes if no exception thrown
	}

	public function testValidateCompatibilityWithColumnListPartialFields(): void {
		echo "\nTesting REPLACE INTO with column list selecting subset of fields\n";

		$mockClient = $this->createMockClient();

		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
			['Field' => 'description', 'Type' => 'text', 'Properties' => ''],
		];

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->withConsecutive(
				['DESC target_table'],
				['SELECT id, title FROM source_table LIMIT 1']
			)
			->willReturnOnConsecutiveCalls(
				$this->createTableSchemaResponse($schemaFields),
				$this->createSelectResponse(
					[
						['id' => 1, 'title' => 'Product A'],
					]
				)
			);

		$validator = new FieldValidator($mockClient);

		// Column list specifies only 2 of 4 target fields
		$validator->validateCompatibility(
			'SELECT id, title FROM source_table',
			'target_table',
			['id', 'title']
		);

		$this->assertTrue(true); // Test passes if no exception thrown
	}

	public function testValidateColumnListWithNonexistentColumn(): void {
		echo "\nTesting REPLACE INTO column list with non-existent column\n";

		$mockClient = $this->createMockClient();

		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		// Column list contains a column that doesn't exist in target
		$validator->validateCompatibility(
			'SELECT id, title FROM source_table',
			'target_table',
			['id', 'invalid_col']
		);
	}

	public function testValidateColumnListFieldCountMismatch(): void {
		echo "\nTesting REPLACE INTO column list with field count mismatch\n";

		$mockClient = $this->createMockClient();

		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($schemaFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($schemaFields);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		// SELECT returns 2 fields but column list expects 3
		$validator->validateCompatibility(
			'SELECT id, title FROM source_table',
			'target_table',
			['id', 'title', 'price']
		);
	}

	public function testValidateColumnListWithFunctionFields(): void {
		echo "\nTesting REPLACE INTO column list with function expressions\n";

		$mockClient = $this->createMockClient();

		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->withConsecutive(
				['DESC target_table'],
				['SELECT id, title, price * 1.1 AS adjusted_price FROM source_table LIMIT 1']
			)
			->willReturnOnConsecutiveCalls(
				$this->createTableSchemaResponse($schemaFields),
				$this->createSelectResponse(
					[
						['id' => 1, 'title' => 'Product A', 'adjusted_price' => 109.99],
					]
				)
			);

		$validator = new FieldValidator($mockClient);

		// Column list with functions (adjusted_price is price * 1.1)
		$validator->validateCompatibility(
			'SELECT id, title, price * 1.1 AS adjusted_price FROM source_table',
			'target_table',
			['id', 'title', 'price']
		);

		$this->assertTrue(true); // Test passes if no exception thrown
	}

	public function testValidateColumnListReorderedColumns(): void {
		echo "\nTesting REPLACE INTO column list with reordered columns\n";

		$mockClient = $this->createMockClient();

		$schemaFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->withConsecutive(
				['DESC target_table'],
				['SELECT price, title, id FROM source_table LIMIT 1']
			)
			->willReturnOnConsecutiveCalls(
				$this->createTableSchemaResponse($schemaFields),
				$this->createSelectResponse(
					[
						['price' => 99.99, 'title' => 'Product A', 'id' => 1],
					]
				)
			);

		$validator = new FieldValidator($mockClient);

		// Column list with reordered columns: price, title, id (not default order)
		$validator->validateCompatibility(
			'SELECT price, title, id FROM source_table',
			'target_table',
			['price', 'title', 'id']
		);

		$this->assertTrue(true); // Test passes if no exception thrown
	}

	public function testValidateSelectStarWithColumnList(): void {
		echo "\nTesting REPLACE INTO column list with SELECT *\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$sourceFields = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => ''],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->expects($this->exactly(3))
			->method('sendRequest')
			->withConsecutive(
				['DESC target_table'],
				['DESC source_table'],
				['SELECT * FROM source_table LIMIT 1']
			)
			->willReturnOnConsecutiveCalls(
				$this->createTableSchemaResponse($targetFields),
				$this->createTableSchemaResponse($sourceFields),
				$this->createSelectResponse(
					[
						['id' => 1, 'title' => 'Product A', 'price' => 99.99],
					]
				)
			);

		$validator = new FieldValidator($mockClient);

		// SELECT * with column list should match column list field names to source fields
		$validator->validateCompatibility(
			'SELECT * FROM source_table',
			'target_table',
			['id', 'title', 'price']
		);

		$this->assertTrue(true); // Test passes if no exception thrown
	}
}
