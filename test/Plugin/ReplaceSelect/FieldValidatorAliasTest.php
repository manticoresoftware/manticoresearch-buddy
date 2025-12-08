<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\FieldValidator;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use Manticoresearch\BuddyTest\Trait\ReplaceSelectTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Field alias validation tests for REPLACE SELECT operations
 *
 * Tests alias parsing and validation in SELECT clauses
 */
class FieldValidatorAliasTest extends TestCase {

	use TestProtectedTrait;
	use ReplaceSelectTestTrait;

	// ========================================================================
	// Simple Alias Tests
	// ========================================================================

	public function testSimpleAlias(): void {
		echo "\nTesting simple field alias\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'product_name', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'product_name' => 'Test Product']]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		// Should not throw exception - alias should be resolved correctly
		$validator->validateCompatibility(
			'SELECT id, name AS product_name FROM source',
			'target',
			['id', 'product_name']
		);

		$this->assertTrue(true); // Test passes if no exception thrown
	}

	public function testMultipleAliases(): void {
		echo "\nTesting multiple field aliases\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'product_name', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'product_price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'product_name' => 'Test', 'product_price' => 99.99]]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, name AS product_name, price AS product_price FROM source',
			'target',
			['id', 'product_name', 'product_price']
		);

		$this->assertTrue(true);
	}

	public function testFunctionWithAlias(): void {
		echo "\nTesting function with alias\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'rounded_price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'rounded_price' => 100.0]]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, CEIL(price) AS rounded_price FROM source',
			'target',
			['id', 'rounded_price']
		);

		$this->assertTrue(true);
	}

	public function testExpressionWithAlias(): void {
		echo "\nTesting expression with alias\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'adjusted_price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'adjusted_price' => 109.99]]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, price * 1.1 AS adjusted_price FROM source',
			'target',
			['id', 'adjusted_price']
		);

		$this->assertTrue(true);
	}

	public function testAliasWithReservedWords(): void {
		echo "\nTesting alias with reserved words\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'count', 'Type' => 'uint', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'count' => 42]]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, total AS count FROM source',
			'target',
			['id', 'count']
		);

		$this->assertTrue(true);
	}

	public function testCaseInsensitiveAs(): void {
		echo "\nTesting case insensitive AS keyword\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'product_name', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'product_price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'product_name' => 'Test', 'product_price' => 99.99]]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, name as product_name, price As product_price FROM source',
			'target',
			['id', 'product_name', 'product_price']
		);

		$this->assertTrue(true);
	}

	public function testAliasWithWhitespace(): void {
		echo "\nTesting alias with extra whitespace\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'product_name', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'product_name' => 'Test Product']]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, name  AS  product_name FROM source',
			'target',
			['id', 'product_name']
		);

		$this->assertTrue(true);
	}

	// ========================================================================
	// Alias with Column List Tests
	// ========================================================================

	public function testAliasInColumnList(): void {
		echo "\nTesting alias in column list\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'new_name', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'new_name' => 'Renamed Product']]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, name AS new_name FROM source',
			'target',
			['id', 'new_name']
		);

		$this->assertTrue(true);
	}

	public function testMultipleAliasesInColumnList(): void {
		echo "\nTesting multiple aliases in column list\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'product_name', 'Type' => 'text', 'Properties' => 'stored'],
			['Field' => 'product_price', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'product_name' => 'Test', 'product_price' => 99.99]]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$validator->validateCompatibility(
			'SELECT id, name AS product_name, cost AS product_price FROM source',
			'target',
			['id', 'product_name', 'product_price']
		);

		$this->assertTrue(true);
	}

	public function testAliasOrderDoesNotMatter(): void {
		echo "\nTesting alias order independence with column list\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
			['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'price' => 99.99, 'title' => 'Test']]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		// Column list order differs from SELECT order
		$validator->validateCompatibility(
			'SELECT id, title AS name, cost AS price FROM source',
			'target',
			['id', 'price', 'title']
		);

		$this->assertTrue(true);
	}

	// ========================================================================
	// Error Cases
	// ========================================================================

	public function testAliasWithoutColumnList(): void {
		echo "\nTesting alias without column list (position-based matching)\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'product_name', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'product_name' => 'Test']]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		// Should succeed - alias resolves to field name that matches target by position
		$validator->validateCompatibility(
			'SELECT id, title AS product_name FROM source',
			'target'
		);

		$this->assertTrue(true);
	}

	// ========================================================================
	// Helper Methods
	// ========================================================================

	/**
	 * Create a mock response for table schema (DESC command)
	 *
	 * @param array<int,array{Field: string, Type: string, Properties: string}> $fields
	 */
	private function createTableSchemaResponse(array $fields): Response {
		return $this->createMockResponse(true, $fields);
	}

	/**
	 * Create a mock response for SELECT queries
	 *
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function createSelectResponse(array $rows): Response {
		return $this->createMockResponse(true, $rows);
	}

	/**
	 * Create a mock error response
	 */
	private function createErrorResponse(string $errorMessage): Response {
		return $this->createMockResponse(false, null, $errorMessage);
	}
}
