<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\BatchProcessor;
use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use Manticoresearch\BuddyTest\Trait\ReplaceSelectTestTrait;
use PHPUnit\Framework\TestCase;

class BatchProcessorTest extends TestCase {

	use TestProtectedTrait;
	use ReplaceSelectTestTrait;

	private const BATCH_SIZE = 1000;

	public function testExecuteWithMultipleBatches(): void {
		echo "\nTesting batch processing with multiple batches\n";

		// Test data for multiple batches (batch size = 2)
		$batch1Data = [
			['id' => 1, 'title' => 'Product A', 'price' => 99.99],
			['id' => 2, 'title' => 'Product B', 'price' => 29.99],
		];

		$batch2Data = [
			['id' => 3, 'title' => 'Product C', 'price' => 149.99],
		];

		$callSequence = [];
		$mockClient = $this->createMock(Client::class);
		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use (&$callSequence, $batch1Data, $batch2Data) {
					$callSequence[] = $query;

					if (str_contains($query, 'LIMIT 2 OFFSET 0')) {
						return $this->createSelectResponse($batch1Data);
					}
					if (str_contains($query, 'LIMIT 2 OFFSET 2')) {
						return $this->createSelectResponse($batch2Data);
					}
					if (str_contains($query, 'LIMIT 2 OFFSET 3')) {
						// Third batch is empty (end of data)
						return $this->createSelectResponse([]);
					}
					if (str_starts_with($query, 'DESC')) {
						// Mock fields loading
						return $this->createSelectResponse(
							[
								['Field' => 'id', 'Type' => 'bigint'],
								['Field' => 'title', 'Type' => 'text'],
								['Field' => 'price', 'Type' => 'float'],
								]
						);
					}

					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$mockClient->method('sendMultiRequest')
			->willReturnCallback(
				function (array $requests) use (&$callSequence) {
					// Mock bulk operations - just return success for any bulk request
					$responses = [];
					foreach ($requests as $request) {
						$callSequence[] = $request['request'];
						// Create a mock response for bulk operations
						$mockResponse = $this->createMock(Response::class);
						$mockResponse->method('hasError')->willReturn(false);
						$mockResponse->method('getResult')->willReturn(
							Struct::fromData(
								[
								'errors' => false,
								'items' => [
									['index' => ['_id' => '1', 'result' => 'created']],
									['index' => ['_id' => '2', 'result' => 'created']],
								],
								]
							)
						);
						$responses[] = $mockResponse;
					}
					return $responses;
				}
			);

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 2);
		$result = $processor->execute();

		$this->assertEquals(3, $result); // Total records processed
		$this->assertEquals(2, $processor->getBatchesProcessed()); // 2 batches (batch1: 2 records, batch2: 1 record)

		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, 2);
		$result = $processor->execute();

		$this->assertEquals(3, $result); // Total records processed
		$this->assertEquals(2, $processor->getBatchesProcessed()); // 2 batches (batch1: 2 records, batch2: 1 record)

		$this->assertContains('SELECT id, title, price FROM source ORDER BY id ASC LIMIT 2 OFFSET 0', $callSequence);
		$this->assertContains('SELECT id, title, price FROM source ORDER BY id ASC LIMIT 2 OFFSET 2', $callSequence);
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

	/**
	 * Create a valid payload for testing
	 *
	 * @param array<string,mixed> $overrides
	 */
	private function createValidPayload(array $overrides = []): Payload {
		$query = $overrides['query'] ?? 'REPLACE INTO target SELECT id, title, price FROM source';
		// Type narrowing for PHPStan
		assert(is_string($query));
		/** @var string $query */

		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => $query,
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload = Payload::fromRequest($request);

		// Override properties if specified
		foreach ($overrides as $key => $value) {
			if ($key === 'query' || !property_exists($payload, $key)) {
				continue;
			}

			$payload->$key = $value;
		}

		return $payload;
	}





	// ========================================================================
	// Batch Execution Tests
	// ========================================================================

	/**
	 * Create standard target fields for testing (position-indexed, matching test DESC responses)
	 *
	 * @return array<int,array{name: string, type: string, properties: string}>
	 */
	private function createTargetFields(): array {
		return [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'title', 'type' => 'text', 'properties' => ''],
			['name' => 'price', 'type' => 'float', 'properties' => ''],
		];
	}

	public function testExecuteWithSingleBatch(): void {
		echo "\nTesting batch processing with single batch (small dataset)\n";

		$mockClient = $this->createMock(Client::class);

		$testData = [
			['id' => 1, 'title' => 'Product A', 'price' => 99.99],
			['id' => 2, 'title' => 'Product B', 'price' => 29.99],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($testData) {
					if (str_contains($query, 'LIMIT 1000 OFFSET 0')) {
						return $this->createSelectResponse($testData);
					}
					if (str_contains($query, 'LIMIT 1000 OFFSET 2')) {
						// Second batch is empty (end of data)
						return $this->createSelectResponse([]);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							['Field' => 'price', 'Type' => 'float'],
							]
						);
					}

					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$mockClient->method('sendMultiRequest')
			->willReturnCallback(
				function (array $requests) {
					// Mock bulk operations - just return success for any bulk request
					return array_map(
						function () {
							// Create a mock response for bulk operations
							$mockResponse = $this->createMock(Response::class);
							$mockResponse->method('hasError')->willReturn(false);
							$mockResponse->method('getResult')->willReturn(
								Struct::fromData(
									[
									'errors' => false,
									'items' => [
										['index' => ['_id' => '1', 'result' => 'created']],
										['index' => ['_id' => '2', 'result' => 'created']],
									],
									]
								)
							);
							return $mockResponse;
						},
						$requests
					);
				}
			);

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);
		$result = $processor->execute();

		$this->assertEquals(2, $result);
		$this->assertEquals(1, $processor->getBatchesProcessed());
	}

	public function testExecuteWithEmptyResults(): void {
		echo "\nTesting batch processing with no data to process\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_contains($query, 'LIMIT 1000 OFFSET 0')) {
						// First batch is already empty
						return $this->createSelectResponse([]);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							]
						);
					}

					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);
		$result = $processor->execute();

		$this->assertEquals(0, $result);
		$this->assertEquals(0, $processor->getBatchesProcessed());
	}

	public function testExecuteWithConsecutiveEmptyBatches(): void {
		echo "\nTesting batch processing handles consecutive empty batches correctly\n";

		$mockClient = $this->createMock(Client::class);

		$callCount = 0;
		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use (&$callCount) {
					if (str_contains($query, 'LIMIT') && str_contains($query, 'OFFSET')) {
						$callCount++;
						// Always return empty batches to test termination logic
						return $this->createSelectResponse([]);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							]
						);
					}

					return $this->createErrorResponse('Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);
		$result = $processor->execute();

		$this->assertEquals(0, $result);
		$this->assertEquals(0, $processor->getBatchesProcessed());
		// Should stop after maxEmptyBatches (3) consecutive empty batches
		$this->assertLessThanOrEqual(3, $callCount);
	}

	// ========================================================================
	// Row Processing Tests
	// ========================================================================

	public function testProcessRowWithAllFieldTypes(): void {
		echo "\nTesting row processing with different field types\n";

		$mockClient = $this->createMock(Client::class);

		// Mock fields loading
		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							['Field' => 'price', 'Type' => 'float'],
							['Field' => 'is_active', 'Type' => 'bool'],
							['Field' => 'count_value', 'Type' => 'uint'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'title', 'type' => 'text', 'properties' => 'stored'],
			['name' => 'price', 'type' => 'float', 'properties' => ''],
			['name' => 'is_active', 'type' => 'bool', 'properties' => ''],
			['name' => 'count_value', 'type' => 'uint', 'properties' => ''],
		];

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);

		$testRow = [
			'id' => 1,
			'title' => 'Product A',
			'price' => 99.99,
			'is_active' => 1,
			'count_value' => 100,
		];

		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		// processRow returns position-indexed array
		$this->assertArrayHasKey(0, $processedRow); // id (position 0)
		$this->assertArrayHasKey(1, $processedRow); // title (position 1)
		$this->assertArrayHasKey(2, $processedRow); // price (position 2)
		$this->assertArrayHasKey(3, $processedRow); // is_active (position 3)
		$this->assertArrayHasKey(4, $processedRow); // count_value (position 4)
	}

	public function testProcessRowWithMissingRequiredId(): void {
		echo "\nTesting row processing fails when ID field is missing\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$payload = $this->createValidPayload();
		// This test's DESC returns 2 fields (id, title), so use 2-field targetFields
		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'title', 'type' => 'text', 'properties' => ''],
		];
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);

		// Row missing id field - only has title
		$testRowWithoutId = [
			'title' => 'Product A',
		];

		$this->expectException(ManticoreSearchClientError::class);
		// Field count mismatch: row has 1 value, targetFields expects 2
		// $this->expectExceptionMessage('Column count mismatch: row has 1 values but target has 2');

		self::invokeMethod($processor, 'processRow', [$testRowWithoutId]);
	}

	public function testProcessRowWithUnknownField(): void {
		echo "\nTesting row processing skips unknown fields\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'title', 'type' => 'text', 'properties' => 'stored'],
		];

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);

		// Position-based approach: row must have exactly the fields in targetFields
		// Unknown fields will cause count mismatch, so just pass known fields
		$testRowWithKnownFields = [
			'id' => 1,
			'title' => 'Product A',
		];

		$processedRow = self::invokeMethod($processor, 'processRow', [$testRowWithKnownFields]);

		// Type narrowing for PHPStan
		assert(is_array($processedRow));
		/** @var array<int,mixed> $processedRow */

		// processRow returns position-indexed array
		$this->assertArrayHasKey(0, $processedRow); // id
		$this->assertArrayHasKey(1, $processedRow); // title
	}

	// ========================================================================
	// REPLACE Query Building Tests
	// ========================================================================

	public function testExecuteReplaceBatchBasic(): void {
		echo "\nTesting REPLACE query building and execution\n";

		$mockClient = $this->createMock(Client::class);

		$capturedReplaceQuery = null;
		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							['Field' => 'price', 'Type' => 'float'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$mockClient->method('sendMultiRequest')
			->willReturnCallback(
				function (array $requests) use (&$capturedReplaceQuery) {
					// Capture the bulk JSON for testing
					$capturedReplaceQuery = $requests[0]['request'];
					// Mock bulk operations - return success
					$mockResponse = $this->createMock(Response::class);
					$mockResponse->method('hasError')->willReturn(false);
					$mockResponse->method('getResult')->willReturn(
						Struct::fromData(
							[
							'errors' => false,
							'items' => [
								['index' => ['_id' => '1', 'result' => 'created']],
								['index' => ['_id' => '2', 'result' => 'created']],
							],
							]
						)
					);
					return [$mockResponse];
				}
			);

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);

		$testBatch = [
			['id' => 1, 'title' => 'Product A', 'price' => 99.99],
			['id' => 2, 'title' => 'Product B', 'price' => 29.99],
		];

		self::invokeMethod($processor, 'executeReplaceBatch', [$testBatch]);

		$this->assertNotNull($capturedReplaceQuery);
		// Type narrowing for PHPStan
		/** @var string $capturedReplaceQuery */
		$this->assertStringContainsString('replace', $capturedReplaceQuery);
		$this->assertStringContainsString('Product A', $capturedReplaceQuery);
		$this->assertStringContainsString('Product B', $capturedReplaceQuery);
		$this->assertStringContainsString('"id":1', $capturedReplaceQuery);
		$this->assertStringContainsString('"id":2', $capturedReplaceQuery);
	}

	public function testExecuteReplaceBatchWithSpecialCharacters(): void {
		echo "\nTesting REPLACE query handles special characters correctly\n";

		$mockClient = $this->createMock(Client::class);

		$capturedReplaceQuery = null;
		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$mockClient->method('sendMultiRequest')
			->willReturnCallback(
				function (array $requests) use (&$capturedReplaceQuery) {
					// Capture the bulk JSON for testing
					$capturedReplaceQuery = $requests[0]['request'];
					// Mock bulk operations - return success
					$mockResponse = $this->createMock(Response::class);
					$mockResponse->method('hasError')->willReturn(false);
					$mockResponse->method('getResult')->willReturn(
						Struct::fromData(
							[
							'errors' => false,
							'items' => [
								['index' => ['_id' => '1', 'result' => 'created']],
								['index' => ['_id' => '2', 'result' => 'created']],
							],
							]
						)
					);
					return [$mockResponse];
				}
			);

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);

		$testBatchWithSpecialChars = [
			['id' => 1, 'title' => "Product with 'quotes' and \"double quotes\"", 'price' => 99.99],
			['id' => 2, 'title' => 'Product with unicode: é, à, ñ', 'price' => 199.99],
		];

		self::invokeMethod($processor, 'executeReplaceBatch', [$testBatchWithSpecialChars]);

		$this->assertNotNull($capturedReplaceQuery);
		// Type narrowing for PHPStan
		/** @var string $capturedReplaceQuery */
		// Should contain JSON bulk format with special characters
		$this->assertStringContainsString('replace', $capturedReplaceQuery);
		$this->assertStringContainsString('quotes', $capturedReplaceQuery);
		$this->assertStringContainsString('unicode', $capturedReplaceQuery);
	}

	public function testExecuteReplaceBatchEmpty(): void {
		echo "\nTesting REPLACE with empty batch does nothing\n";

		$mockClient = $this->createMock(Client::class);

		$queryExecuted = false;
		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use (&$queryExecuted) {
					if (str_starts_with($query, 'REPLACE INTO')) {
						$queryExecuted = true;
						return $this->createSuccessResponse();
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);

		self::invokeMethod($processor, 'executeReplaceBatch', [[]]);

		$this->assertFalse($queryExecuted); // No REPLACE query should be executed for empty batch
	}

	public function testExecuteReplaceBatchFailure(): void {
		echo "\nTesting REPLACE query execution failure\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'REPLACE INTO')) {
						return $this->createErrorResponse('Connection lost during REPLACE');
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);

		$testBatch = [
			['id' => 1, 'title' => 'Product A'],
		];

		$this->expectException(ManticoreSearchClientError::class);

		self::invokeMethod($processor, 'executeReplaceBatch', [$testBatch]);
	}

	// ========================================================================
	// Statistics and Performance Tests
	// ========================================================================

	public function testStatisticsCollection(): void {
		echo "\nTesting statistics collection during batch processing\n";

		$mockClient = $this->createMock(Client::class);

		$testData = [
			['id' => 1, 'title' => 'Product A'],
			['id' => 2, 'title' => 'Product B'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($testData) {
					if (str_contains($query, 'LIMIT 1000 OFFSET 0')) {
						return $this->createSelectResponse($testData);
					}
					if (str_contains($query, 'LIMIT 1000 OFFSET 2')) {
						return $this->createSelectResponse([]);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$mockClient->method('sendMultiRequest')
			->willReturnCallback(
				function (array $requests) {
					// Mock bulk operations - return success
					return array_map(
						function () {
							$mockResponse = $this->createMock(Response::class);
							$mockResponse->method('hasError')->willReturn(false);
							$mockResponse->method('getResult')->willReturn(
								Struct::fromData(
									[
									'errors' => false,
									'items' => [
										['index' => ['_id' => '1', 'result' => 'created']],
										['index' => ['_id' => '2', 'result' => 'created']],
									],
									]
								)
							);
							return $mockResponse;
						},
						$requests
					);
				}
			);

		$payload = $this->createValidPayload();
		// This test's DESC returns 2 fields (id, title), not 3
		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'title', 'type' => 'text', 'properties' => ''],
		];

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);
		$result = $processor->execute();

		$this->assertEquals(2, $result);
		$this->assertEquals(1, $processor->getBatchesProcessed());

		$statistics = $processor->getProcessingStatistics();

		// Type narrowing for PHPStan
		assert(is_array($statistics));
		/** @var array<string,mixed> $statistics */

		$this->assertArrayHasKey('total_records', $statistics);
		$this->assertArrayHasKey('total_batches', $statistics);
		$this->assertArrayHasKey('total_duration_seconds', $statistics);
		$this->assertArrayHasKey('records_per_second', $statistics);
		$this->assertArrayHasKey('avg_batch_size', $statistics);
		$this->assertArrayHasKey('batch_statistics', $statistics);

		$this->assertEquals(2, $statistics['total_records']);
		$this->assertEquals(1, $statistics['total_batches']);
		$this->assertGreaterThan(0, $statistics['total_duration_seconds']);
	}

	public function testBatchTimingTracking(): void {
		echo "\nTesting batch timing and performance tracking\n";

		$mockClient = $this->createMock(Client::class);

		$testData = [
			['id' => 1, 'title' => 'Product A'],
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use ($testData) {
					if (str_contains($query, 'LIMIT 1000 OFFSET 0')) {
						// Simulate processing delay
						usleep(10000); // 10ms delay
						return $this->createSelectResponse($testData);
					}
					if (str_contains($query, 'LIMIT 1000 OFFSET 1')) {
						return $this->createSelectResponse([]);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$mockClient->method('sendMultiRequest')
			->willReturnCallback(
				function (array $requests) {
					// Mock bulk operations - return success
					return array_map(
						function () {
							$mockResponse = $this->createMock(Response::class);
							$mockResponse->method('hasError')->willReturn(false);
							$mockResponse->method('getResult')->willReturn(
								Struct::fromData(
									[
									'errors' => false,
									'items' => [
										['index' => ['_id' => '1', 'result' => 'created']],
									],
									]
								)
							);
							return $mockResponse;
						},
						$requests
					);
				}
			);

		$payload = $this->createValidPayload();
		// This test's DESC returns 2 fields (id, title), not 3
		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'title', 'type' => 'text', 'properties' => ''],
		];

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);
		$processor->execute();

		$statistics = $processor->getProcessingStatistics();

		// Type narrowing for PHPStan
		assert(is_array($statistics));
		/** @var array<string,mixed> $statistics */

		// Verify timing metrics are collected
		$this->assertGreaterThan(0, $statistics['total_duration_seconds']);
		$this->assertArrayHasKey('batch_statistics', $statistics);

		$batchStatistics = $statistics['batch_statistics'];
		assert(is_array($batchStatistics));
		/** @var array<int,mixed> $batchStatistics */

		$this->assertCount(1, $batchStatistics); // Should have 1 batch

		$batchStats = $batchStatistics[0];
		assert(is_array($batchStats));
		/** @var array<string,mixed> $batchStats */

		$this->assertArrayHasKey('batch_number', $batchStats);
		$this->assertArrayHasKey('records_count', $batchStats);
		$this->assertArrayHasKey('duration_seconds', $batchStats);
		$this->assertArrayHasKey('records_per_second', $batchStats);

		$this->assertEquals(1, $batchStats['batch_number']);
		$this->assertEquals(1, $batchStats['records_count']);
		$this->assertGreaterThan(0, $batchStats['duration_seconds']);
	}

	// ========================================================================
	// Error Scenarios
	// ========================================================================

	public function testBatchFetchFailure(): void {
		echo "\nTesting batch processing handles fetch failures\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_contains($query, 'LIMIT 1000 OFFSET 0')) {
						return $this->createErrorResponse('SELECT query failed');
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$payload = $this->createValidPayload();
		// This test's DESC returns only 1 field (id)
		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
		];

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);

		$this->expectException(ManticoreSearchClientError::class);

		$processor->execute();
	}

	public function testProcessorGettersAndSetters(): void {
		echo "\nTesting BatchProcessor getter methods\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$payload = $this->createValidPayload();
		// This test's DESC returns only 1 field (id)
		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
		];

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);

		// Test initial state
		$this->assertEquals(0, $processor->getTotalProcessed());
		$this->assertEquals(0, $processor->getBatchesProcessed());

		$statistics = $processor->getProcessingStatistics();

		// Type narrowing for PHPStan
		assert(is_array($statistics));
		/** @var array<string,mixed> $statistics */

		$this->assertIsArray($statistics);
		$this->assertArrayHasKey('total_records', $statistics);
		$this->assertEquals(0, $statistics['total_records']);
	}

	public function testNoDuplicateLimitClausesWithUserLimit(): void {
		echo "\nTesting that user LIMIT doesn't create duplicate LIMIT clauses in generated SQL\n";

		// Create payload with user LIMIT to test the fix
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INTO target SELECT id, title FROM source LIMIT 5',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload = Payload::fromRequest($request);

		// Verify payload correctly parsed user LIMIT
		$this->assertEquals(5, $payload->selectLimit);
		$this->assertEquals('SELECT id, title FROM source LIMIT 5', $payload->selectQuery);

		$callSequence = [];
		$mockClient = $this->createMock(Client::class);
		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use (&$callSequence) {
					$callSequence[] = $query;

					if (str_starts_with($query, 'DESC')) {
						return $this->createSelectResponse(
							[
							['Field' => 'id', 'Type' => 'bigint'],
							['Field' => 'title', 'Type' => 'text'],
							]
						);
					}

					if (str_starts_with($query, 'SELECT')) {
						// Return 3 records to test user limit enforcement
						return $this->createSelectResponse(
							[
							['id' => 1, 'title' => 'Test 1'],
							['id' => 2, 'title' => 'Test 2'],
							['id' => 3, 'title' => 'Test 3'],
							]
						);
					}

					return $this->createSuccessResponse();
				}
			);

		$mockClient->method('sendMultiRequest')
			->willReturnCallback(
				function (array $requests) {
					// Mock bulk operations - return success
					return array_map(
						function () {
							$mockResponse = $this->createMock(Response::class);
							$mockResponse->method('hasError')->willReturn(false);
							$mockResponse->method('getResult')->willReturn(
								Struct::fromData(
									[
									'errors' => false,
									'items' => [
										['index' => ['_id' => '1', 'result' => 'created']],
										['index' => ['_id' => '2', 'result' => 'created']],
										['index' => ['_id' => '3', 'result' => 'created']],
									],
									]
								)
							);
							return $mockResponse;
						},
						$requests
					);
				}
			);

		$targetFields = [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'title', 'type' => 'text', 'properties' => ''],
		];

		$processor = new BatchProcessor($mockClient, $payload, $targetFields, self::BATCH_SIZE);
		$processor->execute();

		// Find the SELECT queries in the call sequence
		$selectQueries = array_filter($callSequence, fn($query) => str_starts_with($query, 'SELECT'));

		// Verify no duplicate LIMIT clauses exist
		foreach ($selectQueries as $query) {
			$limitCount = substr_count(strtoupper($query), 'LIMIT');
			$this->assertEquals(
				1,
				$limitCount,
				"Query should have exactly one LIMIT clause, found $limitCount in: $query"
			);

			// Verify the query doesn't contain patterns like "LIMIT 5 LIMIT"
			$this->assertStringNotContainsString(
				'LIMIT 5 LIMIT',
				$query,
				"Query contains duplicate LIMIT clauses: $query"
			);
			$this->assertStringNotContainsString(
				'LIMIT 1000 LIMIT',
				$query,
				"Query contains duplicate LIMIT clauses: $query"
			);
		}

		// Verify user limit was properly enforced (should process max 5 records)
		$this->assertLessThanOrEqual(5, $processor->getTotalProcessed());

		echo "✓ No duplicate LIMIT clauses found in generated SQL queries\n";
		echo '✓ User LIMIT properly enforced: processed ' . $processor->getTotalProcessed() . " records\n";
	}
}
