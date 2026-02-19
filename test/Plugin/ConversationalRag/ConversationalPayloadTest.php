<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Payload as RagPayload;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

class ConversationalPayloadTest extends TestCase {
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

	public function testSQLCreateModelParsing(): void {
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

	public function testSQLShowModelsParsing(): void {
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

	public function testSQLDescribeModelParsing(): void {
		$query = "DESCRIBE RAG MODEL 'sfa-2742-dshd6'";

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
		$this->assertEquals('sfa-2742-dshd6', $payload->params['model_name_or_uuid']);
	}

	public function testSQLDropModelParsing(): void {
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



	public function testHTTPNotSupported(): void {
		$this->expectException(QueryParseError::class);

		RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => (string)json_encode(
					[
					'id' => 'test_model',
					'name' => 'Test Model',
					'llm_provider' => 'openai',
					'llm_model' => 'gpt-4o',
					]
				),
				'format' => RequestFormat::JSON,
				'endpointBundle' => ManticoreEndpoint::Search,
				'path' => '/rag/models',
				'httpMethod' => 'POST',
				]
			)
		);
	}



	public function testInvalidQueryThrowsException(): void {
		$this->expectException(QueryParseError::class);

		RagPayload::fromRequest(
			Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => 'INVALID RAG QUERY',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
				]
			)
		);
	}

	public function testEscapedQuotesInConversationParams(): void {
		$query = "CALL CONVERSATIONAL_RAG('I\\'m like programming, ".
			"lets talk about it', 'docs', 'test_model', 'content', 'conversation_1')";

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
		$this->assertEquals("I'm like programming, lets talk about it", $payload->params['query']);
		$this->assertEquals('docs', $payload->params['table']);
		$this->assertEquals('test_model', $payload->params['model_uuid']);
		$this->assertEquals('content', $payload->params['content_fields']);
		$this->assertEquals('conversation_1', $payload->params['conversation_uuid']);
	}

	public function testConversationParsingWithContentFields(): void {
		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123', 'title,content')";

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
		$this->assertEquals('test query', $payload->params['query']);
		$this->assertEquals('docs', $payload->params['table']);
		$this->assertEquals('model123', $payload->params['model_uuid']);
		$this->assertEquals('title,content', $payload->params['content_fields']);
	}

	public function testConversationParsingWithSingleCustomField(): void {
		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123', 'summary')";

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

		$this->assertEquals('summary', $payload->params['content_fields']);
	}

	public function testMissingContentFieldsThrowsException(): void {
		$this->expectException(QueryParseError::class);

		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123')";

		RagPayload::fromRequest(
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
	}

	public function testEmptyContentFieldsThrowsException(): void {
		$this->expectException(QueryParseError::class);

		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123', '')";

		RagPayload::fromRequest(
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
	}

	public function testWhitespaceContentFieldsThrowsException(): void {
		$this->expectException(QueryParseError::class);

		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123', '   ')";

		RagPayload::fromRequest(
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
	}


}
