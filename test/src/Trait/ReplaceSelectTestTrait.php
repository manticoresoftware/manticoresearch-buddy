<?php declare(strict_types=1);

namespace Manticoresearch\BuddyTest\Trait;

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Handler;
use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Common utilities for ReplaceSelect testing
 */
trait ReplaceSelectTestTrait {

	/**
	 * Create a mock ManticoreSearch client
	 *
	 * @return Client&MockObject
	 */
	private function createMockClient(): Client {
		return $this->createMock(Client::class);
	}

	/**
	 * Create a mock response for table operations (DESC, SHOW, etc.)
	 *
	 * @param array<int,array{Field: string, Type: string, Properties: string}>|null $fields
	 */
	private function createTableSchemaResponse(?array $fields = null): Response {
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
	 * Create a mock response
	 *
	 * @param array<int,array<string,mixed>>|null $data
	 *
	 * @return Response&MockObject
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


	/**
	 * Create a target fields array for testing (position-indexed)
	 *
	 * @return array<int,array{name: string, type: string, properties: string}>
	 */
	private function createTargetFields(): array {
		return [
			['name' => 'id', 'type' => 'bigint', 'properties' => ''],
			['name' => 'title', 'type' => 'text', 'properties' => 'stored'],
			['name' => 'price', 'type' => 'float', 'properties' => ''],
			['name' => 'is_active', 'type' => 'bool', 'properties' => ''],
			['name' => 'count_value', 'type' => 'uint', 'properties' => ''],
			['name' => 'tags', 'type' => 'text', 'properties' => 'stored'],
			['name' => 'mva_tags', 'type' => 'multi', 'properties' => ''],
			['name' => 'json_data', 'type' => 'text', 'properties' => 'stored'],
		];
	}
}
