<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Handler as ChatHandler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Payload as ChatPayload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for ConversationalSearch plugin running against real Manticore daemon
 * These tests require a running Manticore Search instance
 */
class ConversationalSearchTest extends TestCase {
	/**
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		if (getenv('SEARCHD_CONFIG')) {
			return;
		}
		if (!is_dir('/etc/manticore')) {
			mkdir('/etc/manticore', 0755, true);
		}
		touch('/etc/manticore/manticore.conf');
		putenv('SEARCHD_CONFIG=/etc/manticore/manticore.conf');

		// Set up test environment variables for API keys
		putenv('TEST_OPENAI_API_KEY=sk-test-key-12345678901234567890123456789012');
		putenv('TEST_ANTHROPIC_API_KEY=sk-ant-test123456789012345678901234567890');
	}

	public static function tearDownAfterClass(): void {
		// Clean up test environment variables
		putenv('TEST_OPENAI_API_KEY');
		putenv('TEST_ANTHROPIC_API_KEY');
	}

	public function testCreateModelEndToEnd(): void {
		$query = "CREATE CHAT MODEL 'functional_test_model' (
			model = 'openai:gpt-4',
			style_prompt = 'You are a helpful assistant.',
			retrieval_limit = 5
		)";

		$payload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$handler = new ChatHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);
		$modelExistsResponse = $this->createResponse([['data' => [['count' => 0]]]]);
		$insertResponse = $this->createResponse([['total' => 1, 'error' => '', 'warning' => '']]);
		$this->configureClientWithInitialization($mockClient, [$modelExistsResponse, $insertResponse]);

		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		if (!$task->isSucceed()) {
			$error = $task->getError();
			$this->fail('Task failed: ' . $error::class . ' - ' . $error->getResponseError());
		}

		$result = $task->getResult();
		$this->assertInstanceOf(TaskResult::class, $result);
		$struct = (array)$result->getStruct();
		$this->assertIsArray($struct);
		/** @var array<int, array{data: array<int, array{uuid: string}>}> $struct */
		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertCount(1, $struct[0]['data']);
		$this->assertArrayHasKey('uuid', $struct[0]['data'][0]);
		$this->assertIsString($struct[0]['data'][0]['uuid']);
	}

	public function testShowModelsEndToEnd(): void {
		$payload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => 'SHOW CHAT MODELS',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$handler = new ChatHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);
		$selectResponse = $this->createResponse([['data' => [], 'total' => 0, 'error' => '', 'warning' => '']]);
		$this->configureClientWithInitialization($mockClient, [$selectResponse]);

		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertTrue($task->isSucceed());
		$result = $task->getResult();

		$this->assertInstanceOf(TaskResult::class, $result);
		$struct = (array)$result->getStruct();
		$this->assertIsArray($struct);
		/** @var array<int, array{data?: array<int, array<string, mixed>>}> $struct */
		$this->assertCount(1, $struct);

		// For empty results, 'data' key may not be present in TaskResult
		if (!isset($struct[0]['data'])) {
			// Empty result case - no models found
			return;
		}

		$data = $struct[0]['data'];

		// Should return array of models, possibly empty if no models exist
		$this->assertIsArray($data);
		if (empty($data)) {
			return;
		}

		$this->assertArrayHasKey('uuid', $data[0]);
		$this->assertArrayHasKey('name', $data[0]);
		$this->assertArrayHasKey('model', $data[0]);
	}

	public function testDescribeModelEndToEnd(): void {
		$query = "DESCRIBE CHAT MODEL 'functional_test_model'";

		$payload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$handler = new ChatHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);
		$selectResponse = $this->createResponse(
			[[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [[
					'uuid' => 'test-uuid-123',
					'name' => 'functional_test_model',
					'model' => 'openai:gpt-4',
					'style_prompt' => 'You are a helpful assistant.',
					'settings' => '{"retrieval_limit":5,"max_document_length":2000}',
				]],
			]]
		);
		$this->configureClientWithInitialization($mockClient, [$selectResponse]);

		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertTrue($task->isSucceed());
		$result = $task->getResult();

		$this->assertInstanceOf(TaskResult::class, $result);
		/** @var array<int, array<string, mixed>> $struct */
		$struct = $result->getStruct();
		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);

		/** @var array<int, array<string, mixed>> $data */
		$data = $struct[0]['data'];
		$this->assertGreaterThan(0, sizeof($data));

		// Should have property-value pairs for the model description
		$this->assertEquals('uuid', $data[0]['property']);
		$this->assertIsString($data[0]['value']);
	}

	public function testDropModelEndToEnd(): void {
		$query = "DROP CHAT MODEL 'functional_test_model'";

		$payload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$handler = new ChatHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);
		$getModelResponse = $this->createResponse(
			[[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [[
					'id' => '1',
					'uuid' => 'test-uuid-123',
					'name' => 'functional_test_model',
					'description' => '',
					'model' => 'openai:gpt-4',
					'style_prompt' => '',
					'settings' => '{"max_document_length":2000}',
					'created_at' => '1234567890',
					'updated_at' => '1234567890',
				]],
			]]
		);
		$deleteModelResponse = $this->createResponse([['total' => 1, 'error' => '', 'warning' => '']]);
		$this->configureClientWithInitialization($mockClient, [$getModelResponse, $deleteModelResponse]);

		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertTrue($task->isSucceed());
		$result = $task->getResult();

		$this->assertInstanceOf(TaskResult::class, $result);
		/** @var array<int, array<string, mixed>> $struct */
		$struct = $result->getStruct();
		$this->assertCount(1, $struct);
		$this->assertEquals(0, $struct[0]['total']);
	}

	public function testCreateModelValidationErrorEndToEnd(): void {
		$query = "CREATE CHAT MODEL 'invalid_model' (
			model = 'gpt-4',
		)";

		$payload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$handler = new ChatHandler($payload);
		$mockClient = $this->createMock(HTTPClient::class);
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(\Manticoresearch\Buddy\Core\Error\QueryParseError::class, $error);
		$this->assertStringContainsString("model must use 'provider:model' format", $error->getResponseError());
	}

	public function testDescribeNonExistentModelEndToEnd(): void {
		$query = "DESCRIBE CHAT MODEL 'non_existent_model'";

		$payload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$handler = new ChatHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);
		$selectResponse = $this->createResponse([['total' => 0, 'error' => '', 'warning' => '']]);
		$this->configureClientWithInitialization($mockClient, [$selectResponse]);

		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(\Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError::class, $error);
	}

	public function testDropNonExistentModelEndToEnd(): void {
		$query = "DROP CHAT MODEL 'non_existent_model'";

		$payload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$handler = new ChatHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);
		$getModelResponse = $this->createResponse([['total' => 0, 'error' => '', 'warning' => '']]);
		$this->configureClientWithInitialization($mockClient, [$getModelResponse]);

		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(\Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError::class, $error);
	}

	/**
	 * @param array<int, array<string, mixed>> $result
	 */
	private function createResponse(
		array $result = [['total' => 0, 'error' => '', 'warning' => '']],
		bool $hasError = false
	): Response {
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn($hasError);
		$response->method('getResult')->willReturn(Struct::fromData($result));

		return $response;
	}

	/**
	 * @param MockObject&HTTPClient $mockClient
	 * @param array<int, Response> $extraResponses
	 */
	private function configureClientWithInitialization(HTTPClient $mockClient, array $extraResponses = []): void {
		$mockClient->expects($this->exactly(2 + sizeof($extraResponses)))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				$this->createResponse(),
				$this->createResponse(),
				...$extraResponses
			);
	}
}
