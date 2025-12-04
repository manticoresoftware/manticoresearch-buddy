<?php declare(strict_types=1);

namespace Manticoresearch\BuddyTest\Trait;

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Payload;
use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Handler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use ReflectionClass;



/**
 * Common utilities for ReplaceSelect testing
 */
trait ReplaceSelectTestTrait {

	/**
	 * Create a mock ManticoreSearch client
	 */
	private function createMockClient(): Client {
		return $this->createMock(Client::class);
	}

	/**
	 * Create a mock response
	 */
	private function createMockResponse(bool $success = true, ?array $data = null, ?string $error = null): Response {
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(!$success);

		if ($error) {
			$response->method('getError')->willReturn($error);
			$response->method('getResult')->willReturn(Struct::fromData([]));
		}

		if ($data !== null) {
			$response->method('getResult')->willReturn(
				Struct::fromData([['data' => $data]])
			);
		}

		return $response;
	}



	/**
	 * Create a mock response for table operations (DESC, SHOW, etc.)
	 */
	private function createTableSchemaResponse(array $fields = null): Response {
		$response = $this->createMockResponse();
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(
			[
			[
				'data' => $fields,
			],
			]
		);

		return $response;
	}

	/**
	 * Create a mock response for batch operations
	 */
	private function createBatchResponse(array $rows = null, bool $wrapped = true): Response {
		$response = $this->createMockResponse();
		$response->method('hasError')->willReturn(!$wrapped);

		if ($wrapped) {
			$response->method('hasError')->willReturn(false);
			$response->method('getResult')->willReturn(
				[
				'data' => $rows,
				]
			);
		} else {
			$response->method('hasError')->willReturn(false);
			$response->method('getResult')->willReturn(
				[
				'data' => $rows,
				]
			);
		}

		return $response;
	}

	/**
	 * Create an error response
	 */
	private function createErrorResponse(string $errorMessage): Response {
		$response = $this->createMockResponse(false, null, $errorMessage);
		$response->method('hasError')->willReturn(true);
		$response->method('getError')->willReturn($errorMessage);
		$response->method('getResult')->willReturn([]);

		return $response;
	}

	/**
	 * Create a mock success response
	 */
	private function createSuccessResponse(): Response {
		return $this->createMockResponse(true);
	}

	/**
	 * Create a mock client with predefined responses
	 */
	private function createMockClientWithResponses(array $responses = []): Client {
		$client = $this->createMockClient();
		$callCount = 0;

		$client->method('sendRequest')
			->willReturnCallback(
				function (string $query) use (&$callCount) {
					$callCount++;

					if (isset($responses[$callCount])) {
						return $responses[$callCount - 1];
					}

					return $responses[$callCount - 1];
				}
			);

		return $client;
	}

	/**
	 * Inject a mock client into a handler
	 */
	private function injectMockClient(Handler $handler, Client $mockClient): void {
		$reflection = new ReflectionClass($handler);
		$property = $reflection->getProperty('manticoreClient');
		$property->setAccessible(true);
		$property->setValue($handler, $mockClient);
	}

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
	 * Create a payload with LIMIT for testing
	 */
	private function createPayloadWithLimit(int $limit, ?int $offset = null): Payload {
		$payload = $this->createValidPayload(['limit' => $limit, 'offset' => $offset]);
		return $payload;
	}

	/**
	 * Create a payload with complex SELECT query
	 */
	private function createPayloadWithComplexQuery(): Payload {
		$payload = $this->createValidPayload(
			[
			'query' => 'SELECT id, title, price FROM source WHERE active = 1 AND price > 50',
			]
		);
		return $payload;
	}

	/**
	 * Create a target fields array for testing
	 */
	private function createTargetFields(array $overrides = []): array {
		$defaultFields = [
			'id' => ['type' => 'bigint', 'properties' => ''],
			'title' => ['type' => 'text', 'properties' => 'stored'],
			'price' => ['type' => 'float', 'properties' => ''],
			'is_active' => ['type' => 'bool', 'properties' => ''],
			'count_value' => ['type' => 'int', 'properties' => ''],
			'tags' => ['type' => 'text', 'properties' => 'stored'],
			'mva_tags' => ['type' => 'multi', 'properties' => ''],
			'json_data' => ['type' => 'text', 'properties' => 'stored'],
		];

		return array_merge($defaultFields, $overrides);
	}

	/**
	 * Create a complex field schema for testing
	 */
	private function createComplexFieldSchema(): array {
		return [
			'id' => ['type' => 'bigint', 'properties' => ''],
			'title' => ['type' => 'text', 'properties' => 'stored'],
			'price' => ['type' => 'float', 'properties' => ''],
			'is_active' => ['type' => 'bool', 'properties' => ''],
			'count_value' => ['type' => 'int', 'properties' => ''],
			'tags' => ['type' => 'text', 'properties' => 'stored'],
			'mva_tags' => ['type' => 'multi', 'properties' => ''],
			'json_data' => ['type' => 'text', 'properties' => 'stored'],
			'created_at' => ['type' => 'timestamp', 'properties' => ''],
			'updated_at' => ['type' => 'timestamp', 'properties' => ''],
		];
	}

	/**
	 * Generate test data rows with various field types
	 */
	private function generateTestRows(int $count = 5, array $fieldTypes = []): array {
		$rows = [];

		for ($i = 0; $i < $count; $i++) {
			$row = [];

			foreach ($fieldTypes as $field => $type) {
				switch ($field) {
					case 'int':
						$row['id'] = $i + 1000;
						break;
					case 'float':
						$row['price'] = ($i + 1) * 99.99;
						break;
					case 'bool':
						$row['is_active'] = ($i % 2) === 0;
						break;
					case 'text':
						$row['title'] = "Test Product $i";
						break;
					case 'timestamp':
						$row['created_at'] = time() + ($i * 86400);
						break;
					case 'json':
						$row['json_data'] = '{"key": "value", "nested": true}';
						break;
					default:
						$row[$field] = "Default value $i";
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Generate test rows with user-specified limits
	 */
	private function generateRowsWithLimits(int $totalRows, int $limit, int $offset = 0): array {
		$rows = [];

		for ($i = $offset; $i < $totalRows; $i++) {
			$rows[] = [
				'id' => $i + $offset,
				'title' => 'Test Product ' . ($i + $offset),
				'price' => ($i + $offset) * 1.99,
			];
		}

		return $rows;
	}

	/**
	 * Assert that statistics match expected values
	 */
	private function assertProcessingStatistics(array $stats, int $expectedRecords, int $expectedBatches): void {
		$this->assertEquals($expectedRecords, $stats['total_records']);
		$this->assertEquals($expectedBatches, $stats['total_batches']);
	}

	/**
	 * Assert that batch statistics contain expected values
	 */
	private function assertBatchStatistics(array $batchStats, int $batchNumber, int $recordCount): void {
		$this->assertEquals($recordCount, $batchStats['records_count']);
	}

	/**
	 * Assert that timing is reasonable
	 */
	private function assertBatchTiming(array $batchStats, int $batchNumber): void {
		$this->assertGreaterThan(0, $batchStats['duration_seconds']);
		$this->assertLessThan(10, $batchStats['duration_seconds']);
	}

	/**
	 * Assert that records per second is reasonable
	 */
	private function assertRecordsPerSecond(array $batchStats, int $batchNumber, int $recordCount): void {
		$this->assertGreaterThan(0, $batchStats['records_per_second']);
	}
}
