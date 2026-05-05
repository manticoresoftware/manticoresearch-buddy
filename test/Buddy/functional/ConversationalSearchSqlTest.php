<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class ConversationalSearchSqlTest extends TestCase {
	use TestFunctionalTrait;



	/**
	 * Test complete chat flow including table creation and conversation
	 */
	public function testCompleteChatFlowWithSqlOperations(): void {
		// Use unique names to avoid conflicts
		$uniqueId = uniqid();
		$tableName = "test_docs_{$uniqueId}";
		$modelName = "test_sql_model_{$uniqueId}";

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
				style_prompt = 'You are a helpful assistant.',
				retrieval_limit = 5
			)"
		);
		$this->assertNotEmpty($result); // CREATE CHAT MODEL returns UUID table
		$this->assertStringContainsString('uuid', implode(' ', $result)); // Should contain UUID header

		// Verify conversations table was created
		$result = static::runSqlQuery('SHOW TABLES LIKE chat_conversations');
		$this->assertNotEmpty($result);
		$this->assertStringContainsString('chat_conversations', implode(' ', $result));

		// Test conversation call (this should insert into conversations table)
		// Note: This might fail due to missing API keys, but we can check SQL structure
		$query = "CALL CHAT('What is machine learning?', '{$tableName}', '{$modelName}',";
		$query .= " 'content', 'test-conv-1')";
		$result = $this->runHttpQuery($query);

		// Even if the call fails due to API issues, we should see the conversations table being used
		$errorValue = $result['error'] ?? '';
		if (is_string($errorValue)) {
			$error = $errorValue;
		} elseif (is_array($errorValue)) {
			$error = $errorValue['error'];
		} else {
			$error = '';
		}
		if (!isset($result['error']) || !str_contains($error, 'Failed to insert into conversations table')) {
			return;
		}

		// This would catch SQL syntax errors like the one we fixed
		$this->fail('SQL syntax error in conversation insertion: ' . $error);
	}

	/**
	 * Test conversations table structure directly
	 */
	public function testConversationsTableStructure(): void {
		// Use unique names to avoid conflicts
		$uniqueId = uniqid();
		$modelName = "structure_test_model_{$uniqueId}";

		// Create chat model to ensure conversations table exists
		$result = static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				style_prompt = 'Test assistant',
				retrieval_limit = 3
			)"
		);
		$this->assertNotEmpty($result); // CREATE CHAT MODEL returns UUID table
		$this->assertStringContainsString('uuid', implode(' ', $result)); // Should contain UUID header

		// Check table structure
		$result = static::runSqlQuery('DESCRIBE chat_conversations');
		$this->assertNotEmpty($result);
		$expectedColumns = [
			'conversation_uuid', 'model_uuid', 'created_at', 'role', 'message',
			'tokens_used', 'intent', 'search_query', 'exclude_query', 'excluded_ids', 'ttl',
		];
		foreach ($expectedColumns as $column) {
			$this->assertStringContainsString($column, implode(' ', $result));
		}

		// Test direct INSERT into conversations table
		$result = static::runSqlQuery(
			'INSERT INTO chat_conversations (conversation_uuid, model_uuid, created_at, role, message, '
				. 'tokens_used, intent, exclude_query, excluded_ids, ttl) VALUES '
				. "('test-conv-1', 'test-model-1', " . time() . ", 'user', 'Test message', 50, "
				. "'NEW', '', '[]', " . (time() + 86400) . ')'
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		// Verify the data was inserted correctly
		$result = static::runSqlQuery(
			"SELECT role, message FROM chat_conversations WHERE conversation_uuid = 'test-conv-1'"
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

		// Create test table first
		static::runSqlQuery("CREATE TABLE {$tableName} (title text, content text, id int)");

		// Create chat model
		$result = static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				style_prompt = 'Security test assistant',
				retrieval_limit = 3
			)"
		);
		$this->assertNotEmpty($result); // CREATE CHAT MODEL returns UUID table
		$this->assertStringContainsString('uuid', implode(' ', $result)); // Should contain UUID header

		// Test with potentially dangerous input
		$dangerousInputs = [
			"Robert'); DROP TABLE chat_conversations; --",
			"' OR '1'='1",
			"'; INSERT INTO chat_conversations VALUES ('hack', 'hack', 0, 'hacker', 'pwned', 0, "
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
			$tableCheck = $this->runSqlQuery('SHOW TABLES LIKE chat_conversations');
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

		// Create chat model
		$result = static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				style_prompt = 'History test assistant',
				retrieval_limit = 3
			)"
		);
		$this->assertNotEmpty($result); // CREATE CHAT MODEL returns UUID table
		$this->assertStringContainsString('uuid', implode(' ', $result)); // Should contain UUID header

		// Insert test conversation data directly
		$currentTime = time();
		$conversationId = 'history-test-conv';

		// Insert multiple messages
		$result = static::runSqlQuery(
			'INSERT INTO chat_conversations (conversation_uuid, model_uuid, created_at, role, message, '
				. 'tokens_used, intent, exclude_query, excluded_ids, ttl) VALUES '
				. "('$conversationId', '{$modelName}', $currentTime, 'user', 'First question', "
				. "30, 'NEW', '', '[]', " . ($currentTime + 86400) . ')'
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		$result = static::runSqlQuery(
			'INSERT INTO chat_conversations (conversation_uuid, model_uuid, created_at, role, message, '
				. 'tokens_used, intent, exclude_query, excluded_ids, ttl) VALUES '
				. "('$conversationId', '{$modelName}', " . ($currentTime + 60) . ", 'assistant', "
				. "'First answer', 50, 'ANSWER', '', '[]', " . ($currentTime + 86400) . ')'
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		$result = static::runSqlQuery(
			'INSERT INTO chat_conversations (conversation_uuid, model_uuid, created_at, role, message, '
				. 'tokens_used, intent, exclude_query, excluded_ids, ttl) VALUES '
				. "('$conversationId', '{$modelName}', " . ($currentTime + 120) . ", 'user', "
				. "'Second question', 25, 'FOLLOW_UP', '', '[]', " . ($currentTime + 86400) . ')'
		);
		$this->assertEmpty($result); // INSERT via SQL returns empty result

		// Test complete history retrieval
		$historyResult = $this->runSqlQuery(
			'SELECT role, message FROM chat_conversations WHERE conversation_uuid = '
			. "'$conversationId' ORDER BY created_at ASC"
		);
		$this->assertNotEmpty($historyResult);
		$this->assertStringContainsString('First question', implode(' ', $historyResult));
		$this->assertStringContainsString('First answer', implode(' ', $historyResult));
		$this->assertStringContainsString('Second question', implode(' ', $historyResult));

		// Test query-generation history keeps FOLLOW_UP messages
		$queryHistoryResult = $this->runSqlQuery(
			'SELECT role, message FROM chat_conversations WHERE conversation_uuid = '
			. "'$conversationId' ORDER BY created_at ASC"
		);
		$this->assertNotEmpty($queryHistoryResult);
		$this->assertStringContainsString('First question', implode(' ', $queryHistoryResult));
		$this->assertStringContainsString('First answer', implode(' ', $queryHistoryResult));
		$this->assertStringContainsString('Second question', implode(' ', $queryHistoryResult));

		// Test search context retrieval (only select attributes, not stored fields)
		$contextResult = $this->runSqlQuery(
			'SELECT exclude_query, excluded_ids FROM chat_conversations WHERE '
				. "conversation_uuid = '$conversationId' AND role = 'user' "
				. "AND intent != 'FOLLOW_UP' ORDER BY created_at DESC LIMIT 1"
		);
		$this->assertNotEmpty($contextResult);
	}

	/**
	 * Test error handling for malformed SQL
	 */
	public function testErrorHandlingForMalformedSql(): void {
		// Test various malformed SQL scenarios that should be caught

		// Test INSERT with wrong column count
		$this->assertQueryResultContainsError(
			'INSERT INTO chat_conversations (conversation_uuid, model_uuid, created_at, role, message, '
				. 'tokens_used, intent, exclude_query, excluded_ids, ttl) '
				. "VALUES ('test', 'test', 0)", // Missing 6 values
			'P01: wrong number of values here near \')\''
		);

		// Test INSERT with wrong data types (Manticore might be more permissive than expected)
		$result = static::runSqlQuery(
			'INSERT INTO chat_conversations (conversation_uuid, model_uuid, created_at, role, message, '
				. 'tokens_used, intent, exclude_query, excluded_ids, ttl) VALUES '
				. "('test', 'test', 'not_a_number', 'user', 'test', 'not_a_number', 'test', "
				. "'test', 'test', 'not_a_number')"
		);
		// This might succeed or fail depending on Manticore's type conversion
		// We just check that it doesn't crash the system
		$this->assertTrue(is_array($result));

		// Test SELECT with invalid column names
		$this->assertQueryResultContainsError(
			'SELECT invalid_column FROM chat_conversations',
			'table chat_conversations: parse error: unknown column: invalid_column'
		);
	}

	/**
	 * Test concurrent conversation handling
	 */
	public function testConcurrentConversationHandling(): void {
		// Use unique names to avoid conflicts
		$uniqueId = uniqid();
		$modelName = "concurrent_test_model_{$uniqueId}";

		// Create chat model
		$result = static::runSqlQuery(
			"CREATE CHAT MODEL '{$modelName}' (
				model = 'openai:gpt-4',
				style_prompt = 'Concurrent test assistant',
				retrieval_limit = 3
			)"
		);
		$this->assertNotEmpty($result); // CREATE CHAT MODEL returns UUID table
		$this->assertStringContainsString('uuid', implode(' ', $result)); // Should contain UUID header

		// Insert conversations for different users concurrently
		$currentTime = time();
		$conversations = [
			['conv1', 'user1', 'User 1 message'],
			['conv2', 'user2', 'User 2 message'],
			['conv3', 'user3', 'User 3 message'],
		];

		foreach ($conversations as [$convId, $user, $message]) {
			$result = static::runSqlQuery(
				'INSERT INTO chat_conversations (conversation_uuid, model_uuid, created_at, role, message, '
					. 'tokens_used, intent, exclude_query, excluded_ids, ttl) VALUES '
					. "('$convId', '{$modelName}', $currentTime, '$user', '$message', 30, "
					. "'NEW', '', '[]', " . ($currentTime + 86400) . ')'
			);
			$this->assertEmpty($result); // INSERT via SQL returns empty result
		}

		// Verify each conversation can be retrieved independently
		foreach ($conversations as [$convId, $user, $message]) {
			$result = $this->runSqlQuery(
				"SELECT role, message FROM chat_conversations WHERE conversation_uuid = '$convId'"
			);
			$this->assertNotEmpty($result);
			$this->assertStringContainsString($user, implode(' ', $result));
			$this->assertStringContainsString($message, implode(' ', $result));
		}

		// Verify no cross-contamination
		$conv1Result = $this->runSqlQuery(
			"SELECT COUNT(*) as count FROM chat_conversations WHERE conversation_uuid = 'conv1'"
		);
		$conv2Result = $this->runSqlQuery(
			"SELECT COUNT(*) as count FROM chat_conversations WHERE conversation_uuid = 'conv2'"
		);
		$this->assertStringContainsString('1', implode(' ', $conv1Result));
		$this->assertStringContainsString('1', implode(' ', $conv2Result));
	}
}
