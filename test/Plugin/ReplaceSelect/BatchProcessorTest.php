<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

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
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use Manticoresearch\BuddyTest\Trait\ReplaceSelectTestTrait;
use PHPUnit\Framework\TestCase;

class BatchProcessorTest extends TestCase {

	use TestProtectedTrait;
	use ReplaceSelectTestTrait;

	/**
	 * Create a valid payload for testing
	 */
	private function createValidPayload(array $overrides = []): Payload {
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => $overrides['query'] ?? 'REPLACE INTO target SELECT id, title, price FROM source',
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

	/**
	 * Create standard target fields for testing
	 */
	private function createTargetFields(): array {
		return [
			'id' => ['type' => 'bigint', 'properties' => ''],
			'title' => ['type' => 'text', 'properties' => 'stored'],
			'price' => ['type' => 'float', 'properties' => ''],
			'is_active' => ['type' => 'bool', 'properties' => ''],
			'count_value' => ['type' => 'int', 'properties' => ''],
		];
	}

	/**
	 * Create a mock response for SELECT queries
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





	// ========================================================================
	// Batch Execution Tests
	// ========================================================================

	public function testExecuteWithMultipleBatches(): void {
		echo "\nTesting batch processing with multiple batches\n";

		// Set batch size to 2 for this test
		$originalBatchSize = $_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] ?? null;
		$_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] = 2;

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
					if (str_starts_with($query, 'REPLACE INTO')) {
						return $this->createSuccessResponse();
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

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields);
		$result = $processor->execute();

		$this->assertEquals(3, $result); // Total records processed
		$this->assertEquals(2, $processor->getBatchesProcessed()); // 2 batches (batch1: 2 records, batch2: 1 record)

		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields);
		$result = $processor->execute();

		$this->assertEquals(3, $result); // Total records processed
		$this->assertEquals(2, $processor->getBatchesProcessed()); // 2 batches (batch1: 2 records, batch2: 1 record)

		$this->assertContains('SELECT id, title, price FROM source LIMIT 2 OFFSET 0', $callSequence);
		$this->assertContains('SELECT id, title, price FROM source LIMIT 2 OFFSET 2', $callSequence);

		// Restore original environment
		if ($originalBatchSize !== null) {
			$_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] = $originalBatchSize;
		} else {
			unset($_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE']);
		}
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
					if (str_starts_with($query, 'REPLACE INTO')) {
						return $this->createSuccessResponse();
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

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields);
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

		$processor = new BatchProcessor($mockClient, $payload, $targetFields);
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

		$processor = new BatchProcessor($mockClient, $payload, $targetFields);
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
							['Field' => 'count_value', 'Type' => 'int'],
							]
						);
					}
					return $this->createSuccessResponse();
				}
			);

		$targetFields = [
			'id' => ['type' => 'bigint', 'properties' => ''],
			'title' => ['type' => 'text', 'properties' => 'stored'],
			'price' => ['type' => 'float', 'properties' => ''],
			'is_active' => ['type' => 'bool', 'properties' => ''],
			'count_value' => ['type' => 'int', 'properties' => ''],
		];

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields);

		$testRow = [
			'id' => 1,
			'title' => 'Product A',
			'price' => 99.99,
			'is_active' => 1,
			'count_value' => 100,
		];

		$processedRow = self::invokeMethod($processor, 'processRow', [$testRow]);

		$this->assertArrayHasKey('id', $processedRow);
		$this->assertArrayHasKey('title', $processedRow);
		$this->assertArrayHasKey('price', $processedRow);
		$this->assertArrayHasKey('is_active', $processedRow);
		$this->assertArrayHasKey('count_value', $processedRow);
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
		$targetFields = $this->createTargetFields();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields);

		$testRowWithoutId = [
			'title' => 'Product A',
			'price' => 99.99,
		];

		$this->expectException(ManticoreSearchClientError::class);
		$this->expectExceptionMessage("Row missing required 'id' field");

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
			'id' => ['type' => 'bigint', 'properties' => ''],
			'title' => ['type' => 'text', 'properties' => 'stored'],
		];

		$payload = $this->createValidPayload();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields);

		$testRowWithUnknownField = [
			'id' => 1,
			'title' => 'Product A',
			'unknown_field' => 'should be skipped',
		];

		$processedRow = self::invokeMethod($processor, 'processRow', [$testRowWithUnknownField]);

		$this->assertArrayHasKey('id', $processedRow);
		$this->assertArrayHasKey('title', $processedRow);
		$this->assertArrayNotHasKey('unknown_field', $processedRow);
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
				function (string $query) use (&$capturedReplaceQuery) {
					if (str_starts_with($query, 'REPLACE INTO')) {
						$capturedReplaceQuery = $query;
						return $this->createSuccessResponse();
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
					return $this->createSuccessResponse();
				}
			);

		$payload = $this->createValidPayload();
		$targetFields = $this->createTargetFields();
		$processor = new BatchProcessor($mockClient, $payload, $targetFields);

		$testBatch = [
			['id' => 1, 'title' => 'Product A', 'price' => 99.99],
			['id' => 2, 'title' => 'Product B', 'price' => 29.99],
		];

		self::invokeMethod($processor, 'executeReplaceBatch', [$testBatch]);

		$this->assertNotNull($capturedReplaceQuery);
		$this->assertStringContainsString('REPLACE INTO', $capturedReplaceQuery);
		$this->assertStringContainsString('VALUES', $capturedReplaceQuery);
		$this->assertStringContainsString('Product A', $capturedReplaceQuery);
		$this->assertStringContainsString('Product B', $capturedReplaceQuery);
	}

	public function testExecuteReplaceBatchWithSpecialCharacters(): void {
		echo "\nTesting REPLACE query handles special characters correctly\n";

		$mockClient = $this->createMock(Client::class);

		$capturedReplaceQuery = null;
		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) use (&$capturedReplaceQuery) {
					if (str_starts_with($query, 'REPLACE INTO')) {
						$capturedReplaceQuery = $query;
						return $this->createSuccessResponse();
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
		$processor = new BatchProcessor($mockClient, $payload, $targetFields);

		$testBatchWithSpecialChars = [
			['id' => 1, 'title' => "Product with 'quotes' and \"double quotes\""],
			['id' => 2, 'title' => 'Product with unicode: é, à, ñ'],
		];

		self::invokeMethod($processor, 'executeReplaceBatch', [$testBatchWithSpecialChars]);

		$this->assertNotNull($capturedReplaceQuery);
		// Should contain escaped/handled special characters
		$this->assertStringContainsString('REPLACE INTO', $capturedReplaceQuery);
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
		$processor = new BatchProcessor($mockClient, $payload, $targetFields);

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
		$processor = new BatchProcessor($mockClient, $payload, $targetFields);

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
					if (str_starts_with($query, 'REPLACE INTO')) {
						return $this->createSuccessResponse();
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

		$processor = new BatchProcessor($mockClient, $payload, $targetFields);
		$result = $processor->execute();

		$this->assertEquals(2, $result);
		$this->assertEquals(1, $processor->getBatchesProcessed());

		$statistics = $processor->getProcessingStatistics();
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
					if (str_starts_with($query, 'REPLACE INTO')) {
						return $this->createSuccessResponse();
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

		$processor = new BatchProcessor($mockClient, $payload, $targetFields);
		$result = $processor->execute();

		$statistics = $processor->getProcessingStatistics();

		// Verify timing metrics are collected
		$this->assertGreaterThan(0, $statistics['total_duration_seconds']);
		$this->assertArrayHasKey('batch_statistics', $statistics);
		$this->assertCount(1, $statistics['batch_statistics']); // Should have 1 batch

		$batchStats = $statistics['batch_statistics'][0];
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
		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields);

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
		$targetFields = $this->createTargetFields();

		$processor = new BatchProcessor($mockClient, $payload, $targetFields);

		// Test initial state
		$this->assertEquals(0, $processor->getTotalProcessed());
		$this->assertEquals(0, $processor->getBatchesProcessed());

		$statistics = $processor->getProcessingStatistics();
		$this->assertIsArray($statistics);
		$this->assertArrayHasKey('total_records', $statistics);
		$this->assertEquals(0, $statistics['total_records']);
	}
}
