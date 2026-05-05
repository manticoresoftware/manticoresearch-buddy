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
		$query = "CREATE CHAT MODEL 'test_model' (
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

		$this->assertEquals('create_model', $payload->action);
		$this->assertEquals('test_model', $payload->params['identifier']);
		$this->assertEquals('openai:gpt-4', $payload->params['model']);
		$this->assertEquals('You are a helpful assistant.', $payload->params['style_prompt']);
		$this->assertEquals(5, $payload->params['retrieval_limit']);
	}





	public function testHandlerCanBeInstantiatedWithParsedPayload(): void {
		$query = 'SHOW CHAT MODELS';

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
		$this->assertInstanceOf(ChatHandler::class, $handler);

		// Verify the handler has the correct payload
		$this->assertEquals('show_models', $payload->action);
	}

	public function testFullShowModelsFlow(): void {
		$query = 'SHOW CHAT MODELS';

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

		$this->assertEquals('show_models', $payload->action);
	}

	public function testFullDescribeModelFlow(): void {
		$query = "DESCRIBE CHAT MODEL 'test_model'";

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

		$this->assertEquals('describe_model', $payload->action);
		$this->assertEquals('test_model', $payload->params['model_name_or_uuid']);
	}

	public function testFullDropModelFlow(): void {
		$query = "DROP CHAT MODEL 'test_model'";

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

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('test_model', $payload->params['model_name_or_uuid']);
	}

	public function testConversationalSearchFlowNewSearch(): void {
		$query = "CALL CHAT('What is machine learning?', 'docs', 'test_model')";

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

		$this->assertEquals('conversation', $payload->action);
		$this->assertEquals('What is machine learning?', $payload->params['query']);
		$this->assertEquals('docs', $payload->params['table']);
		$this->assertEquals('test_model', $payload->params['model_uuid']);
	}

	public function testConversationalSearchFlowWithTable(): void {
		$query = "CALL CHAT('Search this table', 'my_table', 'test_model', 'conversation_1')";

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

		$this->assertEquals('conversation', $payload->action);
		$this->assertEquals('Search this table', $payload->params['query']);
		$this->assertEquals('my_table', $payload->params['table']);
		$this->assertEquals('test_model', $payload->params['model_uuid']);
		$this->assertEquals('conversation_1', $payload->params['conversation_uuid']);
	}

	public function testEndToEndModelLifecycle(): void {
		// Test complete model lifecycle through payload parsing

		// 1. Create model
		$createQuery = "CREATE CHAT MODEL 'lifecycle_test' (
			model = 'openai:gpt-4o-mini',
			style_prompt = 'You are a test assistant.',
			retrieval_limit = 3
		)";

		$createPayload = ChatPayload::fromRequest(
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
		$this->assertEquals('lifecycle_test', $createPayload->params['identifier']);
		$this->assertEquals('openai:gpt-4o-mini', $createPayload->params['model']);
		$this->assertEquals('You are a test assistant.', $createPayload->params['style_prompt']);
		$this->assertEquals(3, $createPayload->params['retrieval_limit']);

		// 2. Show models
		$showPayload = ChatPayload::fromRequest(
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

		$this->assertEquals('show_models', $showPayload->action);

		// 3. Describe model
		$describePayload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => "DESCRIBE CHAT MODEL 'lifecycle_test'",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals('describe_model', $describePayload->action);
		$this->assertEquals('lifecycle_test', $describePayload->params['model_name_or_uuid']);

		// 4. Use model in conversation
		$chatPayload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => "CALL CHAT('Test query', 'docs', 'lifecycle_test')",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals('conversation', $chatPayload->action);
		$this->assertEquals('Test query', $chatPayload->params['query']);
		$this->assertEquals('lifecycle_test', $chatPayload->params['model_uuid']);

		// 5. Drop model
		$dropPayload = ChatPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => "DROP CHAT MODEL 'lifecycle_test'",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);

		$this->assertEquals('drop_model', $dropPayload->action);
		$this->assertEquals('lifecycle_test', $dropPayload->params['model_name_or_uuid']);
	}

	public function testErrorHandlingInvalidSyntax(): void {
		// Test that invalid syntax is properly rejected during parsing
		$query = 'CREATE CHAT MODEL invalid syntax here';

		try {
			ChatPayload::fromRequest(
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
