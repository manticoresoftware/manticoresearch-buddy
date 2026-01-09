<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\BatchProcessor;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use Manticoresearch\BuddyTest\Trait\ReplaceSelectTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Batch processor type boundary tests for REPLACE SELECT operations
 *
 * Tests boundary conditions and edge cases in type conversion
 */
class BatchProcessorTypeBoundaryTest extends TestCase {

	use TestProtectedTrait;
	use ReplaceSelectTestTrait;

	// ========================================================================
	// Numeric Boundary Tests
	// ========================================================================

	public function testMaxIntValue(): void {
		echo "\nTesting maximum INT value handling\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'max_uint', 'type' => 'uint', 'properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($targetFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'max_int' => 2147483647]] // Max INT value
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createSuccessResponse();
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 1000);

		$testRow = ['id' => 1, 'max_int' => 2147483647];
		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		$this->assertArrayHasKey(0, $processedRow); // id
		$this->assertArrayHasKey(1, $processedRow); // max_int
		$this->assertEquals(1, $processedRow[0]);
		$this->assertEquals(2147483647, $processedRow[1]);

		echo "✓ Max INT value handled correctly\n";
	}

	public function testMinIntValue(): void {
		echo "\nTesting minimum INT value handling\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'min_uint', 'type' => 'uint', 'properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($targetFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'min_int' => -2147483648]] // Min INT value
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createSuccessResponse();
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 1000);

		$testRow = ['id' => 1, 'min_int' => -2147483648];
		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		$this->assertEquals(-2147483648, $processedRow[1]);

		echo "✓ Min INT value handled correctly\n";
	}

	public function testBigintOverflow(): void {
		echo "\nTesting BIGINT overflow scenario\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'large_number', 'type' => 'bigint', 'properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($targetFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'large_number' => '9223372036854775808']] // Max BIGINT
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createSuccessResponse();
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 1000);

		$testRow = ['id' => 1, 'large_number' => '9223372036854775808'];
		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		$this->assertEquals('9223372036854775808', $processedRow[1]);

		echo "✓ BIGINT overflow value handled correctly\n";
	}

	public function testFloatPrecisionLoss(): void {
		echo "\nTesting FLOAT precision loss to BIGINT\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'truncated_value', 'type' => 'bigint', 'properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($targetFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'truncated_value' => 99.99]] // Float that will be truncated
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createSuccessResponse();
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 1000);

		$testRow = ['id' => 1, 'truncated_value' => 99.99];
		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		// Float should be converted to bigint (99.99 → 99)
		$this->assertEquals(99, $processedRow[1]);

		echo "✓ Float precision loss handled correctly\n";
	}

	// ========================================================================
	// NULL and Empty Value Tests
	// ========================================================================

	public function testEmptyStringToNumeric(): void {
		echo "\nTesting empty string to numeric conversion\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'empty_to_int', 'type' => 'uint', 'properties' => ''],
			['name' => 'empty_to_float', 'type' => 'float', 'properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($targetFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'empty_to_int' => '', 'empty_to_float' => '']]
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createSuccessResponse();
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 1000);

		$testRow = ['id' => 1, 'empty_to_int' => '', 'empty_to_float' => ''];
		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		// Empty string: int field doesn't match, returns ''; float field matches, returns 0.0
		$this->assertEquals(0, $processedRow[1]);
		$this->assertEquals(0.0, $processedRow[2]);

		echo "✓ Empty string to numeric conversion handled correctly\n";
	}

	// ========================================================================
	// Boolean Conversion Tests
	// ========================================================================

	public function testBoolToIntConversion(): void {
		echo "\nTesting BOOL to INT conversion\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'bool_as_int', 'type' => 'uint', 'properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($targetFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'bool_as_int' => true]]
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createSuccessResponse();
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 1000);

		$testRow = ['id' => 1, 'bool_as_int' => true];
		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		// true should be converted to 1
		$this->assertEquals(1, $processedRow[1]);

		echo "✓ BOOL to INT conversion successful\n";
	}

	public function testBoolToFloatConversion(): void {
		echo "\nTesting BOOL to FLOAT conversion\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'bool_as_float', 'type' => 'float', 'properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($targetFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'bool_as_float' => false]]
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createSuccessResponse();
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 1000);

		$testRow = ['id' => 1, 'bool_as_float' => false];
		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		// false should be converted to 0.0
		$this->assertEquals(0.0, $processedRow[1]);

		echo "✓ BOOL to FLOAT conversion successful\n";
	}

	// ========================================================================
	// Array and JSON Tests
	// ========================================================================

	public function testArrayToJsonConversion(): void {
		echo "\nTesting array to JSON conversion\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'array_as_json', 'type' => 'json', 'properties' => ''],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($targetFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createSelectResponse(
							[['id' => 1, 'array_as_json' => [1, 2, 3]]]
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createSuccessResponse();
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 1000);

		$testRow = ['id' => 1, 'array_as_json' => [1, 2, 3]];
		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		// Array should be JSON encoded and escaped for SQL
		$this->assertEquals("'[1,2,3]'", $processedRow[1]);

		echo "✓ Array to JSON conversion successful\n";
	}

	public function testObjectToStringConversion(): void {
		echo "\nTesting object to string conversion\n";

		$mockClient = $this->createMockClient();

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'object_as_text', 'type' => 'text', 'properties' => 'stored'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($targetFields) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createTableSchemaResponse($targetFields);
					}
					if (str_contains($query, 'LIMIT 1')) {
						// Create an object with __toString method
						$obj = new class {
							public function __toString(): string {
								return 'object string representation';
							}
						};
						return $this->createSelectResponse(
							[['id' => 1, 'object_as_text' => $obj]]
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createSuccessResponse();
					}
					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 1000);

		// Create test object
		$testObj = new class {
			public function __toString(): string {
				return 'object string representation';
			}
		};
		$testRow = ['id' => 1, 'object_as_text' => $testObj];
		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		// Object should be converted using __toString and escaped for SQL
		$this->assertEquals("'object string representation'", $processedRow[1]);

		echo "✓ Object to string conversion successful\n";
	}

	// ========================================================================
	// Helper Methods
	// ========================================================================

	/**
	 * Create a mock response for table schema (DESC command)
	 *
	 * @param array<int,array{name: string, type: string, properties: string}> $fields
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
	 * Create a mock success response
	 */
	private function createSuccessResponse(): Response {
		return $this->createMockResponse(true, []);
	}

	/**
	 * Create a mock error response
	 */
	private function createErrorResponse(string $errorMessage): Response {
		return $this->createMockResponse(false, null, $errorMessage);
	}
}
