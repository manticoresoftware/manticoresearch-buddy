<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Payload as ChatPayload;
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
		$query = "CREATE CHAT MODEL 'test_model' (
			model = 'openai:gpt-4',
			style_prompt = 'You are a helpful assistant.',
			retrieval_limit = 5
		)";

		$payload = $this->parseSqlPayload($query);

		$this->assertCreateModelPayload(
			$payload,
			[
				'identifier' => 'test_model',
				'model' => 'openai:gpt-4',
				'style_prompt' => 'You are a helpful assistant.',
				'retrieval_limit' => 5,
			]
		);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLCreateModelKeepsIdentifierSeparateFromDescription(): void {
		$query = "CREATE CHAT MODEL 'test_model' (
			description = 'Test chat Model',
			model = 'openai:gpt-4'
		)";

		$payload = $this->parseSqlPayload($query);

		$this->assertCreateModelPayload(
			$payload,
			[
				'identifier' => 'test_model',
				'description' => 'Test chat Model',
				'model' => 'openai:gpt-4',
			]
		);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLCreateModelParsesNameFieldInBodyWithoutValidation(): void {
		$query = "CREATE CHAT MODEL 'test_model' (
			name = 'Test chat Model',
			model = 'openai:gpt-4'
		)";

		$payload = $this->parseSqlPayload($query);

		$this->assertCreateModelPayload(
			$payload,
			[
				'identifier' => 'test_model',
				'name' => 'Test chat Model',
				'model' => 'openai:gpt-4',
			]
		);
	}

	/**
	 * @throws QueryParseError
	 */
	private function parseSqlPayload(string $query): ChatPayload {
		return ChatPayload::fromRequest($this->createSqlRequest($query));
	}

	private function createSqlRequest(string $query): Request {
		return Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
			]
		);
	}

	/**
	 * @param array<string, int|float|string> $expectedParams
	 */
	private function assertCreateModelPayload(ChatPayload $payload, array $expectedParams): void {
		$this->assertEquals('create_model', $payload->action);

		foreach ($expectedParams as $key => $value) {
			$this->assertEquals($value, $payload->params[$key]);
		}
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLCreateModelParsingWithFlatOptions(): void {
		$query = "CREATE CHAT MODEL advanced_assistant (
			model='openai:gpt-4o',
			style_prompt='You are a helpful assistant specializing in search technology',
			api_key='sk-test',
			base_url='http://host.docker.internal:8787/v1',
			timeout=60,
			retrieval_limit=5
		);";

		$payload = $this->parseSqlPayload($query);

		$this->assertCreateModelPayload(
			$payload,
			[
				'identifier' => 'advanced_assistant',
					'model' => 'openai:gpt-4o',
				'api_key' => 'sk-test',
				'base_url' => 'http://host.docker.internal:8787/v1',
				'timeout' => 60,
				'retrieval_limit' => 5,
			]
		);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLShowModelsParsing(): void {
		$query = 'SHOW CHAT MODELS';

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('show_models', $payload->action);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLShowModelsParsingWithTrailingSemicolon(): void {
		$query = 'SHOW CHAT MODELS;';

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('show_models', $payload->action);
	}

	public function testSQLShowModelsDoesNotMatchTrailingTokens(): void {
		$request = $this->createSqlRequest('SHOW CHAT MODELS test_model');

		$this->assertFalse(ChatPayload::hasMatch($request));
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDescribeModelParsing(): void {
		$query = "DESCRIBE CHAT MODEL 'sfa-2742-dshd6'";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('describe_model', $payload->action);
		$this->assertEquals('sfa-2742-dshd6', $payload->params['model_name_or_uuid']);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDropModelParsing(): void {
		$query = "DROP CHAT MODEL 'test_model'";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('test_model', $payload->params['model_name_or_uuid']);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDropModelIfExistsParsingWithoutQuotes(): void {
		$query = 'DROP CHAT MODEL IF EXISTS advanced_assistant;';

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('advanced_assistant', $payload->params['model_name_or_uuid']);
		$this->assertEquals('1', $payload->params['if_exists']);
	}

	public function testSQLDropModelParsingRejectsTrailingTokens(): void {
		$this->expectException(QueryParseError::class);

		$this->parseSqlPayload('DROP CHAT MODEL my_model garbage');
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDropModelQuotedNameDoesNotSetIfExistsFlag(): void {
		$query = "DROP CHAT MODEL 'my IF EXISTS model'";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('my IF EXISTS model', $payload->params['model_name_or_uuid']);
		$this->assertArrayNotHasKey('if_exists', $payload->params);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testSQLDropModelUnquotedUuidParsing(): void {
		$query = 'DROP CHAT MODEL 550e8400-e29b-41d4-a716-446655440000';

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('drop_model', $payload->action);
		$this->assertEquals('550e8400-e29b-41d4-a716-446655440000', $payload->params['model_name_or_uuid']);
	}

	public function testHTTPNotSupported(): void {
		$this->expectException(QueryParseError::class);

		ChatPayload::fromRequest(
			Request::fromArray(
				[
					'version' => Buddy::PROTOCOL_VERSION,
					'error' => '',
					'payload' => (string)json_encode(
						[
							'id' => 'test_model',
							'name' => 'Test Model',
							'model' => 'openai:gpt-4o',
						]
					),
					'format' => RequestFormat::JSON,
					'endpointBundle' => ManticoreEndpoint::Search,
					'path' => '/chat/models',
				]
			)
		);
	}

	public function testInvalidQueryThrowsException(): void {
		$this->expectException(QueryParseError::class);

		$this->parseSqlPayload('INVALID chat QUERY');
	}

	public function testInvalidChatQueryExposesParseErrorResponse(): void {
		try {
			$this->parseSqlPayload("CALL CHAT('What about rag?', 'docs', 'test_model', 'conversation_1') ololo");
			$this->fail('Expected QueryParseError');
		} catch (QueryParseError $e) {
			$this->assertEquals('Invalid chat query syntax', $e->getResponseError());
		}
	}

	/**
	 * @throws QueryParseError
	 */
	public function testEscapedQuotesInConversationParams(): void {
		$query = "CALL CHAT('I\\'m like programming, " .
			"lets talk about it', 'docs', 'test_model', 'conversation_1')";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('conversation', $payload->action);
		$this->assertEquals("I'm like programming, lets talk about it", $payload->params['query']);
		$this->assertEquals('docs', $payload->params['table']);
		$this->assertEquals('test_model', $payload->params['model_uuid']);
		$this->assertEquals('conversation_1', $payload->params['conversation_uuid']);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testConversationParsingWithoutConversationUuid(): void {
		$query = "CALL CHAT('test query', 'docs', 'model123')";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('conversation', $payload->action);
		$this->assertEquals('test query', $payload->params['query']);
		$this->assertEquals('docs', $payload->params['table']);
		$this->assertEquals('model123', $payload->params['model_uuid']);
		$this->assertArrayNotHasKey('conversation_uuid', $payload->params);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testConversationParsingWithConversationUuid(): void {
		$query = "CALL CHAT('test query', 'docs', 'model123', 'conversation_1')";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('conversation_1', $payload->params['conversation_uuid']);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testConversationParsingWithConversationUuidAndFields(): void {
		$query = "CALL CHAT('test query', 'docs', 'model123', 'conversation_1', 'title_embedding')";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('conversation_1', $payload->params['conversation_uuid']);
		$this->assertEquals('title_embedding', $payload->params['fields']);
	}

	/**
	 * @throws QueryParseError
	 */
	public function testConversationParsingWithEmptyConversationUuidAndFields(): void {
		$query = "CALL CHAT('test query', 'docs', 'model123', '', 'title_embedding')";

		$payload = $this->parseSqlPayload($query);

		$this->assertEquals('', $payload->params['conversation_uuid']);
		$this->assertEquals('title_embedding', $payload->params['fields']);
	}

	public function testConversationParsingTreatsEqualsAsPositionalConversationUuid(): void {
		$query = "CALL CHAT('test query', 'docs', 'model123', fields='title_embedding')";

		$payload = $this->parseSqlPayload($query);
		$this->assertEquals("fields='title_embedding'", $payload->params['conversation_uuid']);
	}

	public function testConversationParsingTreatsNamedStyleFiveArgsAsPositionalValues(): void {
		$query = "CALL CHAT('test query', 'docs', 'model123', conversation_uuid='',"
			. " fields='title_embedding')";

		$payload = $this->parseSqlPayload($query);
		$this->assertEquals("conversation_uuid=''", $payload->params['conversation_uuid']);
		$this->assertEquals("fields='title_embedding'", $payload->params['fields']);
	}

}
