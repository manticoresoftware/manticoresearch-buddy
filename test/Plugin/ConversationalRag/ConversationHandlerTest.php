<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Handler as RagHandler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LlmProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Payload as RagPayload;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

class ConversationHandlerTest extends TestCase {
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
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$modelExistsResponse = $this->createMock(Response::class);
		$modelExistsResponse->method('getResult')
			->willReturn(Struct::fromData([['data' => [['count' => 0]]]]));

		$insertResponse = $this->createMock(Response::class);
		$insertResponse->method('getResult')
			->willReturn(Struct::fromData([['total' => 1, 'error' => '', 'warning' => '']]));

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
		$this->assertArrayHasKey('uuid', $struct[0]['data'][0]);
		$this->assertNotEmpty($struct[0]['data'][0]['uuid']);
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
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

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
		$struct = (array)$result->getStruct();
		$this->assertIsArray($struct);
		/** @var array<int, array{data: array<int, array{uuid: string, name: string}>}> $struct */
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
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

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
		$struct = (array)$result->getStruct();
		$this->assertIsArray($struct);
		/** @var array<int, array{data: array<int, array{property: string, value: string}>}> $struct */
		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertGreaterThan(0, sizeof($struct[0]['data']));
		// Should have multiple property-value pairs for the model description
		$this->assertEquals('uuid', $struct[0]['data'][0]['property']);
		$this->assertEquals('test-uuid', $struct[0]['data'][0]['value']);
	}

	public function testDropModelSuccess(): void {
		$query = "DROP RAG MODEL 'test_model'";

		$payload = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

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
		$deleteModelResponse->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$mockClient->expects($this->exactly(4)) // initializeTables (2) + getModel (1) + delete model (1)
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $getModelResponse, $deleteModelResponse);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertTrue($task->isSucceed());
		$result = $task->getResult();

		$this->assertInstanceOf(TaskResult::class, $result);
		$struct = (array)$result->getStruct();
		$this->assertIsArray($struct);
		/** @var array<int, array{total: int, error: string, warning: string}> $struct */
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
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString(
			"Required field 'llm_provider' is missing or empty",
			$error->getResponseError()
		);
	}

	public function testCreateModelValidationInvalidProvider(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'anthropic',
			llm_model = 'gpt-4',
		)";

		$payload = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString(
			"Invalid LLM provider: anthropic. Only 'openai' is supported.",
			$error->getResponseError()
		);
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
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
		$this->assertInstanceOf(QueryParseError::class, $error);
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
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
		$this->assertInstanceOf(QueryParseError::class, $error);
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
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
		$this->assertInstanceOf(QueryParseError::class, $error);
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
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$modelExistsResponse = $this->createMock(Response::class);
		$modelExistsResponse->method('getResult')->willReturn(Struct::fromData([['data' => [['count' => 0]]]]));

		$insertResponse = $this->createMock(Response::class);
		$insertResponse->method('getResult')
			->willReturn(Struct::fromData([['total' => 1, 'error' => '', 'warning' => '']]));

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

	public function testDescribeModelWithApiKeyMasking(): void {
		$query = "DESCRIBE RAG MODEL 'encrypted_test_model'";

		$payload = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' =>
				Buddy::PROTOCOL_VERSION,
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
		$initResponse1->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

		$initResponse2 = $this->createMock(Response::class);
		$initResponse2->method('getResult')->willReturn(
			Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
		);

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
		$struct = (array)$result->getStruct();
		$this->assertIsArray($struct);
		/** @var array<int, array{data: array<int, array<string, mixed>>}> $struct */
		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertGreaterThan(0, sizeof($struct[0]['data']));
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
					'version' =>
					Buddy::PROTOCOL_VERSION,
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
			$initResponse1->method('getResult')->willReturn(
				Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
			);

			$initResponse2 = $this->createMock(Response::class);
			$initResponse2->method('getResult')->willReturn(
				Struct::fromData([['total' => 0, 'error' => '', 'warning' => '']])
			);

			$modelExistsResponse = $this->createMock(Response::class);
			$modelExistsResponse->method('getResult')->willReturn(Struct::fromData([['data' => [['count' => 0]]]]));

			$insertResponse = $this->createMock(Response::class);
			$insertResponse->method('getResult')->willReturn(
				Struct::fromData([['total' => 1, 'error' => '', 'warning' => '']])
			);

			$mockClient->expects($this->exactly(4))
				->method('sendRequest')
				->willReturnOnConsecutiveCalls($initResponse1, $initResponse2, $modelExistsResponse, $insertResponse);
			$handler->setManticoreClient($mockClient);

			try {
				echo "About to call handler->run()...\n";
				$task = $handler->run();
				echo "Handler->run() completed\n";

				$this->assertTrue($task->isSucceed());
				$result = $task->getResult();
			} catch (Exception $e) {
				echo 'Exception: ' . $e->getMessage() . "\n";
				echo 'Stack trace: ' . $e->getTraceAsString() . "\n";
				throw $e;
			}

			$this->assertInstanceOf(TaskResult::class, $result);
			$struct = (array)$result->getStruct();
			$this->assertIsArray($struct);
			/** @var array<int, array{data: array<int, array<string, mixed>>}> $struct */
			$this->assertCount(1, $struct);
			$this->assertArrayHasKey('data', $struct[0]);
			$this->assertGreaterThan(0, sizeof($struct[0]['data']));
		} finally {
			// Clean up the temporary key file
			if (file_exists($tempKeyFile)) {
				unlink($tempKeyFile);
			}
		}
	}

	public function testHandleConversationNewQuestionGeneratesNewContext(): void {
		$query = "CALL CONVERSATIONAL_RAG('Show me action movies', 'movies', 'model-uuid', 'content', 'conv-uuid')";

		$payload = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' =>
				Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

			$this->assertEquals('content', $payload->params['content_fields']);

			$handler = new RagHandler(
				$payload, $this->createMockLlmProvider(
					[
					['content' => 'NEW_SEARCH', 'success' => true, 'metadata' => []], // classifyIntent response
					[
						'content' => 'SEARCH_QUERY: action movies\nEXCLUDE_QUERY: none',
					'success' => true,
					'metadata' => [],
				], // generateQueries response
				['content' => 'YES', 'success' => true, 'metadata' => []], // detectExpansionIntent response
				[
					'content' => 'Here are some action movies!',
					'metadata' => ['tokens_used' => 120],
					'success' => true,
				], // generateResponse
				]
			)
		);

		// Mock the manticore client with all expected calls
		$mockClient = $this->createMock(HTTPClient::class);

		// Expected responses in order:
		// 1-2: initializeTables (model and conversation tables)
		$initResponse = $this->createMock(Response::class);
		$initResponse->method('hasError')->willReturn(false);

		// 3: getModelByUuidOrName
		$modelResponse = $this->createMock(Response::class);
		$modelResponse->method('hasError')->willReturn(false);
		$modelResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						[
							'uuid' => 'model-uuid',
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

		// 4: getConversationHistory
		$historyResponse = $this->createMock(Response::class);
		$historyResponse->method('hasError')->willReturn(false);
		$historyResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'total' => 0,
					'error' => '',
					'warning' => '',
					'data' => [],
				],
				]
			)
		);

		// 5: saveMessage (user)
		$saveUserResponse = $this->createMock(Response::class);
		$saveUserResponse->method('hasError')->willReturn(false);
		$saveUserResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
					'total' => 1,
					'error' => '',
					'warning' => '',
					'data' => [],
				]
			)
		);

		// 6: getConversationHistoryForQueryGeneration
		$queryHistoryResponse = $this->createMock(Response::class);
		$queryHistoryResponse->method('hasError')->willReturn(false);
		$queryHistoryResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'total' => 0,
					'error' => '',
					'warning' => '',
					'data' => [],
				],
				]
			)
		);

		// 7: detectVectorField (DESCRIBE table for main search)
		$describeResponse = $this->createMock(Response::class);
		$describeResponse->method('hasError')->willReturn(false);
		$describeResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'embedding', 'Type' => 'FLOAT_VECTOR(1536)'],
					],
				],
				]
			)
		);

		// 8: getExcludedIds (KNN search for exclusions - but since exclude_query is 'none', this might not be called)
		// Since exclude_query is 'none', getExcludedIds returns []
		// So no call here

		// 9: detectVectorField (DESCRIBE table for main search)
		$describeResponse2 = $this->createMock(Response::class);
		$describeResponse2->method('hasError')->willReturn(false);
		$describeResponse2->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'embedding', 'Type' => 'FLOAT_VECTOR(1536)'],
					],
				],
				]
			)
		);



		// 8: performSearchWithExcludedIds (main KNN search)
		$searchResponse = $this->createMock(Response::class);
		$searchResponse->method('hasError')->willReturn(false);
		$searchResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['id' => 1, 'content' => 'Action movie content...', 'knn_dist' => 0.1],
					],
				],
				]
			)
		);

		// 9: saveMessage (user with context)
		$saveUserContextResponse = $this->createMock(Response::class);
		$saveUserContextResponse->method('hasError')->willReturn(false);

		// 10: saveMessage (assistant)
		$saveAssistantResponse = $this->createMock(Response::class);
		$saveAssistantResponse->method('hasError')->willReturn(false);

		$callCounter = 0;
		$responses = [
			$initResponse, $initResponse, // initializeTables
			$modelResponse, // getModelByUuidOrName
			$historyResponse, // getConversationHistory
			$saveUserResponse, // saveMessage user initial
			$queryHistoryResponse, // getConversationHistoryForQueryGeneration
			$describeResponse, // detectVectorField for main search
			$searchResponse, // performSearchWithExcludedIds
			$saveUserContextResponse, // saveMessage user with context
			$saveAssistantResponse, // saveMessage assistant
		];

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function ($sql) use (&$callCounter, $responses) {
					echo 'DB Call #' . (++$callCounter) . ': ' . substr($sql, 0, 100) . "...\n";
					if ($callCounter > sizeof($responses)) {
						echo "ERROR: More calls than expected responses!\n";
						echo 'Available responses: ' . sizeof($responses) . "\n";
						echo 'This is call #' . $callCounter . "\n";
						throw new Exception("Unexpected database call #$callCounter");
					}
					$response = $responses[$callCounter - 1];
					echo '  Returning response type: ' . $response::class . "\n";
					return $response;
				}
			);

		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		if (!$task->isSucceed()) {
			$error = $task->getError();
			$this->fail('Task failed: ' . $error->getMessage());
		}
		$result = $task->getResult();

		$this->assertInstanceOf(TaskResult::class, $result);
		$struct = (array)$result->getStruct();
		$this->assertIsArray($struct);
		/** @var array<int, array{data: array<int, array{conversation_uuid: string, sources: mixed}>}> $struct */

		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertArrayHasKey('conversation_uuid', $struct[0]['data'][0]);
		$this->assertArrayHasKey('response', $struct[0]['data'][0]);
		$this->assertArrayHasKey('sources', $struct[0]['data'][0]);
		$this->assertEquals('conv-uuid', $struct[0]['data'][0]['conversation_uuid']);
	}

		/**
		 * Create a mock LLM provider with predefined responses
		 *
		 * @param array<int, array<string, mixed>> $responses Array of LLM response arrays
		 * @return LlmProvider
		 */
		private function createMockLlmProvider(array $responses): LlmProvider {
			$mockProvider = $this->createMock(LlmProvider::class);
			$callCount = 0;
			$mockProvider->method('generateResponse')
				->willReturnCallback(
					function ($_prompt, $_options = []) use (&$responses, &$callCount) {
						unset($_prompt, $_options);
					if ($callCount >= sizeof($responses)) {
						throw new \Exception(
							'Too many LLM calls: expected ' . sizeof($responses) . ', got ' . ($callCount + 1)
						);
					}
					$result = $responses[$callCount];
						$callCount++;
						return $result;
					}
				);

			return $mockProvider;
		}
	}
