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

	/**
	 * @throws QueryParseError
	 */
	public function testSQLCreateModelParsing(): void {
		$query = "CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			style_prompt = 'You are a helpful assistant.',
			temperature = 0.7,
			max_tokens = 1000,
			k_results = 5
		)";

		$payload = $this->parseSqlPayload($query);

		$this->assertCreateModelPayload(
			$payload,
			[
				'name' => 'test_model',
				'llm_provider' => 'openai',
				'llm_model' => 'gpt-4',
				'style_prompt' => 'You are a helpful assistant.',
				'temperature' => 0.7,
				'max_tokens' => 1000,
				'k_results' => 5,
			]
		);
	}

	/**
	 * @throws QueryParseError
	 */
	private function parseSqlPayload(string $query): RagPayload {
		return RagPayload::fromRequest(
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

	/**
	 * @param array<string, int|float|string> $expectedParams
	 */
	private function assertCreateModelPayload(RagPayload $payload, array $expectedParams): void {
		$this->assertEquals('create_model', $payload->action);

		foreach ($expectedParams as $key => $value) {
			$this->assertEquals($value, $payload->params[$key]);
		}
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLCreateModelParsingWithSettingsJson(): void {
		$query = "CREATE RAG MODEL advanced_assistant (
			llm_provider='openai',
			llm_model='gpt-4o',
			style_prompt='You are a helpful assistant specializing in search technology',
			settings='{\"temperature\":0.3, \"max_tokens\":2000, \"k_results\":5}'
		);";

		$payload = $this->parseSqlPayload($query);

		$this->assertCreateModelPayload(
			$payload,
			[
				'name' => 'advanced_assistant',
				'llm_provider' => 'openai',
				'llm_model' => 'gpt-4o',
				'settings' => '{"temperature":0.3, "max_tokens":2000, "k_results":5}',
			]
		);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLShowModelsParsing(): void {
		$query = 'SHOW RAG MODELS';

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('show_models', $payload->action);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDescribeModelParsing(): void {
		$query = "DESCRIBE RAG MODEL 'sfa-2742-dshd6'";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('describe_model', $payload->action);
		$this->assertEquals('sfa-2742-dshd6', $payload->params['model_name_or_uuid']);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDropModelParsing(): void {
		$query = "DROP RAG MODEL 'test_model'";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('test_model', $payload->params['model_name_or_uuid']);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDropModelIfExistsParsingWithoutQuotes(): void {
		$query = 'DROP RAG MODEL IF EXISTS advanced_assistant;';

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('advanced_assistant', $payload->params['model_name_or_uuid']);
		$this->assertEquals('1', $payload->params['if_exists']);
	}

	public function testSQLDropModelParsingRejectsTrailingTokens(): void {
		$this->expectException(QueryParseError::class);

		$this->parseSqlPayload('DROP RAG MODEL my_model garbage');
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDropModelQuotedNameDoesNotSetIfExistsFlag(): void {
		$query = "DROP RAG MODEL 'my IF EXISTS model'";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('my IF EXISTS model', $payload->params['model_name_or_uuid']);
		$this->assertArrayNotHasKey('if_exists', $payload->params);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDropModelUnquotedUuidParsing(): void {
		$query = 'DROP RAG MODEL 550e8400-e29b-41d4-a716-446655440000';

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $payload->params['model_name_or_uuid']);
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
				]
			)
		);
	}

	public function testInvalidQueryThrowsException(): void {
		$this->expectException(QueryParseError::class);

		$this->parseSqlPayload('INVALID RAG QUERY');
	}

	/**
	 * @throws QueryParseError
	 */
	public function testEscapedQuotesInConversationParams(): void {
		$query = "CALL CONVERSATIONAL_RAG('I\\'m like programming, " .
			"lets talk about it', 'docs', 'test_model', 'content', 'conversation_1')";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('conversation', $payload->action);
		$this->assertEquals("I'm like programming, lets talk about it", $payload->params['query']);
		$this->assertEquals('docs', $payload->params['table']);
		$this->assertEquals('test_model', $payload->params['model_uuid']);
		$this->assertEquals('content', $payload->params['content_fields']);
		$this->assertEquals('conversation_1', $payload->params['conversation_uuid']);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testConversationParsingWithContentFields(): void {
		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123', 'title,content')";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('conversation', $payload->action);
		$this->assertEquals('test query', $payload->params['query']);
		$this->assertEquals('docs', $payload->params['table']);
		$this->assertEquals('model123', $payload->params['model_uuid']);
		$this->assertEquals('title,content', $payload->params['content_fields']);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testConversationParsingWithSingleCustomField(): void {
		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123', 'summary')";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('summary', $payload->params['content_fields']);
	}

	public function testMissingContentFieldsThrowsException(): void {
		$this->expectException(QueryParseError::class);

		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123')";

		$this->parseSqlPayload($query);
	}

	public function testEmptyContentFieldsThrowsException(): void {
		$this->expectException(QueryParseError::class);

		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123', '')";

		$this->parseSqlPayload($query);
	}

	public function testWhitespaceContentFieldsThrowsException(): void {
		$this->expectException(QueryParseError::class);

		$query = "CALL CONVERSATIONAL_RAG('test query', 'docs', 'model123', '   ')";

		$this->parseSqlPayload($query);
	}

}
