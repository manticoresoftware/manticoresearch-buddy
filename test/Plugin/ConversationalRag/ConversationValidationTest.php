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
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConversationValidationTest extends TestCase {
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
		putenv('TEST_OPENAI_API_KEY=TEST_OPENAI_API_KEY');
		putenv('TEST_ANTHROPIC_API_KEY=sk-ant-test123456789012345678901234567890');
	}

	public static function tearDownAfterClass(): void {
		// Clean up test environment variables
		putenv('TEST_OPENAI_API_KEY');
		putenv('TEST_ANTHROPIC_API_KEY');
	}

	public function testInvalidModelFormat(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'gpt-4',
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed(), 'Task should fail for invalid model format');
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error, 'Error should be QueryParseError');
		$this->assertStringContainsString(
			"model must use 'provider:model' format", $error->getResponseError()
		);
	}

	public function testMissingRequiredFieldModel(): void {
		$query = "CREATE RAG MODEL 'test_model' (
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Required field 'model' is missing or empty", $error->getResponseError());
	}

	public function testSettingsFieldIsRejected(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			settings = '{\"temperature\":0.3}'
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Unsupported field 'settings'", $error->getResponseError());
	}

	public function testUnknownFieldIsRejected(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			ololol = '123'
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Unsupported field 'ololol'", $error->getResponseError());
	}

	public function testDescriptionFieldIsAccepted(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			description = 'Test RAG Model',
			model = 'openai:gpt-4'
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

		$this->assertSame('Test RAG Model', $payload->params['description']);
	}

	public function testNameFieldIsRejected(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			name = 'Test RAG Model',
			model = 'openai:gpt-4'
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Unsupported field 'name'", $error->getResponseError());
	}

	public function testTemperatureFieldIsRejected(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			temperature = 0.3
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Unsupported field 'temperature'", $error->getResponseError());
	}

	public function testMaxTokensFieldIsRejected(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			max_tokens = 1000
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Unsupported field 'max_tokens'", $error->getResponseError());
	}

	public function testKResultsBelowMinimum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			retrieval_limit = 0
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

	public function testKResultsAboveMaximum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			retrieval_limit = 51
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

	public function testKResultsRejectsNonIntegerValue(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			retrieval_limit = 1.5
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

	public function testTimeoutBelowMinimum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			timeout = 0
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString('timeout must be an integer between 1 and 65536', $error->getResponseError());
	}

	public function testTimeoutAboveMaximum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			timeout = 65537
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString('timeout must be an integer between 1 and 65536', $error->getResponseError());
	}

	public function testTimeoutRejectsNonIntegerValue(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			timeout = 1.5
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
		$this->configureClientWithInitialization($mockClient);
		$handler->setManticoreClient($mockClient);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString('timeout must be an integer between 1 and 65536', $error->getResponseError());
	}

	public function testValidTimeoutEdgeCases(): void {
		$query1 = "CREATE RAG MODEL 'test_model1' (
			model = 'openai:gpt-4',
			timeout = 1
		)";

		$payload1 = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query1,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals(1, $payload1->params['timeout']);

		$query2 = "CREATE RAG MODEL 'test_model2' (
			model = 'openai:gpt-4',
			timeout = 65536
		)";

		$payload2 = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query2,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals(65536, $payload2->params['timeout']);
	}

	public function testValidModelConfiguration(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			style_prompt = 'You are a helpful assistant.',
			retrieval_limit = 5,
			max_document_length = 4000
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

		$this->assertEquals('create_model', $payload->action);
		$this->assertEquals('test_model', $payload->params['identifier']);
		$this->assertEquals('openai:gpt-4', $payload->params['model']);
	}

	public function testMaxDocumentLengthBelowMinimum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			max_document_length = 99
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

	public function testMaxDocumentLengthAboveMaximum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			max_document_length = 65537
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

	public function testMaxDocumentLengthRejectsNonIntegerValue(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			max_document_length = 1.5
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

	public function testValidMaxDocumentLengthEdgeCases(): void {
		$query1 = "CREATE RAG MODEL 'test_model1' (
			model = 'openai:gpt-4',
			max_document_length = 0
		)";

		$payload1 = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query1,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals(0, $payload1->params['max_document_length']);

		$query2 = "CREATE RAG MODEL 'test_model2' (
			model = 'openai:gpt-4',
			max_document_length = 65536
		)";

		$payload2 = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query2,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals(65536, $payload2->params['max_document_length']);
	}

	public function testValidKResultsEdgeCases(): void {
		$query1 = "CREATE RAG MODEL 'test_model1' (
			model = 'openai:gpt-4',
			retrieval_limit = 1
		)";

		$payload1 = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query1,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals(1, $payload1->params['retrieval_limit']);

		$query2 = "CREATE RAG MODEL 'test_model2' (
			model = 'openai:gpt-4',
			retrieval_limit = 50
		)";

		$payload2 = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query2,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals(50, $payload2->params['retrieval_limit']);
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
	 */
	private function configureClientWithInitialization(HTTPClient $mockClient): void {
		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				$this->createResponse(),
				$this->createResponse()
			);
	}
}
