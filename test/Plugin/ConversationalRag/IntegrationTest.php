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
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase {
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

	public function testFullCreateModelFlow(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			llm_base_url = 'https://api.openai.com/v1',
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
		$this->assertEquals('https://api.openai.com/v1', $payload->params['llm_base_url']);
		$this->assertEquals('You are a helpful assistant.', $payload->params['style_prompt']);
		$this->assertEquals(0.7, $payload->params['temperature']);
		$this->assertEquals(1000, $payload->params['max_tokens']);
		$this->assertEquals(5, $payload->params['k_results']);
	}





	public function testHandlerCanBeInstantiatedWithParsedPayload(): void {
		$query = 'SHOW RAG MODELS';

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
		$this->assertInstanceOf(RagHandler::class, $handler);

		// Verify the handler has the correct payload
		$this->assertEquals('show_models', $payload->action);
	}

	public function testFullShowModelsFlow(): void {
		$query = 'SHOW RAG MODELS';

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

		$this->assertEquals('show_models', $payload->action);
	}

	public function testFullDescribeModelFlow(): void {
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

		$this->assertEquals('describe_model', $payload->action);
		$this->assertEquals('test_model', $payload->params['model_name_or_uuid']);
	}

	public function testFullDropModelFlow(): void {
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

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('test_model', $payload->params['model_name_or_uuid']);
	}
}
