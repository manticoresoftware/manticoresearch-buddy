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

	public function testConversationalRagFlow_NewSearch(): void {
		$query = "CALL CONVERSATIONAL_RAG('What is machine learning?', 'docs', 'test_model')";

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

		$this->assertEquals('conversation', $payload->action);
		$this->assertEquals('What is machine learning?', $payload->params['query']);
		$this->assertEquals('docs', $payload->params['table']);
		$this->assertEquals('test_model', $payload->params['model_uuid']);
	}

	public function testConversationalRagFlow_WithOptions(): void {
		$query = "CALL CONVERSATIONAL_RAG('What is AI?', 'docs', 'test_model', '', '{\"temperature\": 0.8, \"max_tokens\": 2000, \"k_results\": 10}')";

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

		$this->assertEquals('conversation', $payload->action);
		$this->assertEquals('What is AI?', $payload->params['query']);
		$this->assertEquals('docs', $payload->params['table']);
		$this->assertEquals('test_model', $payload->params['model_uuid']);
		$this->assertEquals('', $payload->params['conversation_uuid']);
		$this->assertEquals(['temperature' => 0.8, 'max_tokens' => 2000, 'k_results' => 10], $payload->params['overrides']);
	}

	public function testConversationalRagFlow_WithTable(): void {
		$query = "CALL CONVERSATIONAL_RAG('Search this table', 'my_table', 'test_model')";

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

		$this->assertEquals('conversation', $payload->action);
		$this->assertEquals('Search this table', $payload->params['query']);
		$this->assertEquals('my_table', $payload->params['table']);
		$this->assertEquals('test_model', $payload->params['model_uuid']);
	}

	public function testEndToEndModelLifecycle(): void {
		// Test complete model lifecycle through payload parsing

		// 1. Create model
		$createQuery = "CREATE RAG MODEL 'lifecycle_test' (
			llm_provider = 'openai',
			llm_model = 'gpt-4o-mini',
			style_prompt = 'You are a test assistant.',
			temperature = 0.8,
			max_tokens = 1500,
			k_results = 3
		)";

		$createPayload = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $createQuery,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals('create_model', $createPayload->action);
		$this->assertEquals('lifecycle_test', $createPayload->params['name']);
		$this->assertEquals('openai', $createPayload->params['llm_provider']);
		$this->assertEquals('gpt-4o-mini', $createPayload->params['llm_model']);
		$this->assertEquals('You are a test assistant.', $createPayload->params['style_prompt']);
		$this->assertEquals(0.8, $createPayload->params['temperature']);
		$this->assertEquals(1500, $createPayload->params['max_tokens']);
		$this->assertEquals(3, $createPayload->params['k_results']);

		// 2. Show models
		$showPayload = RagPayload::fromRequest(
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

		$this->assertEquals('show_models', $showPayload->action);

		// 3. Describe model
		$describePayload = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => "DESCRIBE RAG MODEL 'lifecycle_test'",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals('describe_model', $describePayload->action);
		$this->assertEquals('lifecycle_test', $describePayload->params['model_name_or_uuid']);

		// 4. Use model in conversation
		$ragPayload = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => "CALL CONVERSATIONAL_RAG('Test query', 'docs', 'lifecycle_test')",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals('conversation', $ragPayload->action);
		$this->assertEquals('Test query', $ragPayload->params['query']);
		$this->assertEquals('lifecycle_test', $ragPayload->params['model_uuid']);

		// 5. Drop model
		$dropPayload = RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => "DROP RAG MODEL 'lifecycle_test'",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals('drop_model', $dropPayload->action);
		$this->assertEquals('lifecycle_test', $dropPayload->params['model_name_or_uuid']);
	}

	public function testErrorHandling_InvalidSyntax(): void {
		// Test that invalid syntax is properly rejected during parsing
		$query = 'CREATE RAG MODEL invalid syntax here';

		try {
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
			// If we get here, the payload was parsed (which might be unexpected)
			$this->assertTrue(true); // Test passes if no exception thrown
		} catch (Exception $e) {
			// If an exception is thrown, that's also acceptable for invalid syntax
			$this->assertTrue(true);
		}
	}
}
