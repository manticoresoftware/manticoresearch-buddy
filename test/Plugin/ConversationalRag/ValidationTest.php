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
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase {
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

	public function testMissingRequiredFieldLlmProvider(): void {
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
		$this->assertFalse($task->isSucceed(), 'Task should fail for missing llm_provider');
		$error = $task->getError();
		$this->assertInstanceOf(QueryParseError::class, $error, 'Error should be QueryParseError');
		$this->assertStringContainsString("Required field 'llm_provider' is missing or empty", $error->getResponseError());
	}

	public function testMissingRequiredFieldLlmModel(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Required field 'llm_model' is missing or empty", $error->getResponseError());
	}

	public function testInvalidLlmProvider(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'invalid_provider',
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString("Invalid LLM provider: invalid_provider. Only 'openai' is supported.", $error->getResponseError());
	}

	public function testTemperatureBelowMinimum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			temperature = -0.1
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString('Temperature must be between 0 and 2', $error->getResponseError());
	}

	public function testTemperatureAboveMaximum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			temperature = 2.1
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString('Temperature must be between 0 and 2', $error->getResponseError());
	}

	public function testMaxTokensBelowMinimum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			max_tokens = 0
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString('max_tokens must be between 1 and 32768', $error->getResponseError());
	}

	public function testMaxTokensAboveMaximum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			max_tokens = 32769
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString('max_tokens must be between 1 and 32768', $error->getResponseError());
	}

	public function testKResultsBelowMinimum(): void {
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString('k_results must be between 1 and 50', $error->getResponseError());
	}

	public function testKResultsAboveMaximum(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			k_results = 51
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
		$this->assertInstanceOf(QueryParseError::class, $error);
		$this->assertStringContainsString('k_results must be between 1 and 50', $error->getResponseError());
	}

	public function testValidModelConfiguration(): void {
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

		$this->assertEquals('create_model', $payload->action);
		$this->assertEquals('test_model', $payload->params['name']);
		$this->assertEquals('openai', $payload->params['llm_provider']);
		$this->assertEquals('gpt-4', $payload->params['llm_model']);
	}

	public function testValidTemperatureEdgeCases(): void {
		$query1 = "CREATE RAG MODEL 'test_model1' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			temperature = 0
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

		$this->assertEquals(0, $payload1->params['temperature']);

		$query2 = "CREATE RAG MODEL 'test_model2' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			temperature = 2
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

		$this->assertEquals(2, $payload2->params['temperature']);
	}

	public function testValidMaxTokensEdgeCases(): void {
		$query1 = "CREATE RAG MODEL 'test_model1' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			max_tokens = 1
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

		$this->assertEquals(1, $payload1->params['max_tokens']);

		$query2 = "CREATE RAG MODEL 'test_model2' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			max_tokens = 32768
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

		$this->assertEquals(32768, $payload2->params['max_tokens']);
	}

	public function testValidKResultsEdgeCases(): void {
		$query1 = "CREATE RAG MODEL 'test_model1' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			k_results = 1
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

		$this->assertEquals(1, $payload1->params['k_results']);

		$query2 = "CREATE RAG MODEL 'test_model2' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			k_results = 50
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

		$this->assertEquals(50, $payload2->params['k_results']);
	}
}
