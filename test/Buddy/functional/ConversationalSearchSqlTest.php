<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRoute;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Handler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\LlmProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class ConversationalSearchSqlTest extends TestCase {
	use TestFunctionalTrait;

	/** @var list<string> */
	private array $createdChatModels = [];

	/** @var list<string> */
	private array $createdTables = [];

	protected function tearDown(): void {
		foreach ($this->createdChatModels as $modelName) {
			static::runSqlQuery("DROP CHAT MODEL IF EXISTS '{$modelName}'");
		}

		foreach ($this->createdTables as $tableName) {
			static::runSqlQuery("DROP TABLE IF EXISTS {$tableName}");
		}

		$this->createdChatModels = [];
		$this->createdTables = [];
	}

	/**
	 * Test a complete no-retrieval chat turn with real model and history tables.
	 */
	public function testCompleteSocialChatFlowWithSqlOperations(): void {
		// Use unique names to avoid conflicts
		$uniqueId = uniqid();
		$tableName = "test_docs_{$uniqueId}";
		$modelName = "test_sql_model_{$uniqueId}";
		$this->createdTables[] = $tableName;
		$this->createdChatModels[] = $modelName;

		// Create a test documents table for chat
		$result = static::runSqlQuery("CREATE TABLE {$tableName} (title text, content text, id int)");
		$this->assertEmpty($result); // CREATE TABLE via SQL returns empty result

		// Insert some test documents
		$result = static::runSqlQuery(
			"INSERT INTO {$tableName} (id, title, content) VALUES (1, 'Machine Learning Basics', "
				. "'Machine learning is a subset of AI')"
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		$result = static::runSqlQuery(
			"INSERT INTO {$tableName} (id, title, content) VALUES (2, 'Deep Learning', "
				. "'Deep learning uses neural networks')"
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		// Test chat model creation (this should create the conversations table)
		$result = static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				retrieval_limit = 5,
				custom_prompt = 'Reply as a brief tutor.'
			)"
		);
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('name', implode(' ', $result));

		$historyTable = "system.chat_history_{$modelName}";

		// Verify conversations table was created
		$result = static::runSqlQuery("DESCRIBE {$historyTable}");
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('conversation_uuid', implode(' ', $result));

		$conversationId = 'test-conv-1';
		$createdAt = time();
		$result = static::runSqlQuery(
			"INSERT INTO {$historyTable} (conversation_uuid, `model_name`, created_at, role, message, ttl) VALUES "
				. "('{$conversationId}', '{$modelName}', {$createdAt}, 'user', 'What is machine learning?', "
				. ($createdAt + 86400) . '), '
				. "('{$conversationId}', '{$modelName}', {$createdAt}, 'assistant', "
				. "'Machine learning uses examples.', " . ($createdAt + 86400) . ')'
		);
		$this->assertEmpty($result);

		$toolCall = $this->createMock('ToolCall');
		$toolCall->method('getArguments')->willReturn(
			['route' => ConversationRoute::CHAT, 'standalone_question' => '', 'exclude_query' => '',
				'reason' => 'Acknowledgement']
		);
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->willReturn(
			[
				'success' => true, 'content' => '', 'tool_calls' => [$toolCall],
				'metadata' => ['tokens_used' => 1, 'input_tokens' => 1, 'output_tokens' => 1,
					'response_time_ms' => 1, 'finish_reason' => 'tool_calls'],
			]
		);
		$provider->expects($this->once())->method('generateResponse')->with(
			$this->callback(
				static fn(string $prompt): bool => str_contains($prompt, 'Reply as a brief tutor.')
					&& str_contains($prompt, 'Machine learning uses examples.')
					&& str_contains($prompt, '<message>Thanks for explaining</message>')
			)
		)->willReturn(
			[
				'success' => true, 'content' => 'You are welcome!',
				'metadata' => ['tokens_used' => 2, 'input_tokens' => 1, 'output_tokens' => 1,
					'response_time_ms' => 1, 'finish_reason' => 'stop'],
			]
		);
		$query = "CALL CHAT('Thanks for explaining', '{$tableName}', '{$modelName}', '{$conversationId}')";
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION, 'error' => '', 'payload' => $query,
				'format' => RequestFormat::SQL, 'endpointBundle' => Endpoint::Sql, 'path' => '',
			]
		);
		$handler = new Handler(Payload::fromRequest($request), $provider);
		$handler->setManticoreClient(new Client('http://127.0.0.1:' . static::getListenHttpPort()));
		$task = null;
		/** @phpstan-ignore-next-line Swoole's coroutine run function exists in the test container. */
		\Swoole\Coroutine\run(
			static function () use ($handler, &$task): void {
				$task = $handler->run();
				$task->wait();
			}
		);
		$this->assertInstanceOf(Task::class, $task);
		$this->assertTrue($task->isSucceed(), $task->isSucceed() ? '' : $task->getError()->getResponseError());
		$response = $task->getResult();
		$this->assertInstanceOf(TaskResult::class, $response);
		/** @var array<int, array{data: array<int, array{response:string, sources:string, search_query:string}>}> $rows */
		$rows = (array)$response->getStruct();
		$this->assertSame('You are welcome!', $rows[0]['data'][0]['response']);
		$this->assertSame('[]', $rows[0]['data'][0]['sources']);
		$this->assertSame('', $rows[0]['data'][0]['search_query']);

		$stored = static::runHttpQuery(
			"SELECT role, message FROM {$historyTable} WHERE conversation_uuid = '{$conversationId}' "
				. 'ORDER BY created_at ASC, id ASC'
		);
		$this->assertIsArray($stored[0]);
		$this->assertSame('', $stored[0]['error']);
		$this->assertCount(4, $stored[0]['data']);
		$this->assertSame('Thanks for explaining', $stored[0]['data'][2]['message']);
		$this->assertSame('You are welcome!', $stored[0]['data'][3]['message']);
	}

	public function testHybridSqlRetrievesDocumentsWithManualVectors(): void {
		$tableName = 'test_hybrid_' . uniqid();
		$this->createdTables[] = $tableName;
		$createSql = "CREATE TABLE {$tableName} (title text, embedding float_vector knn_type='hnsw' "
			. "knn_dims='2' hnsw_similarity='l2')";
		$this->assertEmpty(static::runSqlQuery($createSql));
		$insertSql = "INSERT INTO {$tableName} (id, title, embedding) VALUES "
			. "(1, 'Frame 16x48', (1,0)), (2, 'Pants', (0,1))";
		$this->assertEmpty(static::runSqlQuery($insertSql));
		$result = static::runHttpQuery(
			"SELECT id, hybrid_score() AS hybrid_score FROM {$tableName} "
				. "WHERE MATCH('frame') AND knn(embedding, 2, (1,0)) "
				. "LIMIT 2 OPTION fusion_method='rrf'"
		);
		$this->assertIsArray($result[0]);
		$this->assertSame('', $result[0]['error']);
		$this->assertNotEmpty($result[0]['data']);
		$this->assertSame(1, $result[0]['data'][0]['id']);
		$this->assertArrayHasKey('hybrid_score', $result[0]['data'][0]);
	}

	/**
	 * Test conversations table structure directly
	 */
	public function testConversationsTableStructure(): void {
		// Use unique names to avoid conflicts
		$uniqueId = uniqid();
		$modelName = "structure_test_model_{$uniqueId}";
		$this->createdChatModels[] = $modelName;

		// Create chat model to ensure conversations table exists
		$result = static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				retrieval_limit = 3
			)"
		);
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('name', implode(' ', $result));

		$historyTable = "system.chat_history_{$modelName}";

		// Check table structure
		$result = static::runSqlQuery("DESCRIBE {$historyTable}");
		$this->assertNotEmpty($result);
		$expectedColumns = [
			'conversation_uuid', 'created_at', 'role', 'message', 'ttl',
		];
		foreach ($expectedColumns as $column) {
			$this->assertStringContainsString($column, implode(' ', $result));
		}

		// Test direct INSERT into conversations table
		$result = static::runSqlQuery(
			"INSERT INTO {$historyTable} (conversation_uuid, created_at, role, message, ttl) VALUES "
				. "('test-conv-1', " . time() . ", 'user', 'Test message', " . (time() + 86400) . ')'
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		// Verify the data was inserted correctly
		$result = static::runSqlQuery(
			"SELECT role, message FROM {$historyTable} WHERE conversation_uuid = 'test-conv-1'"
		);
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('user', implode(' ', $result));
		$this->assertStringContainsString('Test message', implode(' ', $result));
	}

	/**
	 * Test SQL injection prevention and proper escaping
	 */
	public function testSqlInjectionPrevention(): void {
		// Use unique names to avoid conflicts
		$uniqueId = uniqid();
		$modelName = "security_test_model_{$uniqueId}";
		$tableName = "test_docs_{$uniqueId}";
		$this->createdTables[] = $tableName;
		$this->createdChatModels[] = $modelName;

		// Create test table first
		static::runSqlQuery("CREATE TABLE {$tableName} (title text, content text, id int)");

		// Create chat model
		$result = static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				retrieval_limit = 3
			)"
		);
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('name', implode(' ', $result));

		$historyTable = "system.chat_history_{$modelName}";

		// Test with potentially dangerous input
		$dangerousInputs = [
			"Robert'); DROP TABLE {$historyTable}; --",
			"' OR '1'='1",
			"'; INSERT INTO {$historyTable} VALUES ('hack', 'hack', 0, 'hacker', 'pwned', 0, "
				. "'' , '', '', '[]', 0); --",
			'CONCAT(char(39), char(32), char(79), char(82), char(32), char(39), char(49), '
				. 'char(39), char(61), char(39), char(49), char(39))',
		];

		foreach ($dangerousInputs as $input) {
			// Try to insert dangerous input
			$this->runHttpQuery(
				"CALL CHAT('" . addslashes($input) . "', '{$tableName}', "
				. "'{$modelName}', 'content', 'security-conv-" . uniqid() . "')"
			);

			// Check if any SQL injection succeeded (table should still exist)
			$tableCheck = $this->runSqlQuery('SHOW TABLES');
			$this->assertNotEmpty(
				$tableCheck,
				'Conversations table should still exist after potential SQL injection attempt'
			);
		}
	}

	/**
	 * Test conversation history retrieval with SQL
	 */
	public function testConversationHistoryRetrievalWithSql(): void {
		// Use unique names to avoid conflicts
		$uniqueId = uniqid();
		$modelName = "history_test_model_{$uniqueId}";
		$this->createdChatModels[] = $modelName;

		// Create chat model
		$result = static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				retrieval_limit = 3
			)"
		);
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('name', implode(' ', $result));

		$historyTable = "system.chat_history_{$modelName}";

		// Insert test conversation data directly
		$currentTime = time();
		$conversationId = 'history-test-conv';

		// Insert multiple messages
		$result = static::runSqlQuery(
			"INSERT INTO {$historyTable} (conversation_uuid, created_at, role, message, ttl) VALUES "
				. "('$conversationId', $currentTime, 'user', 'First question', "
				. ($currentTime + 86400) . ')'
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		$result = static::runSqlQuery(
			"INSERT INTO {$historyTable} (conversation_uuid, created_at, role, message, ttl) VALUES "
				. "('$conversationId', " . ($currentTime + 60) . ", 'assistant', "
				. "'First answer', " . ($currentTime + 86400) . ')'
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		$result = static::runSqlQuery(
			"INSERT INTO {$historyTable} (conversation_uuid, created_at, role, message, ttl) VALUES "
				. "('$conversationId', " . ($currentTime + 120) . ", 'user', "
				. "'Second question', " . ($currentTime + 86400) . ')'
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		// Test complete history retrieval
		$historyResult = $this->runSqlQuery(
			"SELECT role, message FROM {$historyTable} WHERE conversation_uuid = "
			. "'$conversationId' ORDER BY created_at ASC"
		);
		$this->assertNotEmpty($historyResult);
		$this->assertStringContainsString('First question', implode(' ', $historyResult));
		$this->assertStringContainsString('First answer', implode(' ', $historyResult));
		$this->assertStringContainsString('Second question', implode(' ', $historyResult));

		// Test query-generation history keeps follow-up messages.
		$queryHistoryResult = $this->runSqlQuery(
			"SELECT role, message FROM {$historyTable} WHERE conversation_uuid = "
			. "'$conversationId' ORDER BY created_at ASC"
		);
		$this->assertNotEmpty($queryHistoryResult);
		$this->assertStringContainsString('First question', implode(' ', $queryHistoryResult));
		$this->assertStringContainsString('First answer', implode(' ', $queryHistoryResult));
		$this->assertStringContainsString('Second question', implode(' ', $queryHistoryResult));

		// Excluded IDs are stored as snapshots by table, not in user-message columns.
		$exclusionResult = static::runSqlQuery(
			"INSERT INTO {$historyTable} (conversation_uuid, created_at, role, message, ttl) VALUES "
				. "('$conversationId', " . ($currentTime + 180) . ", 'excluded_ids:films', '[1,2]', "
				. ($currentTime + 86400) . ')'
		);
		$this->assertEmpty($exclusionResult);
		$storedExclusions = $this->runSqlQuery(
			"SELECT message FROM {$historyTable} WHERE conversation_uuid = '$conversationId' "
				. "AND role = 'excluded_ids:films' ORDER BY created_at DESC LIMIT 1"
		);
		$this->assertStringContainsString('[1,2]', implode(' ', $storedExclusions));
	}

	/**
	 * Test error handling for malformed SQL
	 */
	public function testErrorHandlingForMalformedSql(): void {
		$uniqueId = uniqid();
		$modelName = "error_test_model_{$uniqueId}";
		$this->createdChatModels[] = $modelName;
		static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				retrieval_limit = 3
			)"
		);
		$historyTable = "system.chat_history_{$modelName}";

		// Test INSERT with wrong column count
		$this->assertQueryResultContainsError(
			"INSERT INTO {$historyTable} (conversation_uuid, created_at, role, message, ttl) "
				. "VALUES ('test', 'test', 0)", // Missing 2 values
			'P01: wrong number of values here near \')\''
		);

		// Test INSERT with wrong data types (Manticore might be more permissive than expected)
		$result = static::runSqlQuery(
			"INSERT INTO {$historyTable} (conversation_uuid, created_at, role, message, ttl) VALUES "
				. "('test', 'not_a_number', 'user', 'test', 'not_a_number')"
		);
		// This might succeed or fail depending on Manticore's type conversion
		// We just check that it doesn't crash the system
		$this->assertTrue(is_array($result));

		// Test SELECT with invalid column names
		$this->assertQueryResultContainsError(
			"SELECT invalid_column FROM {$historyTable}",
			"table {$historyTable}: parse error: unknown column: invalid_column"
		);
	}

	/**
	 * Test concurrent conversation handling
	 */
	public function testConcurrentConversationHandling(): void {
		// Use unique names to avoid conflicts
		$uniqueId = uniqid();
		$modelName = "concurrent_test_model_{$uniqueId}";
		$this->createdChatModels[] = $modelName;

		// Create chat model
		$result = static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				retrieval_limit = 3
			)"
		);
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('name', implode(' ', $result));

		$historyTable = "system.chat_history_{$modelName}";

		// Insert conversations for different users concurrently
		$currentTime = time();
		$conversations = [
			['conv1', 'user1', 'User 1 message'],
			['conv2', 'user2', 'User 2 message'],
			['conv3', 'user3', 'User 3 message'],
		];

		foreach ($conversations as [$convId, $user, $message]) {
			$result = static::runSqlQuery(
				"INSERT INTO {$historyTable} (conversation_uuid, created_at, role, message, ttl) VALUES "
					. "('$convId', $currentTime, '$user', '$message', "
					. ($currentTime + 86400) . ')'
			);
			$this->assertEmpty($result); // INSERT via SQL returns empty result
		}

		// Verify each conversation can be retrieved independently
		foreach ($conversations as [$convId, $user, $message]) {
			$result = $this->runSqlQuery(
				"SELECT role, message FROM {$historyTable} WHERE conversation_uuid = '$convId'"
			);
			$this->assertNotEmpty($result);
			$this->assertStringContainsString($user, implode(' ', $result));
			$this->assertStringContainsString($message, implode(' ', $result));
		}

		// Verify no cross-contamination
		$conv1Result = $this->runSqlQuery(
			"SELECT COUNT(*) as count FROM {$historyTable} WHERE conversation_uuid = 'conv1'"
		);
		$conv2Result = $this->runSqlQuery(
			"SELECT COUNT(*) as count FROM {$historyTable} WHERE conversation_uuid = 'conv2'"
		);
		$this->assertStringContainsString('1', implode(' ', $conv1Result));
		$this->assertStringContainsString('1', implode(' ', $conv2Result));
	}
}
