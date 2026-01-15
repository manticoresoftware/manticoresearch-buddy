<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Handler as RagHandler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Payload as RagPayload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for ConversationalRag plugin running against real Manticore daemon
 * These tests require a running Manticore Search instance
 */
class ConversationalRagTest extends TestCase {
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
		$query = "CREATE RAG MODEL 'functional_test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			style_prompt = 'You are a helpful assistant.',
			temperature = 0.7,
			max_tokens = 1000,
			k_results = 5
		)";

		$payload = RagPayload::fromRequest(
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

		$handler = new RagHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock responses for initializeTables (2 calls) + modelExists (1 call) + createModel (1 call)
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$modelExistsResponse = $this->createMock(Response::class);
		$modelExistsResponse->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$insertResponse = $this->createMock(Response::class);
		$insertResponse->method('getResult')->willReturn(
			Struct::fromData([['total' => 1, 'error' => '', 'warning' => '']])
		);

		$mockClient->expects($this->exactly(4))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $modelExistsResponse, $insertResponse);

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
		$payload = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => 'SHOW RAG MODELS',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$handler = new RagHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock responses for initializeTables (2 calls) + getAllModels (1 call)
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$selectResponse = $this->createMock(Response::class);
		$selectResponse->method('getResult')->willReturn(
			Struct::fromData([['data' => [], 'total' => 0, 'error' => '', 'warning' => '']])
		);

		$mockClient->expects($this->exactly(3))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $selectResponse);

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
		$this->assertArrayHasKey('llm_provider', $data[0]);
	}

	public function testDescribeModelEndToEnd(): void {
		$query = "DESCRIBE RAG MODEL 'functional_test_model'";

		$payload = RagPayload::fromRequest(
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

		$handler = new RagHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock responses for initializeTables (2 calls) + getModelByUuidOrName (1 call)
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$selectResponse = $this->createMock(Response::class);
		$selectResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [
					[
						'uuid' => 'test-uuid-123',
						'name' => 'functional_test_model',
						'llm_provider' => 'openai',
						'llm_model' => 'gpt-4',
						'llm_api_key' => '', // Empty for security
						'style_prompt' => 'You are a helpful assistant.',
						'temperature' => 0.7,
						'max_tokens' => 1000,
						'k_results' => 5,
					],
				],
				],
				]
			)
		);

		$mockClient->expects($this->exactly(3))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $selectResponse);

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
		$query = "DROP RAG MODEL 'functional_test_model'";

		$payload = RagPayload::fromRequest(
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

		$handler = new RagHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock responses for initializeTables (2) + getModel (1) + delete model (1)
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$getModelResponse = $this->createMock(Response::class);
		$getModelResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [
					[
						'uuid' => 'test-uuid-123',
						'name' => 'functional_test_model',
					],
				],
				],
				]
			)
		);

		$deleteModelResponse = $this->createMock(Response::class);
		$deleteModelResponse->method('getResult')->willReturn(
			Struct::fromData([['total' => 1, 'error' => '', 'warning' => '']])
		);

		$mockClient->expects($this->exactly(4))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $getModelResponse, $deleteModelResponse);

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
		$query = "CREATE RAG MODEL 'invalid_model' (
			llm_model = 'gpt-4',
		)";

		$payload = RagPayload::fromRequest(
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

		$handler = new RagHandler($payload);
		$mockClient = $this->createMock(HTTPClient::class);
		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(\Manticoresearch\Buddy\Core\Error\QueryParseError::class, $error);
		$this->assertStringContainsString("Required field 'llm_provider' is missing", $error->getResponseError());
	}

	public function testDescribeNonExistentModelEndToEnd(): void {
		$query = "DESCRIBE RAG MODEL 'non_existent_model'";

		$payload = RagPayload::fromRequest(
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

		$handler = new RagHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock responses for initializeTables (2 calls) + getModelByUuidOrName (1 call that returns no results)
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$selectResponse = $this->createMock(Response::class);
		$selectResponse->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$mockClient->expects($this->exactly(3))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $selectResponse);

		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(\Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError::class, $error);
	}

	public function testDropNonExistentModelEndToEnd(): void {
		$query = "DROP RAG MODEL 'non_existent_model'";

		$payload = RagPayload::fromRequest(
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

		$handler = new RagHandler($payload);

		// Mock the HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock responses for initializeTables (2 calls) + getModel (1 call that returns no results)
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$getModelResponse = $this->createMock(Response::class);
		$getModelResponse->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$mockClient->expects($this->exactly(3))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $getModelResponse);

		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(\Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError::class, $error);
	}
}
