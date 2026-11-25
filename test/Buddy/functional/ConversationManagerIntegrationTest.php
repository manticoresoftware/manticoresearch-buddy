<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationManager;
use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class ConversationManagerIntegrationTest extends TestCase {
	use TestFunctionalTrait;

	/**
	 * @var ConversationManager $conversationManager
	 */
	private ConversationManager $conversationManager;

	/**
	 * @var Client $client
	 */
	private Client $client;


	public function setUp(): void {
		// Create a real client connected to the test Manticore instance
		$this->client = new Client('http://127.0.0.1:' . static::getListenHttpPort());
		$this->client->sendRequest(
			'DROP TABLE IF EXISTS ' . ResourceTable::name(ResourceTable::RESOURCE_CHAT_HISTORY, 'test_model')
		);
		$this->conversationManager = new ConversationManager($this->client, 'test_model');
	}

	/**
	 * Test complete conversation flow with real database
	 *
	 * @throws ManticoreSearchClientError
	 * @throws QueryParseError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testCompleteConversationFlowWithRealDatabase(): void {
		// Save a user message
		$this->saveUser(
			'test-conversation-1',
			'What is machine learning?'
		);

		// Small delay to ensure different timestamps
		sleep(1);

		// Save an assistant response
		$this->saveAssistant(
			'test-conversation-1',
			'Machine learning is a subset of artificial intelligence that focuses '
			. 'on algorithms that can learn from data.'
		);

		// Retrieve conversation history
		$history = $this->getConversationHistory('test-conversation-1');

		$expectedHistory = "user: What is machine learning?\n"
			. 'assistant: Machine learning is a subset of artificial intelligence that focuses '
			. "on algorithms that can learn from data.\n";

		$this->assertEquals($expectedHistory, $history);

		// Test query-generation history
		$queryHistory = $this->getConversationHistoryForQueryGeneration('test-conversation-1');
		$this->assertEquals($expectedHistory, $queryHistory);
	}

	/**
	 * Test conversation with multiple exchanges
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testMultipleConversationExchanges(): void {
		// First exchange
		$this->saveUser(
			'test-conversation-2',
			'Show me movies about space'
		);

		$this->saveAssistant(
			'test-conversation-2',
			'Here are some great space movies: 2001: A Space Odyssey, Interstellar, Gravity...'
		);

		// Second exchange
		$this->saveUser(
			'test-conversation-2',
			'What about documentaries?'
		);

		$this->saveAssistant(
			'test-conversation-2',
			'Here are some space documentaries: Cosmos, The Farthest, Apollo 13...'
		);

		// Test complete history
		$history = $this->getConversationHistory('test-conversation-2');
		$this->assertStringContainsString('Show me movies about space', $history);
		$this->assertStringContainsString('What about documentaries?', $history);
		$this->assertStringContainsString('Here are some great space movies', $history);
		$this->assertStringContainsString('Here are some space documentaries', $history);
	}

	/**
	 * Test follow-up questions remain available to the query generator
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testFollowUpQuestionsRemainInHistory(): void {
		// Regular search question
		$this->saveUser('test-conversation-3', 'What are the best sci-fi movies?');

		$this->saveAssistant(
			'test-conversation-3',
			'Here are some highly-rated sci-fi movies: Blade Runner 2049, Arrival, The Matrix...'
		);

		// Follow-up question should remain in query generation history.
		$this->saveUser('test-conversation-3', 'Tell me more about Blade Runner 2049');

		$this->saveAssistant(
			'test-conversation-3',
			'Blade Runner 2049 is a 2017 science fiction film directed by Denis Villeneuve...'
		);

		$this->saveUser('test-conversation-3', 'Who directed it?');

		$this->saveAssistant(
			'test-conversation-3',
			'Denis Villeneuve directed Blade Runner 2049.'
		);

		// Test that complete history includes everything
		$this->assertStringContainsString(
			'What are the best sci-fi movies?',
			$this->getConversationHistory('test-conversation-3')
		);
		$this->assertStringContainsString(
			'Tell me more about Blade Runner 2049',
			$this->getConversationHistory('test-conversation-3')
		);
		$this->assertStringContainsString(
			'Who directed it?',
			$this->getConversationHistory('test-conversation-3')
		);

		$queryHistory = $this->getConversationHistoryForQueryGeneration('test-conversation-3');
		$this->assertStringContainsString('What are the best sci-fi movies?', $queryHistory);
		$this->assertStringContainsString('Tell me more about Blade Runner 2049', $queryHistory);
		$this->assertStringContainsString('Who directed it?', $queryHistory);
	}

	/**
	 * Test empty conversation handling
	 *
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testEmptyConversationHandling(): void {
		// Test history for non-existent conversation
		$history = $this->getConversationHistory('non-existent-conversation');
		$this->assertEquals('', $history);

		// Test query-generation history for non-existent conversation
		$this->assertEquals(
			'',
			$this->getConversationHistoryForQueryGeneration('non-existent-conversation')
		);
	}

	/**
	 * Test conversation with special characters and long messages
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testSpecialCharactersAndLongMessages(): void {
		// Message with special characters
		$specialMessage = "This message contains: quotes 'single' and \"double\", " .
			"newlines\nand\ttabs, backslashes\\, and unicode: ñáéíóú 🚀";

		$this->saveUser('test-conversation-4', $specialMessage);

		// Retrieve and verify the message is preserved correctly
		$history = $this->getConversationHistory('test-conversation-4');
		$this->assertStringContainsString('quotes \'single\' and "double"', $history);
		$this->assertStringContainsString('newlines', $history);
		$this->assertStringContainsString('tabs', $history);
		$this->assertStringContainsString('backslashes', $history);
		$this->assertStringContainsString('unicode: ñáéíóú 🚀', $history);
	}

	/**
	 * Test conversation stores only messages
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testConversationStoresOnlyMessages(): void {
		$this->saveUser('test-conversation-5', 'Show me results excluding some items');

		$this->assertSame(
			"user: Show me results excluding some items\n",
			$this->getConversationHistory('test-conversation-5')
		);
	}

	/** @throws ManticoreSearchClientError|ManticoreSearchResponseError|JsonException */
	public function testExcludedIdsPersistAcrossTurnsAndRemainScopedToTheTargetTable(): void {
		$conversation = 'exclusion-integration';
		$this->saveUser($conversation, 'Recommend TV shows');
		$this->conversationManager->saveTurn(
			$conversation, 'test_model', 'Shows please', 'Here are shows', 'shows', ['12', '9007199254740993']
		);
		$this->conversationManager->saveTurn(
			$conversation, 'test_model', 'Films please', 'Here are films', 'films', ['99']
		);
		$this->assertSame(
			[], $this->conversationManager->getConversationMessages($conversation, 2, 'shows')->excludedIds()
		);
		$this->conversationManager->saveTurn(
			$conversation, 'test_model', 'More shows', 'Here are more shows', 'shows', ['12', '9007199254740993', '43']
		);

		$this->assertSame(
			['12', '9007199254740993', '43'],
			$this->conversationManager->getConversationMessages($conversation, indexName: 'shows')->excludedIds()
		);
		$this->assertSame(
			['99'],
			$this->conversationManager->getConversationMessages($conversation, indexName: 'films')->excludedIds()
		);
		$this->assertSame(
			[],
			$this->conversationManager->getConversationMessages('another-conversation', indexName: 'shows')
				->excludedIds()
		);
		$this->assertSame(
			"user: Recommend TV shows\nuser: Shows please\nassistant: Here are shows\n"
			. "user: Films please\nassistant: Here are films\n"
			. "user: More shows\nassistant: Here are more shows\n",
			$this->getConversationHistory($conversation)
		);
		$this->saveUser($conversation, 'Thanks');
		$this->saveUser($conversation, 'New topic');
		$this->assertSame(
			[], $this->conversationManager->getConversationMessages($conversation, 2, 'shows')->excludedIds()
		);
	}

	public function testMessagesRecordTheirOwnModelName(): void {
		$this->conversationManager->saveMessage('mixed-models', 'first-model', 'user', 'Hello');
		$this->conversationManager->saveMessage('mixed-models', 'second-model', 'assistant', 'Hi');
		$response = $this->client->sendRequest(
			"SELECT role, `model_name` FROM system.chat_history_test_model WHERE conversation_uuid = 'mixed-models' "
			. 'ORDER BY created_at ASC, id ASC'
		);
		$this->assertFalse($response->hasError());
		$rows = $response->getResult()[0]['data'];
		$this->assertSame('first-model', $rows[0]['model_name']);
		$this->assertSame('second-model', $rows[1]['model_name']);
	}

	/**
	 * Test table creation with real database
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testTableCreationWithRealDatabase(): void {
		// Verify table exists by trying to insert into it
		$this->saveUser('test-conversation-6', 'Test message for table creation');

		// Verify we can retrieve the message
		$history = $this->getConversationHistory('test-conversation-6');
		$this->assertStringContainsString('Test message for table creation', $history);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function getConversationHistory(string $conversationUuid): string {
		return $this->conversationManager->getConversationMessages($conversationUuid)->format();
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function getConversationHistoryForQueryGeneration(string $conversationUuid): string {
		return $this->conversationManager->getConversationMessages($conversationUuid)->format();
	}

	/** @throws ManticoreSearchClientError */
	private function saveUser(string $conversationUuid, string $message): void {
		$this->conversationManager->saveMessage(
			$conversationUuid,
			'test_model',
			'user',
			$message
		);
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private function saveAssistant(string $conversationUuid, string $message): void {
		$this->conversationManager->saveMessage(
			$conversationUuid,
			'test_model',
			'assistant',
			$message
		);
	}
}
