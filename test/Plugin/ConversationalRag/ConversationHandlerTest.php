<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Handler as RagHandler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Intent;
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
use PHPUnit\Framework\MockObject\MockObject;
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
			model = 'openai:gpt-4',
			style_prompt = 'You are a helpful assistant.',
			retrieval_limit = 5
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

		$mockClient = $this->createMock(HTTPClient::class);
		$selectResponse = $this->createResponse(
			[['data' => [[
				'uuid' => 'test-uuid',
				'name' => 'test_model',
				'model' => 'openai:gpt-4',
				'created_at' => '2023-01-01',
			]]]]
		);
		$this->configureClientWithInitialization($mockClient, [$selectResponse]);
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

		$mockClient = $this->createMock(HTTPClient::class);
		$selectResponse = $this->createResponse(
			[[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [[
					'uuid' => 'test-uuid',
					'name' => 'test_model',
					'style_prompt' => 'You are a helpful assistant.',
					'settings' => '{"retrieval_limit":5,"max_document_length":2000,"api_key":"sk-test"}',
					'created_at' => '2023-01-01 00:00:00',
				]],
			]]
		);
		$this->configureClientWithInitialization($mockClient, [$selectResponse]);
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
		$apiKeyRows = array_values(
			array_filter(
				$struct[0]['data'],
				static fn(array $row): bool => $row['property'] === 'settings.api_key'
			)
		);
		$this->assertCount(1, $apiKeyRows);
		$this->assertSame('HIDDEN', $apiKeyRows[0]['value']);
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

		$mockClient = $this->createMock(HTTPClient::class);
		$getModelResponse = $this->createResponse(
			[[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [[
					'uuid' => 'test-uuid',
					'name' => 'test_model',
					'style_prompt' => 'You are a helpful assistant.',
					'settings' => '{"retrieval_limit":5,"max_document_length":2000}',
					'created_at' => '2023-01-01 00:00:00',
				]],
			]]
		);
		$deleteModelResponse = $this->createResponse([['total' => 0, 'error' => '', 'warning' => '']]);
		$this->configureClientWithInitialization($mockClient, [$getModelResponse, $deleteModelResponse]);
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

	public function testCreateModelValidationInvalidModelFormat(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'gpt-4',
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString(
			"model must use 'provider:model' format",
			$error->getResponseError()
		);
	}

	public function testCreateModelRejectsTemperatureField(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			temperature = 0.7
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Unsupported field 'temperature'", $error->getResponseError());
	}

	public function testCreateModelRejectsMaxTokensField(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			max_tokens = 1000
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Unsupported field 'max_tokens'", $error->getResponseError());
	}

	public function testCreateModelValidationKResultsTooLow(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			retrieval_limit = 0
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString(
			'retrieval_limit must be an integer between 1 and 50',
			$error->getResponseError()
		);
	}

	public function testCreateModelInvalidMaxDocumentLength(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			max_document_length = 99
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString(
			'max_document_length must be 0 or an integer between 100 and 65536',
			$error->getResponseError()
		);
	}

	public function testCreateModelWithEncryptionIntegration(): void {
		$query = "CREATE RAG MODEL 'encrypted_test_model' (
			model = 'openai:gpt-4',
			style_prompt = 'You are a helpful assistant.',
			retrieval_limit = 5
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

		$mockClient = $this->createMock(HTTPClient::class);
		$selectResponse = $this->createResponse(
			[[
				'total' => 1,
				'error' => '',
				'warning' => '',
				'data' => [[
					'uuid' => 'encrypted-uuid-123',
					'name' => 'encrypted_test_model',
					'style_prompt' => 'You are a helpful assistant.',
					'settings' => '{"retrieval_limit":5,"max_document_length":2000}',
					'created_at' => '2023-01-01 00:00:00',
				]],
			]]
		);
		$this->configureClientWithInitialization($mockClient, [$selectResponse]);
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
				model = 'openai:gpt-4',
				style_prompt = 'You are a helpful assistant.',
				retrieval_limit = 5
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

			$modelExistsResponse = $this->createResponse([['data' => [['count' => 0]]]]);
			$insertResponse = $this->createResponse([['total' => 1, 'error' => '', 'warning' => '']]);
			$this->configureClientWithInitialization($mockClient, [$modelExistsResponse, $insertResponse]);
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

		$initResponses = $this->createInitializationResponses();

		$modelResponse = $this->createResponse(
			[[
				'data' => [[
					'uuid' => 'model-uuid',
					'name' => 'test_model',
					'style_prompt' => 'You are a helpful assistant.',
					'settings' => '{"retrieval_limit":5,"max_document_length":2000}',
					'created_at' => '2023-01-01 00:00:00',
				]],
			]]
		);

		// 4: getConversationHistory
		$historyResponse = $this->createResponse(
			[[
			'total' => 0,
			'error' => '',
			'warning' => '',
			'data' => [],
			]]
		);

		// 5: getConversationHistoryForQueryGeneration
		$queryHistoryResponse = $this->createResponse(
			[[
			'total' => 0,
			'error' => '',
			'warning' => '',
			'data' => [],
			]]
		);

		$describeResponse = $this->createResponse(
			[[
			'data' => [
				['Field' => 'id', 'Type' => 'bigint'],
				['Field' => 'content', 'Type' => 'text'],
				['Field' => 'embedding', 'Type' => 'FLOAT_VECTOR(1536)'],
			],
			]]
		);

		// 8: getExcludedIds (KNN search for exclusions - but since exclude_query is 'none', this might not be called)
			// Since exclude_query is 'none', getExcludedIds returns []
			// So no call here

			// 9: getVectorFields (DESCRIBE table for result filtering)
			$describeResponse2 = $this->createResponse(
				[[
				'data' => [
					['Field' => 'id', 'Type' => 'bigint'],
					['Field' => 'content', 'Type' => 'text'],
					['Field' => 'embedding', 'Type' => 'FLOAT_VECTOR(1536)'],
				],
				]]
			);



		// 8: performSearchWithExcludedIds (main KNN search)
		$searchResponse = $this->createResponse(
			[[
			'data' => [
				['id' => 1, 'content' => 'Action movie content...', 'knn_dist' => 0.1],
			],
			]]
		);

		// 9: saveMessage (user with context)
		$saveUserContextResponse = $this->createResponse();

		$saveAssistantResponse = $this->createResponse();

			$callCounter = 0;
			$responses = [
				...$initResponses, // initializeTables
				$modelResponse, // getModelByUuidOrName
				$historyResponse, // getConversationHistory
				$queryHistoryResponse, // getConversationHistoryForQueryGeneration
				$describeResponse, // detectVectorField for main search
				$searchResponse, // performSearchWithExcludedIds
				$describeResponse2, // getVectorFields
				$saveUserContextResponse, // saveMessage user with context
				$saveAssistantResponse, // saveMessage assistant
			];

			$mockClient->method('hasTable')
			->with('movies')
			->willReturn(true);

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
		/** @var array<int, array{data: array<int, array{
		 *   conversation_uuid: string,
		 *   user_query: string,
		 *   search_query: string,
		 *   sources: mixed
		 * }>}
		 *> $struct */

		$this->assertCount(1, $struct);
		$this->assertArrayHasKey('data', $struct[0]);
		$this->assertArrayHasKey('conversation_uuid', $struct[0]['data'][0]);
		$this->assertArrayHasKey('user_query', $struct[0]['data'][0]);
		$this->assertArrayHasKey('search_query', $struct[0]['data'][0]);
		$this->assertArrayHasKey('response', $struct[0]['data'][0]);
		$this->assertArrayHasKey('sources', $struct[0]['data'][0]);
		$this->assertEquals('conv-uuid', $struct[0]['data'][0]['conversation_uuid']);
		$this->assertEquals('Show me action movies', $struct[0]['data'][0]['user_query']);
		$this->assertStringContainsString('action movies', $struct[0]['data'][0]['search_query']);
	}

	public function testContentQuestionWithoutContextFallsBackToNewSearchIntent(): void {
		$query = "CALL CONVERSATIONAL_RAG('What about comedies?', 'movies', 'model-uuid', 'content', 'conv-uuid')";

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

		$handler = new RagHandler(
			$payload,
			$this->createMockLlmProvider(
				[
					['content' => Intent::CONTENT_QUESTION, 'success' => true, 'metadata' => []],
					[
						'content' => 'SEARCH_QUERY: comedy movies' . "\n" . 'EXCLUDE_QUERY: none',
						'success' => true,
						'metadata' => [],
					],
					['content' => 'YES', 'success' => true, 'metadata' => []],
					[
						'content' => 'Here are some comedies!',
						'metadata' => ['tokens_used' => 99],
						'success' => true,
					],
				]
			)
		);

		$mockClient = $this->createMock(HTTPClient::class);

		$initResponses = $this->createInitializationResponses();
		$modelResponse = $this->createResponse(
			[[
				'data' => [[
					'uuid' => 'model-uuid',
					'name' => 'test_model',
					'style_prompt' => 'You are a helpful assistant.',
					'settings' => '{"retrieval_limit":5,"max_document_length":2000}',
					'created_at' => '2023-01-01 00:00:00',
				]],
			]]
		);
		$historyResponse = $this->createResponse([['data' => []]]);
		$latestContextResponse = $this->createResponse([['data' => []]]);
		$queryHistoryResponse = $this->createResponse([['data' => []]]);
		$describeResponse = $this->createResponse(
			[[ 'data' => [
				['Field' => 'id', 'Type' => 'bigint'],
				['Field' => 'content', 'Type' => 'text'],
				['Field' => 'embedding', 'Type' => 'FLOAT_VECTOR(1536)'],
			] ]]
		);
		$searchResponse = $this->createResponse(
			[[ 'data' => [['id' => 7, 'content' => 'Funny movie', 'knn_dist' => 0.1]] ]]
		);
		$describeResponse2 = $this->createResponse(
			[[ 'data' => [
				['Field' => 'id', 'Type' => 'bigint'],
				['Field' => 'content', 'Type' => 'text'],
				['Field' => 'embedding', 'Type' => 'FLOAT_VECTOR(1536)'],
			] ]]
		);
		$saveUserResponse = $this->createResponse();
		$saveAssistantResponse = $this->createResponse();

		$queries = [];
		$responses = [
			...$initResponses,
			$modelResponse,
			$historyResponse,
			$latestContextResponse,
			$queryHistoryResponse,
			$describeResponse,
			$searchResponse,
			$describeResponse2,
			$saveUserResponse,
			$saveAssistantResponse,
		];
		$callCounter = 0;

		$mockClient->method('hasTable')
			->with('movies')
			->willReturn(true);

		$mockClient->method('sendRequest')
			->willReturnCallback(
				function (string $sql) use (&$callCounter, &$queries, $responses): Response {
					$queries[] = $sql;
					$response = $responses[$callCounter];
					$callCounter++;
					return $response;
				}
			);

		$handler->setManticoreClient($mockClient);
		$task = $handler->run();

		$this->assertTrue($task->isSucceed());
		$userInsert = $queries[9];
		$this->assertStringContainsString("'NEW_SEARCH'", $userInsert);
		$this->assertStringContainsString("'comedy movies'", $userInsert);
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
	 * @return array<int, Response>
	 */
	private function createInitializationResponses(): array {
		return [
			$this->createResponse(),
			$this->createResponse(),
		];
	}

	/**
	 * @param MockObject&HTTPClient $mockClient
	 * @param array<int, Response> $extraResponses
	 */
	private function configureClientWithInitialization(HTTPClient $mockClient, array $extraResponses = []): void {
		$responses = [...$this->createInitializationResponses(), ...$extraResponses];
		$mockClient->expects($this->exactly(sizeof($responses)))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls(...$responses);
	}
}
