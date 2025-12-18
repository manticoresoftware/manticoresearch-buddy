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

/**
 * Field type mismatch validation tests for REPLACE SELECT operations
 *
 * Tests incompatible type conversions that should be rejected during validation
 */
class FieldValidatorTypeMismatchTest extends TestCase {

	use TestProtectedTrait;
	use ReplaceSelectTestTrait;

	// ========================================================================
	// Incompatible Type Rejection Tests
	// ========================================================================

	public function testTextToIntRejection(): void {
		echo "\nTesting TEXT to INT type mismatch rejection\n";

		$mockClient = $this->createMockClient();

		// Target: UINT, Source: TEXT
		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'count', 'Type' => 'uint', 'Properties' => ''],
		];

		$sourceSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'count', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema, $sourceSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_starts_with($query, 'DESC source')) {
						return $this->createTableSchemaResponse($sourceSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'count' => 'some text']]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT * FROM source',
			'target'
		);
	}

	public function testIntToTextRejection(): void {
		echo "\nTesting INT to TEXT type mismatch rejection\n";

		$mockClient = $this->createMockClient();

		// Target: TEXT, Source: UINT
		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'count', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$sourceSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'count', 'Type' => 'uint', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema, $sourceSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_starts_with($query, 'DESC source')) {
						return $this->createTableSchemaResponse($sourceSchema);
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

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT * FROM source',
			'target'
		);
	}

	public function testFloatToBoolRejection(): void {
		echo "\nTesting FLOAT to BOOL type mismatch rejection\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'active', 'Type' => 'bool', 'Properties' => ''],
		];

		$sourceSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'active', 'Type' => 'float', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema, $sourceSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_starts_with($query, 'DESC source')) {
						return $this->createTableSchemaResponse($sourceSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'active' => 0.95]]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT * FROM source',
			'target'
		);
	}

	public function testMultiToTextRejection(): void {
		echo "\nTesting MULTI to TEXT type mismatch rejection\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'tags', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$sourceSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'tags', 'Type' => 'multi', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema, $sourceSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_starts_with($query, 'DESC source')) {
						return $this->createTableSchemaResponse($sourceSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'tags' => [1, 2, 3]]]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT * FROM source',
			'target'
		);
	}

	public function testJsonToIntRejection(): void {
		echo "\nTesting JSON to INT type mismatch rejection\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'metadata', 'Type' => 'uint', 'Properties' => ''],
		];

		$sourceSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'metadata', 'Type' => 'json', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema, $sourceSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_starts_with($query, 'DESC source')) {
						return $this->createTableSchemaResponse($sourceSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'metadata' => '{"key": "value"}']]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT * FROM source',
			'target'
		);
	}

	public function testTimestampToTextRejection(): void {
		echo "\nTesting TIMESTAMP to TEXT type mismatch rejection\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'created_at', 'Type' => 'text', 'Properties' => 'stored'],
		];

		$sourceSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'created_at', 'Type' => 'timestamp', 'Properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetSchema, $sourceSchema) {
					if (str_starts_with($query, 'DESC target')) {
						return $this->createTableSchemaResponse($targetSchema);
					}
					if (str_starts_with($query, 'DESC source')) {
						return $this->createTableSchemaResponse($sourceSchema);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'created_at' => 1609459200]]
						);
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$validator = new FieldValidator($mockClient);

		$this->expectException(ManticoreSearchClientError::class);

		$validator->validateCompatibility(
			'SELECT * FROM source',
			'target'
		);
	}

	public function testIncompatibleTypeWithColumnList(): void {
		echo "\nTesting incompatible type rejection with column list\n";

		$mockClient = $this->createMockClient();

		$targetSchema = [
			['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
			['Field' => 'price', 'Type' => 'uint', 'Properties' => ''],
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

		// Column list validation doesn't check types, only field counts
		// This should pass even with incompatible types
		$validator->validateCompatibility(
			'SELECT id, price, title FROM source',
			'target',
			['id', 'price', 'title']
		);

		$this->assertTrue(true); // Test passes if no exception thrown
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
