<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationMessage;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\TestCase;

class ConversationManagerSqlValidationTest extends TestCase {

	/**
	 * Test that saveMessage generates valid SQL INSERT syntax
	 */
	public function testSaveMessageGeneratesValidInsertSql(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = new ConversationManager($mockClient);

		// Mock successful response
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with(
				$this->callback(
					function ($sql) {
						return $this->validateInsertSql($sql);
					}
				)
			)
			->willReturn($mockResponse);

		$conversationManager->saveMessage(
			'conv-123',
			'model-456',
			ConversationMessage::user('Hello, how are you?', 'NEW'),
			150
		);
	}

	/**
	 * Validate INSERT SQL structure and values
	 */
	private function validateInsertSql(string $sql): bool {
		$this->validateBasicSqlStructure($sql);
		$this->validateValuesCount($sql);
		$this->validateTableNameNotInValues($sql);
		$this->validateQuotedStrings($sql);

		return true;
	}

	/**
	 * Validate basic SQL structure
	 */
	private function validateBasicSqlStructure(string $sql): void {
		$this->assertStringStartsWith('INSERT INTO chat_conversations', $sql);
		$this->assertStringContainsString(
			'(conversation_uuid, model_uuid, created_at, role, message, tokens_used, intent, '
			. 'search_query, exclude_query, excluded_ids, ttl)',
			$sql
		);
		$this->assertStringContainsString('VALUES (', $sql);
	}

	/**
	 * Validate that VALUES clause has exactly 11 values
	 */
	private function validateValuesCount(string $sql): void {
		$valuesMatch = [];
		if (!preg_match('/VALUES\s*\((.*)\)/', $sql, $valuesMatch)) {
			return;
		}

		$values = $valuesMatch[1];
		$valueArray = $this->parseValuesFromSql($values);

		$valueCount = sizeof(array_filter($valueArray));
		if ($valueCount !== 11) {
			echo '[DEBUG SQL] ' . $sql . "\n";
			echo "[DEBUG VALUE COUNT] $valueCount\n";
			echo '[DEBUG VALUES] ' . print_r($valueArray, true) . "\n";
		}
		$this->assertEquals(
			11,
			$valueCount,
			'VALUES clause should have exactly 11 values matching the column count'
		);
	}

	/**
	 * Parse values from SQL VALUES clause, handling quoted strings properly
	 * @return array<int, string>
	 */
	private function parseValuesFromSql(string $values): array {
		$valueArray = [];
		$currentValue = '';
		$inQuotes = false;
		$quoteChar = '';

		for ($i = 0; $i < strlen($values); $i++) {
			$char = $values[$i];
			if (!$inQuotes && ($char === "'" || $char === '"')) {
				$inQuotes = true;
				$quoteChar = $char;
				$currentValue .= $char;
			} elseif ($inQuotes && $char === $quoteChar) {
				$inQuotes = false;
				$currentValue .= $char;
			} elseif (!$inQuotes && $char === ',') {
				$valueArray[] = trim($currentValue);
				$currentValue = '';
			} else {
				$currentValue .= $char;
			}
		}
		$valueArray[] = trim($currentValue); // Add last value

		return $valueArray;
	}

	/**
	 * Validate that table name doesn't appear in VALUES clause
	 */
	private function validateTableNameNotInValues(string $sql): void {
		$this->assertDoesNotMatchRegularExpression(
			'/VALUES\s*\([^)]*chat_conversations[^)]*\)/',
			$sql,
			'Table name should not appear in VALUES clause'
		);
	}

	/**
	 * Validate quoted strings in VALUES clause
	 */
	private function validateQuotedStrings(string $sql): void {
		$this->assertMatchesRegularExpression(
			"/VALUES\s*\('[^']*'/",
			$sql,
			'First value should be a quoted string'
		);
	}


	/**
	 * Test saveMessage with all optional parameters generates valid SQL
	 */
	public function testSaveMessageWithAllParametersGeneratesValidInsertSql(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = new ConversationManager($mockClient);

		// Mock successful response
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);

		$mockClient->expects($this->once())
		->method('sendRequest')
		->with(
			$this->callback(
				function ($sql) {
					return $this->validateInsertSqlWithAllParameters($sql);
				}
			)
		)
		->willReturn($mockResponse);

		$conversationManager->saveMessage(
			'conv-with-all-params',
			'model-with-all-params',
			ConversationMessage::userWithExcludedIds(
				'Message with all parameters',
				'NEW',
				'search query here',
				'exclude query here',
				['1', '2', '3']
			),
			100
		);
	}

	/**
	 * Validate INSERT SQL with all parameters
	 */
	private function validateInsertSqlWithAllParameters(string $sql): bool {
		$this->validateBasicSqlStructure($sql);
		$this->validateAllFieldsPresent($sql);
		$this->validateValuesCount($sql);

		return true;
	}

	/**
	 * Validate that all expected fields are present in SQL
	 */
	private function validateAllFieldsPresent(string $sql): void {
		$expectedFields = [
		'conversation_uuid', 'model_uuid', 'created_at', 'role', 'message',
		'tokens_used', 'intent', 'search_query', 'exclude_query', 'excluded_ids', 'ttl',
		];

		foreach ($expectedFields as $field) {
			$this->assertStringContainsString($field, $sql);
		}
	}

	/**
	 * Test that saveMessage properly handles special characters in SQL
	 */
	public function testSaveMessageHandlesSpecialCharactersInSql(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = new ConversationManager($mockClient);

		// Mock successful response
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);

		$mockClient->expects($this->once())
		->method('sendRequest')
		->with(
			$this->callback(
				function ($sql) {
					// Check that special characters are properly escaped
					$this->assertStringContainsString("O\\'Reilly", $sql);
					$this->assertStringContainsString('line\\nbreak', $sql);
					$this->assertStringContainsString('quote\\"test', $sql);

					// Validate that the SQL is still syntactically valid
					$this->assertStringStartsWith('INSERT INTO chat_conversations', $sql);
					$this->assertStringContainsString('VALUES (', $sql);

					return true;
				}
			)
		)
		->willReturn($mockResponse);

		$conversationManager->saveMessage(
			'conv-123',
			'model-456',
			ConversationMessage::user("O'Reilly's book has a line\nbreak and quote\"test", 'NEW'),
			150
		);
	}

	/**
	 * Test that getConversationMessages generates valid SELECT SQL
	 */
	public function testGetConversationMessagesGeneratesValidSelectSql(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = new ConversationManager($mockClient);

		// Mock response with conversation data
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
				'data' => [
					$this->createConversationRow('user', 'Hello'),
					$this->createConversationRow('assistant', 'Hi there!'),
				],
				],
				]
			)
		);

		$mockClient->expects($this->once())
		->method('sendRequest')
		->with(
			$this->callback(
				function ($sql) {
					// Validate SELECT SQL structure
					$this->assertStringStartsWith(
						'SELECT role, message, intent, search_query, exclude_query, excluded_ids '
						. 'FROM chat_conversations',
						$sql
					);
					$this->assertStringContainsString("WHERE conversation_uuid = 'conv-123'", $sql);
					$this->assertStringContainsString('ORDER BY created_at ASC, id ASC', $sql);
					$this->assertStringContainsString('LIMIT 100', $sql);

					return true;
				}
			)
		)
		->willReturn($mockResponse);

			$result = $conversationManager->getConversationMessages('conv-123');
			$this->assertEquals(
				"user: Hello\nassistant: Hi there!\n",
				$result->format()
			);
	}

	/**
	 * Test that latest search context is derived from conversation messages
	 */
	public function testLatestSearchContextUsesConversationMessagesSelectSql(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = new ConversationManager($mockClient);

		// Mock response with search context data
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
				'data' => [
					$this->createConversationRow(
						'user',
						'Show me movies',
						'NEW',
						'movies about space',
						'Star Wars',
						'[1,2,3]'
					),
				],
				],
				]
			)
		);

		$mockClient->expects($this->once())
		->method('sendRequest')
		->with(
			$this->callback(
				function ($sql) {
					// Validate SELECT SQL structure for search context
					$this->assertStringStartsWith(
						'SELECT role, message, intent, search_query, exclude_query, excluded_ids '
						. 'FROM chat_conversations',
						$sql
					);
					$this->assertStringContainsString("WHERE conversation_uuid = 'conv-123'", $sql);
					$this->assertStringContainsString('ORDER BY created_at ASC, id ASC', $sql);
					$this->assertStringContainsString('LIMIT 100', $sql);

					return true;
				}
			)
		)
		->willReturn($mockResponse);

		$result = $conversationManager->getConversationMessages('conv-123')->latestSearchContext();
		$this->assertIsArray($result);
		$this->assertEquals('movies about space', $result['search_query']);
	}

	/**
	 * Test that query generation history is built from conversation messages
	 */
	public function testQueryGenerationHistoryUsesConversationMessagesSelectSql(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = new ConversationManager($mockClient);

		// Mock response with conversation data
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
				'data' => [
					$this->createConversationRow('user', 'Show me movies'),
					$this->createConversationRow('assistant', 'Here are movies...'),
				],
				],
				]
			)
		);

		$mockClient->expects($this->once())
		->method('sendRequest')
		->with(
			$this->callback(
				function ($sql) {
					// Validate SELECT SQL structure
					$this->assertStringStartsWith(
						'SELECT role, message, intent, search_query, exclude_query, excluded_ids '
						. 'FROM chat_conversations',
						$sql
					);
					$this->assertStringContainsString("WHERE conversation_uuid = 'conv-123'", $sql);
					$this->assertStringNotContainsString("AND intent != 'FOLLOW_UP'", $sql);
					$this->assertStringContainsString('ORDER BY created_at ASC, id ASC', $sql);
					$this->assertStringContainsString('LIMIT 50', $sql);

					return true;
				}
			)
		)
		->willReturn($mockResponse);

			$result = $conversationManager->getConversationMessages('conv-123', 50)->format();
			$this->assertEquals("user: Show me movies\nassistant: Here are movies...\n", $result);
	}

	/**
	 * Test that initializeTable generates valid CREATE TABLE SQL
	 */
	public function testInitializeTableGeneratesValidCreateTableSql(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = new ConversationManager($mockClient);

		// Mock successful response
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);

		$mockClient->expects($this->once())
		->method('sendRequest')
		->with(
			$this->callback(
				function ($sql) {
					// Validate CREATE TABLE SQL structure
					$this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS chat_conversations', $sql);
					$this->assertStringContainsString('conversation_uuid string', $sql);
					$this->assertStringContainsString('model_uuid string', $sql);
					$this->assertStringContainsString('created_at bigint', $sql);
					$this->assertStringContainsString('role string', $sql);
					$this->assertStringContainsString('message text', $sql);
					$this->assertStringContainsString('tokens_used int', $sql);
					$this->assertStringContainsString('intent string', $sql);
					$this->assertStringContainsString('search_query text', $sql);
					$this->assertStringContainsString('exclude_query text', $sql);
					$this->assertStringContainsString('excluded_ids text', $sql);
					$this->assertStringContainsString('ttl bigint', $sql);

					return true;
				}
			)
		)
		->willReturn($mockResponse);

		$conversationManager->initializeTable($mockClient);
	}

	/**
	 * @return array{role:string, message:string, intent:string, search_query:string,
	 *   exclude_query:string, excluded_ids:string}
	 */
	private function createConversationRow(
		string $role,
		string $message,
		string $intent = '',
		string $searchQuery = '',
		string $excludeQuery = '',
		string $excludedIds = ''
	): array {
		return [
			'role' => $role,
			'message' => $message,
			'intent' => $intent,
			'search_query' => $searchQuery,
			'exclude_query' => $excludeQuery,
			'excluded_ids' => $excludedIds,
		];
	}
}
