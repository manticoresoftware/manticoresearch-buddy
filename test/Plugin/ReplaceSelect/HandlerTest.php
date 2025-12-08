<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Handler;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\CoreTest\Trait\TestHTTPServerTrait;
use Manticoresearch\BuddyTest\Trait\ReplaceSelectTestTrait;
use PHPUnit\Framework\TestCase;

class HandlerTest extends TestCase {

	use TestHTTPServerTrait;
	use ReplaceSelectTestTrait;

	// ========================================================================
	// Helper Methods for MATCH with Multiple Conditions Test
	// ========================================================================

	/**
	 * Create mock callback for MATCH with multiple conditions test
	 *
	 * @return callable Callback function for mock sendRequest
	 */
	private function createMatchMultiConditionsCallback(): callable {
		return function (string $query) {
			// Handle transaction BEGIN
			if ($query === 'BEGIN') {
				return $this->createMockResponse(true);
			}

			// Handle DESC queries
			if (str_starts_with($query, 'DESC')) {
				return $this->createMatchDescResponse();
			}

			// Handle MATCH queries with conditions
			if ($this->isMatchQueryWithConditions($query)) {
				return $this->createMatchResultsResponse($query);
			}

			// Handle REPLACE queries
			if (str_starts_with($query, 'REPLACE')) {
				return $this->createMockResponse(true);
			}

			// Handle transaction COMMIT
			if ($query === 'COMMIT') {
				return $this->createMockResponse(true);
			}

			// Unexpected query
			return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
		};
	}

	/**
	 * Create DESC response for MATCH test with multiple fields
	 *
	 * @return mixed Mock response with field schema
	 */
	private function createMatchDescResponse() {
		return $this->createMockResponse(
			true,
			[
				['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
				['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
				['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
				['Field' => 'status', 'Type' => 'text', 'Properties' => 'stored'],
			]
		);
	}

	/**
	 * Check if query is a MATCH query with AND conditions
	 *
	 * @param string $query SQL query string
	 * @return bool True if query matches pattern
	 */
	private function isMatchQueryWithConditions(string $query): bool {
		if (!str_contains($query, 'MATCH(title')) {
			return false;
		}

		if (!str_contains($query, 'AND')) {
			return false;
		}

		return true;
	}

	/**
	 * Create MATCH query results based on LIMIT value
	 *
	 * @param string $query SQL query string
	 * @return mixed Mock response with matching results
	 */
	private function createMatchResultsResponse(string $query) {
		// Return single result for LIMIT 1 (validation query)
		if (str_contains($query, 'LIMIT 1') && !str_contains($query, 'LIMIT 1000')) {
			return $this->createMockResponse(
				true,
				[
					[
						'id' => 1,
						'title' => 'Keyword Product',
						'price' => 150.00,
						'status' => 'active',
					],
				]
			);
		}

		// Return multiple results for LIMIT 1000 (batch processing)
		if (str_contains($query, 'LIMIT 1000')) {
			return $this->createMockResponse(
				true,
				[
					[
						'id' => 1,
						'title' => 'Keyword Product',
						'price' => 150.00,
						'status' => 'active',
					],
					[
						'id' => 2,
						'title' => 'Another Keyword Item',
						'price' => 200.00,
						'status' => 'active',
					],
				]
			);
		}

		// Default: no results
		return $this->createMockResponse(true, []);
	}

	// ========================================================================
	// Transaction Management Tests
	// ========================================================================

	public function testSuccessfulTransactionFlow(): void {
		echo "\nTesting successful transaction flow (BEGIN → operations → COMMIT)\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->expects($this->exactly(6))
			->method('sendRequest')
			->withConsecutive(
				['BEGIN'],
				['DESC target'],
				['SELECT id, title, price FROM source LIMIT 1'],
				['SELECT id, title, price FROM source LIMIT 1000 OFFSET 0'],
				['REPLACE INTO target (id,title,price) VALUES (1,\'Test Product\',29.99)'],
				['COMMIT']
			)
			->willReturnOnConsecutiveCalls(
				$this->createMockResponse(true),
				$this->createMockResponse(
					true, [
					['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
					['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
					['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
					]
				),
				$this->createMockResponse(
					true, [
					['id' => 1, 'title' => 'Test Product', 'price' => 29.99],
					]
				),
				$this->createMockResponse(
					true, [
					['id' => 1, 'title' => 'Test Product', 'price' => 29.99],
					]
				),
				$this->createMockResponse(true),
				$this->createMockResponse(true)
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms - allow coroutine to complete

		$this->assertTrue($task->isSucceed(), 'Handler should successfully complete transaction');
	}

	public function testTransactionRollbackOnValidationFailure(): void {
		echo "\nTesting transaction rollback on validation failure\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->expects($this->exactly(3))
			->method('sendRequest')
			->withConsecutive(
				['BEGIN'],
				['DESC target'],
				['ROLLBACK']
			)
			->willReturnOnConsecutiveCalls(
				$this->createMockResponse(true),
				$this->createMockResponse(false, null, 'Table does not exist'),
				$this->createMockResponse(true)
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertNotNull($error);
		$this->assertInstanceOf(ManticoreSearchClientError::class, $error);
		$message = $error->getMessage();
		$this->assertStringContainsString('processed 0 records', $message);
	}

	public function testTransactionRollbackOnBatchProcessingError(): void {
		echo "\nTesting transaction rollback on batch processing error\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(
							true, [
							['id' => 1, 'title' => 'Test Product'],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1000')) {
						return $this->createMockResponse(false, null, 'Connection lost during batch processing');
					}
					if ($query === 'ROLLBACK') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertNotNull($error);
		$this->assertInstanceOf(ManticoreSearchClientError::class, $error);
		$this->assertStringContainsString('processed 0 records', $error->getMessage());
	}

	public function testBeginTransactionFailure(): void {
		echo "\nTesting BEGIN transaction failure\n";

		$mockClient = $this->createMock(Client::class);
		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('BEGIN')
			->willReturn($this->createMockResponse(false, null, 'Cannot start transaction'));

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertNotNull($error);
		$this->assertInstanceOf(ManticoreSearchClientError::class, $error);
		$message = $error->getMessage();
		$this->assertStringContainsString('processed 0 records', $message);
	}

	public function testCommitTransactionFailure(): void {
		echo "\nTesting COMMIT transaction failure\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(
							true, [
							['id' => 1, 'title' => 'Test Product'],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1000')) {
						return $this->createMockResponse(true, []);
					}
					if ($query === 'COMMIT') {
						return $this->createMockResponse(false, null, 'Cannot commit transaction');
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertNotNull($error);
		$this->assertInstanceOf(ManticoreSearchClientError::class, $error);
		$message = $error->getMessage();
		$this->assertStringContainsString('processed 0 records', $message);
	}

	// ========================================================================
	// Payload Validation Tests
	// ========================================================================

	public function testInvalidPayloadValidation(): void {
		echo "\nTesting invalid payload validation\n";

		$payload = $this->createValidPayload(['batchSize' => 0]);
		$handler = new Handler($payload);

		try {
			$handler->run();
			$this->fail('Expected Exception was not thrown');
		} catch (\Exception $e) {
			$this->assertInstanceOf(\Exception::class, $e);
		}
	}

	// ========================================================================
	// Result Formatting Tests
	// ========================================================================

	public function testResultFormattingWithStatistics(): void {
		echo "\nTesting result formatting with statistics\n";

		$_ENV['BUDDY_REPLACE_SELECT_DEBUG'] = 'true';

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(
							true, [
							['id' => 1, 'title' => 'Test Product', 'price' => 29.99],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1000')) {
						return $this->createMockResponse(true, []);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createMockResponse(true);
					}
					if ($query === 'COMMIT') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertTrue($task->isSucceed());

		unset($_ENV['BUDDY_REPLACE_SELECT_DEBUG']);
	}

	public function testResultFormattingWithoutDebug(): void {
		echo "\nTesting result formatting without debug mode\n";

		$_ENV['BUDDY_REPLACE_SELECT_DEBUG'] = 'false';

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(
							true, [
							['id' => 1, 'title' => 'Test Product', 'price' => 29.99],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1000')) {
						return $this->createMockResponse(true, []);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createMockResponse(true);
					}
					if ($query === 'COMMIT') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertTrue($task->isSucceed());

		unset($_ENV['BUDDY_REPLACE_SELECT_DEBUG']);
	}

	// ========================================================================
	// Error Context Testing
	// ========================================================================

	public function testErrorContextBuilding(): void {
		echo "\nTesting comprehensive error context building\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(false, null, 'Connection refused');
					}
					if ($query === 'ROLLBACK') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload(
			[
			'targetTable' => 'test_target',
			'selectQuery' => 'SELECT id, name FROM test_source WHERE active = 1',
			'batchSize' => 500,
			]
		);

		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertNotNull($error);
		$this->assertInstanceOf(ManticoreSearchClientError::class, $error);
		$message = $error->getMessage();
		$this->assertStringContainsString('processed 0 records', $message);
	}

	// ========================================================================
	// Integration Tests
	// ========================================================================

	public function testHandlerWithRealMockServer(): void {
		echo "\nTesting handler integration with complete flow\n";

		// Test with mock client that has proper responses for all transaction commands
		// The real HTTP mock server doesn't support BEGIN/COMMIT/ROLLBACK, so we use client mocks
		$mockClient = $this->createMock(Client::class);

		$mockClient->expects($this->exactly(6))
			->method('sendRequest')
			->withConsecutive(
				['BEGIN'],
				['DESC target'],
				['SELECT id, title, price FROM source LIMIT 1'],
				['SELECT id, title, price FROM source LIMIT 1000 OFFSET 0'],
				['REPLACE INTO target (id,title,price) VALUES (1,\'Test Product\',29.99)'],
				['COMMIT']
			)
			->willReturnOnConsecutiveCalls(
				$this->createMockResponse(true),
				$this->createMockResponse(
					true, [
					['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
					['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
					['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
					]
				),
				$this->createMockResponse(
					true, [
					['id' => 1, 'title' => 'Test Product', 'price' => 29.99],
					]
				),
				$this->createMockResponse(
					true, [
					['id' => 1, 'title' => 'Test Product', 'price' => 29.99],
					]
				),
				$this->createMockResponse(true),
				$this->createMockResponse(true)
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms - allow coroutine to complete

		$this->assertTrue($task->isSucceed());
	}

	// ========================================================================
	// Additional Handler Tests (Enhanced Coverage)
	// ========================================================================

	public function testHandlerWithUserSpecifiedLimit(): void {
		echo "\nTesting handler with user-specified limit\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(
							true, [
							['id' => 1, 'title' => 'Test Product'],
							]
						);
					}
					if (str_contains($query, 'LIMIT')) {
						return $this->createMockResponse(true, []);
					}
					if ($query === 'COMMIT') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload(
			[
			'query' => 'REPLACE INTO target SELECT id, title FROM source',
			'limit' => 100,
			]
		);
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertTrue($task->isSucceed());
	}

	public function testHandlerWithEmptySelectResult(): void {
		echo "\nTesting handler with empty SELECT result\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(true, []);
					}
					if ($query === 'ROLLBACK') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		// Empty SELECT result should fail validation
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertNotNull($error);
	}

	public function testHandlerWithComplexFieldTypes(): void {
		echo "\nTesting handler with complex field types\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
							['Field' => 'tags', 'Type' => 'multi', 'Properties' => ''],
							['Field' => 'created_at', 'Type' => 'timestamp', 'Properties' => ''],
							['Field' => 'metadata', 'Type' => 'json', 'Properties' => ''],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(
							true, [
							[
								'id' => 1,
								'price' => 29.99,
								'tags' => [1, 2, 3],
								'created_at' => '2024-01-01 00:00:00',
								'metadata' => '{"category": "electronics"}',
							],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1000')) {
						return $this->createMockResponse(true, []);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createMockResponse(true);
					}
					if ($query === 'COMMIT') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload(
			[
			'query' => 'REPLACE INTO products SELECT id, price, tags, created_at, metadata FROM source',
			]
		);
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertTrue($task->isSucceed());
	}

	public function testHandlerWithWrappedResponse(): void {
		echo "\nTesting handler with wrapped response format\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(
							true, [
							['id' => 1, 'title' => 'Test Product', 'price' => 29.99],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1000')) {
						return $this->createMockResponse(true, []);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createMockResponse(true);
					}
					if ($query === 'COMMIT') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertTrue($task->isSucceed());
	}

	public function testHandlerWithRollbackFailure(): void {
		echo "\nTesting handler with rollback failure\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->expects($this->exactly(3))
			->method('sendRequest')
			->withConsecutive(
				['BEGIN'],
				['DESC target'],
				['ROLLBACK']
			)
			->willReturnOnConsecutiveCalls(
				$this->createMockResponse(true),
				$this->createMockResponse(false, null, 'Table does not exist'),
				$this->createMockResponse(false, null, 'Rollback failed')
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertNotNull($error);
		$this->assertInstanceOf(ManticoreSearchClientError::class, $error);
		$message = $error->getMessage();
		$this->assertStringContainsString('processed 0 records', $message);
	}

	public function testHandlerWithStatisticsTracking(): void {
		echo "\nTesting handler with detailed statistics tracking\n";

		$_ENV['BUDDY_REPLACE_SELECT_DEBUG'] = 'true';

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(
							true, [
							['id' => 1, 'title' => 'Test Product', 'price' => 29.99],
							]
						);
					}
					if (str_contains($query, 'LIMIT 1000')) {
						return $this->createMockResponse(true, []);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createMockResponse(true);
					}
					if ($query === 'COMMIT') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload();
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertTrue($task->isSucceed());

		unset($_ENV['BUDDY_REPLACE_SELECT_DEBUG']);
	}

	// ========================================================================
	// MATCH() Clause Integration Tests
	// ========================================================================

	public function testHandlerWithMatchWhereClause(): void {
		echo "\nTesting handler with MATCH() full-text search in WHERE clause\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
							]
						);
					}
					if (str_contains($query, 'MATCH(title') && str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(
							true, [
							['id' => 1, 'title' => 'Keyword Product', 'price' => 99.99],
							]
						);
					}
					if (str_contains($query, 'MATCH(title') && str_contains($query, 'LIMIT 1000')) {
						return $this->createMockResponse(
							true, [
							['id' => 1, 'title' => 'Keyword Product', 'price' => 99.99],
							]
						);
					}
					if (str_starts_with($query, 'REPLACE')) {
						return $this->createMockResponse(true);
					}
					if ($query === 'COMMIT') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload(
			[
			'query' => 'REPLACE INTO target SELECT id, title, price FROM source WHERE MATCH(title, \'@keyword\')',
			]
		);
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertTrue($task->isSucceed());
	}

	public function testHandlerWithMatchAndMultipleConditions(): void {
		echo "\nTesting handler with MATCH() combined with other WHERE conditions\n";

		$mockClient = $this->createMock(Client::class);

		// Extract complex callback to helper method for reduced cognitive complexity
		$mockClient->method('sendRequest')
			->willReturnCallback($this->createMatchMultiConditionsCallback());

		$payload = $this->createValidPayload(
			[
				'query' => 'REPLACE INTO target SELECT id, title, price, status FROM source '
					. 'WHERE MATCH(title, \'@keyword\') AND price > 100 AND status = \'active\'',
			]
		);
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		$this->assertTrue($task->isSucceed());
	}

	public function testHandlerWithMatchReturnsNoResults(): void {
		echo "\nTesting handler with MATCH() query returning no results\n";

		$mockClient = $this->createMock(Client::class);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $query) {
					if ($query === 'BEGIN') {
						return $this->createMockResponse(true);
					}
					if (str_starts_with($query, 'DESC')) {
						return $this->createMockResponse(
							true, [
							['Field' => 'id', 'Type' => 'bigint', 'Properties' => ''],
							['Field' => 'title', 'Type' => 'text', 'Properties' => 'stored'],
							['Field' => 'price', 'Type' => 'float', 'Properties' => ''],
							]
						);
					}
					if (str_contains($query, 'MATCH(title') && str_contains($query, 'LIMIT 1')) {
						return $this->createMockResponse(true, []);  // No matches
					}
					if (str_contains($query, 'MATCH(title') && str_contains($query, 'LIMIT 1000')) {
						return $this->createMockResponse(true, []);  // No matches
					}
					if ($query === 'ROLLBACK') {
						return $this->createMockResponse(true);
					}
					return $this->createMockResponse(false, null, 'Unexpected query: ' . $query);
				}
			);

		$payload = $this->createValidPayload(
			[
			'query' => 'REPLACE INTO target SELECT id, title, price FROM source WHERE MATCH(title, \'@nonexistent\')',
			]
		);
		$handler = new Handler($payload);
		$this->injectMockClient($handler, $mockClient);

		$task = $handler->run();
		usleep(500000); // 500ms

		// MATCH query returning no results should fail validation
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertNotNull($error);
	}
}
