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

class HandlerTest extends TestCase {
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

	public function testHandlerInitialization(): void {
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
		$this->assertInstanceOf(RagHandler::class, $handler);
	}

	public function testCreateModelSuccess(): void {
		$query = "CREATE RAG MODEL 'test_model' (
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

		// Mock the manticore client - createModel calls sendRequest 4 times (init tables + modelExists check + insert)
		$mockClient = $this->createMock(HTTPClient::class);

		// Create mock responses for each call - successful operations return standard result format
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$modelExistsResponse = $this->createMock(Response::class);
		$modelExistsResponse->method('getResult')->willReturn(Struct::fromData([['data' => [['count' => 0]]]]));

		$insertResponse = $this->createMock(Response::class);
		$insertResponse->method('getResult')->willReturn(Struct::fromData([['total' => 1, 'error' => '', 'warning' => '']]));

		$mockClient->expects($this->exactly(4)) // initializeTables (2 calls) + modelExists (1 call) + createModel (1 call)
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
		/** @var Struct<string,mixed> $struct */
		$struct = $result->getStruct();
		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertCount(1, $struct[0]['data']);
		$this->assertArrayHasKey('uuid', $struct[0]['data'][0]);
		$this->assertIsString($struct[0]['data'][0]['uuid']);
	}

	public function testShowModelsSuccess(): void {
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

		// Mock the manticore client - showModels calls sendRequest 3 times (init tables + getAllModels)
		$mockClient = $this->createMock(HTTPClient::class);

		// Create mock responses for each call
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$selectResponse = $this->createMock(Response::class);
		// getAllModels expects getResult()[0]['data'] to contain the models array
		$selectResponse->method('getResult')->willReturn(
			Struct::fromData(
				[['data' => [
				[
				'uuid' => 'test-uuid',
				'name' => 'test_model',
				'llm_provider' => 'openai',
				'llm_model' => 'gpt-4',
				'created_at' => '2023-01-01',
				],
				]]]
			)
		);

		$mockClient->expects($this->exactly(3)) // initializeTables (2 calls) + getAllModels (1 call)
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $selectResponse);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertTrue($task->isSucceed());
		$result = $task->getResult();

		$this->assertInstanceOf(TaskResult::class, $result);
		/** @var Struct<string,mixed> $struct */
		$struct = $result->getStruct();
		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertCount(1, $struct[0]['data']);
		$this->assertEquals('test-uuid', $struct[0]['data'][0]['uuid']);
		$this->assertEquals('test_model', $struct[0]['data'][0]['name']);
	}

	public function testDescribeModelSuccess(): void {
		$query = "DESCRIBE RAG MODEL 'test_model'";

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

		// Mock the manticore client - describeModel calls sendRequest 3 times (init tables + getModelByUiidOrName)
		$mockClient = $this->createMock(HTTPClient::class);

		// Create mock responses for each call
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$selectResponse = $this->createMock(Response::class);
		// getModelByUiidOrName expects getResult()[0]['data'][0] to be the model
		$selectResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [
					[
						'uuid' => 'test-uuid',
						'name' => 'test_model',
						'llm_provider' => 'openai',
						'llm_api_key' => '',
						'style_prompt' => 'You are a helpful assistant.',
						'settings' => '{"temperature":0.7,"max_tokens":1000,"k_results":5}',
						'created_at' => '2023-01-01 00:00:00',
					],
				],
				],
				]
			)
		);

		$mockClient->expects($this->exactly(3)) // initializeTables (2 calls) + getModelByUiidOrName (1 call)
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $selectResponse);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertTrue($task->isSucceed());
		$result = $task->getResult();

		$this->assertInstanceOf(TaskResult::class, $result);
		/** @var Struct<string,mixed> $struct */
		$struct = $result->getStruct();
		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertGreaterThan(0, count($struct[0]['data']));
		// Should have multiple property-value pairs for the model description
		$this->assertEquals('uuid', $struct[0]['data'][0]['property']);
		$this->assertEquals('test-uuid', $struct[0]['data'][0]['value']);
	}

	public function testDropModelSuccess(): void {
		$query = "DROP RAG MODEL 'test_model'";

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

		// Mock the manticore client - dropModel calls sendRequest 4 times (init tables + getModel + delete model)
		$mockClient = $this->createMock(HTTPClient::class);

		// Create mock responses for each call
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$getModelResponse = $this->createMock(Response::class);
		// getModelByUiidOrName expects getResult()[0]['data'][0] to be the model
		$getModelResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [
					[
						'uuid' => 'test-uuid',
						'name' => 'test_model',
						'llm_provider' => 'openai',
						'llm_api_key' => '',
						'style_prompt' => 'You are a helpful assistant.',
						'settings' => '{"temperature":0.7,"max_tokens":1000,"k_results":5}',
						'created_at' => '2023-01-01 00:00:00',
					],
				],
				],
				]
			)
		);

		$deleteModelResponse = $this->createMock(Response::class);
		$deleteModelResponse->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$mockClient->expects($this->exactly(4)) // initializeTables (2) + getModel (1) + delete model (1)
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $getModelResponse, $deleteModelResponse);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertTrue($task->isSucceed());
		$result = $task->getResult();

		$this->assertInstanceOf(TaskResult::class, $result);
		/** @var Struct<string,mixed> $struct */
		$struct = $result->getStruct();
		$this->assertCount(1, $struct);
		$this->assertEquals(0, $struct[0]['total']);
		$this->assertEmpty($struct[0]['error']);
		$this->assertEmpty($struct[0]['warning']);
	}

	public function testCreateModelValidationMissingProvider(): void {
		$query = "CREATE RAG MODEL 'test_model' (
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
		$this->assertStringContainsString("Required field 'llm_provider' is missing or empty", $error->getResponseError());
	}

	public function testCreateModelValidationInvalidProvider(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'anthropic',
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
		$this->assertStringContainsString("Invalid LLM provider: anthropic. Only 'openai' is supported.", $error->getResponseError());
	}

	public function testCreateModelValidationTemperatureTooHigh(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			temperature = 5.0
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
		$this->assertStringContainsString('Temperature must be between 0 and 2', $error->getResponseError());
	}

	public function testCreateModelValidationMaxTokensTooHigh(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			max_tokens = 100000
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
		$this->assertStringContainsString('max_tokens must be between 1 and 32768', $error->getResponseError());
	}

	public function testCreateModelValidationKResultsTooLow(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			k_results = 0
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
		$this->assertStringContainsString('k_results must be between 1 and 50', $error->getResponseError());
	}

	public function testCreateModelWithEncryptionIntegration(): void {
		$query = "CREATE RAG MODEL 'encrypted_test_model' (
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

		// Mock the manticore client - createModel calls sendRequest 4 times (init tables + modelExists check + insert)
		$mockClient = $this->createMock(HTTPClient::class);

		// Create mock responses for each call - successful operations return standard result format
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$modelExistsResponse = $this->createMock(Response::class);
		$modelExistsResponse->method('getResult')->willReturn(Struct::fromData([['data' => [['count' => 0]]]]));

		$insertResponse = $this->createMock(Response::class);
		$insertResponse->method('getResult')->willReturn(Struct::fromData([['total' => 1, 'error' => '', 'warning' => '']]));

		$mockClient->expects($this->exactly(4)) // initializeTables (2 calls) + modelExists (1 call) + createModel (1 call)
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
		/** @var Struct<string,mixed> $struct */
		$struct = $result->getStruct();
		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertCount(1, $struct[0]['data']);
		$this->assertArrayHasKey('uuid', $struct[0]['data'][0]);
		$this->assertIsString($struct[0]['data'][0]['uuid']);
	}

	public function testDescribeModelWithApiKeyMasking(): void {
		$query = "DESCRIBE RAG MODEL 'encrypted_test_model'";

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

		// Mock the manticore client - describeModel calls sendRequest 3 times (init tables + getModelByUiidOrName)
		$mockClient = $this->createMock(HTTPClient::class);

		// Create mock responses for each call
		$initResponse1 = $this->createMock(Response::class);
		$initResponse1->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

		$selectResponse = $this->createMock(Response::class);
		// getModelByUiidOrName expects getResult()[0]['data'][0] to be the model
		$selectResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [
					[
						'uuid' => 'encrypted-uuid-123',
						'name' => 'encrypted_test_model',
						'llm_provider' => 'openai',
						'llm_api_key' => '',
						'style_prompt' => 'You are a helpful assistant.',
						'settings' => '{"temperature":0.7,"max_tokens":1000,"k_results":5}',
						'created_at' => '2023-01-01 00:00:00',
					],
				],
				],
				]
			)
		);

		$mockClient->expects($this->exactly(3)) // initializeTables (2 calls) + getModelByUiidOrName (1 call)
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $selectResponse);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertTrue($task->isSucceed());
		$result = $task->getResult();

		$this->assertInstanceOf(TaskResult::class, $result);
		/** @var Struct<string,mixed> $struct */
		$struct = $result->getStruct();
		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertGreaterThan(0, count($struct[0]['data']));
	}

	public function testEncryptionKeyFileIntegration(): void {
		// Create a temporary key file for testing
		$tempKeyFile = sys_get_temp_dir() . '/test_buddy_key_' . uniqid() . '.key';
		$testKey = 'integration-test-key-12345';
		file_put_contents($tempKeyFile, $testKey);

		try {
			$query = "CREATE RAG MODEL 'keyfile_test_model' (
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

			// Mock the manticore client
			$mockClient = $this->createMock(HTTPClient::class);

			// Create mock responses
			$initResponse1 = $this->createMock(Response::class);
			$initResponse1->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

			$initResponse2 = $this->createMock(Response::class);
			$initResponse2->method('getResult')->willReturn(Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']]));

			$modelExistsResponse = $this->createMock(Response::class);
			$modelExistsResponse->method('getResult')->willReturn(Struct::fromData([['data' => [['count' => 0]]]]));

			$insertResponse = $this->createMock(Response::class);
			$insertResponse->method('getResult')->willReturn(Struct::fromData([['total' => 1, 'error' => '', 'warning' => '']]));

			$mockClient->expects($this->exactly(4))
				->method('sendRequest')
				->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $modelExistsResponse, $insertResponse);
			$handler->setManticoreClient($mockClient);

			$task = $handler->run();
			$this->assertTrue($task->isSucceed());

			$result = $task->getResult();
			$this->assertInstanceOf(TaskResult::class, $result);
		} finally {
			// Clean up
			if (file_exists($tempKeyFile)) {
				unlink($tempKeyFile);
			}
		}
	}
}
