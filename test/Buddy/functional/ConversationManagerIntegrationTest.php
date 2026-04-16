<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationMessage;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
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
		$this->conversationManager = new ConversationManager($this->client);
	}

	/**
	 * Test complete conversation flow with real database
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testCompleteConversationFlowWithRealDatabase(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// Save a user message
		$this->saveUser(
			'test-conversation-1',
			'test-model-1',
			'What is machine learning?',
			50,
			'NEW',
			'machine learning information',
			'',
			[]
		);

		// Small delay to ensure different timestamps
		sleep(1);

		// Save an assistant response
		$this->saveAssistant(
			'test-conversation-1',
			'test-model-1',
			'Machine learning is a subset of artificial intelligence that focuses '
			. 'on algorithms that can learn from data.',
			75,
			'ANSWER'
		);

		// Retrieve conversation history
		$history = $this->getConversationHistory('test-conversation-1');

		$expectedHistory = "user: What is machine learning?\n"
			. 'assistant: Machine learning is a subset of artificial intelligence that focuses '
			. "on algorithms that can learn from data.\n";

		$this->assertEquals($expectedHistory, $history);

		// Test search context retrieval
		$searchContext = $this->getLatestSearchContext('test-conversation-1');
		$this->assertIsArray($searchContext);
		$this->assertEquals('machine learning information', $searchContext['search_query']);
		$this->assertEquals('', $searchContext['exclude_query']);
		$this->assertEquals('[]', $searchContext['excluded_ids']); // Empty excluded IDs are stored as JSON

		// Test query-generation history
		$queryHistory = $this->getConversationHistoryForQueryGeneration('test-conversation-1');
		$this->assertEquals($expectedHistory, $queryHistory);
	}

	/**
	 * Test conversation with multiple exchanges and search context
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testMultipleConversationExchangesWithSearchContext(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// First exchange with search context
		$this->saveUser(
			'test-conversation-2',
			'test-model-2',
			'Show me movies about space',
			30,
			'NEW',
			'movies about space',
			'Star Wars',
			['1', '2', '3']
		);

		$this->saveAssistant(
			'test-conversation-2',
			'test-model-2',
			'Here are some great space movies: 2001: A Space Odyssey, Interstellar, Gravity...',
			60,
			'ANSWER'
		);

		// Second exchange with different search context
		$this->saveUser(
			'test-conversation-2',
			'test-model-2',
			'What about documentaries?',
			25,
			'NEW',
			'documentaries about space',
			'fiction movies',
			['4', '5', '6']
		);

		$this->saveAssistant(
			'test-conversation-2',
			'test-model-2',
			'Here are some space documentaries: Cosmos, The Farthest, Apollo 13...',
			55,
			'ANSWER'
		);

		// Test that we get the latest search context
		$searchContext = $this->getLatestSearchContext('test-conversation-2');
		$this->assertNotNull($searchContext);
		$this->assertEquals('documentaries about space', $searchContext['search_query']);
		$this->assertEquals('fiction movies', $searchContext['exclude_query']);
		$this->assertEquals('["4","5","6"]', $searchContext['excluded_ids']);

		// Test complete history
		$history = $this->getConversationHistory('test-conversation-2');
		$this->assertStringContainsString('Show me movies about space', $history);
		$this->assertStringContainsString('What about documentaries?', $history);
		$this->assertStringContainsString('Here are some great space movies', $history);
		$this->assertStringContainsString('Here are some space documentaries', $history);
	}

	/**
	 * Test conversation with FOLLOW_UP intent history
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testContentQuestionIntentFiltering(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// Regular search question
		$this->saveUser(
			'test-conversation-3',
			'test-model-3',
			'What are the best sci-fi movies?',
			25,
			'NEW',
			'best sci-fi movies',
			'',
			[]
		);

		$this->saveAssistant(
			'test-conversation-3',
			'test-model-3',
			'Here are some highly-rated sci-fi movies: Blade Runner 2049, Arrival, The Matrix...',
			50,
			'ANSWER'
		);

		// Follow-up question should remain in query generation history.
		$this->saveUser(
			'test-conversation-3',
			'test-model-3',
			'Tell me more about Blade Runner 2049',
			20,
			'FOLLOW_UP',
			'',
			'',
			[]
		);

		$this->saveAssistant(
			'test-conversation-3',
			'test-model-3',
			'Blade Runner 2049 is a 2017 science fiction film directed by Denis Villeneuve...',
			45,
			'ANSWER'
		);

		$this->saveUser(
			'test-conversation-3',
			'test-model-3',
			'Who directed it?',
			15,
			'FOLLOW_UP',
			'',
			'',
			[]
		);

		$this->saveAssistant(
			'test-conversation-3',
			'test-model-3',
			'Denis Villeneuve directed Blade Runner 2049.',
			30,
			'FOLLOW_UP'
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

		// Test that search context is not affected by consecutive FOLLOW_UP turns.
		$searchContext = $this->getLatestSearchContext('test-conversation-3');
		$this->assertNotNull($searchContext);
		$this->assertEquals('best sci-fi movies', $searchContext['search_query']);
	}

	/**
	 * Test empty conversation handling
	 *
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testEmptyConversationHandling(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// Test history for non-existent conversation
		$history = $this->getConversationHistory('non-existent-conversation');
		$this->assertEquals('', $history);

		// Test query-generation history for non-existent conversation
		$this->assertEquals(
			'',
			$this->getConversationHistoryForQueryGeneration('non-existent-conversation')
		);

		// Test search context for non-existent conversation
		$searchContext = $this->getLatestSearchContext('non-existent-conversation');
		$this->assertNull($searchContext);
	}

	/**
	 * Test conversation with special characters and long messages
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testSpecialCharactersAndLongMessages(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// Message with special characters
		$specialMessage = "This message contains: quotes 'single' and \"double\", " .
			"newlines\nand\ttabs, backslashes\\, and unicode: ñáéíóú 🚀";

		$this->saveUser(
			'test-conversation-4',
			'test-model-4',
			$specialMessage,
			100,
			'NEW',
			'special characters test',
			'',
			[]
		);

		// Retrieve and verify the message is preserved correctly
		$history = $this->getConversationHistory('test-conversation-4');
		$this->assertStringContainsString('quotes \'single\' and "double"', $history);
		$this->assertStringContainsString('newlines', $history);
		$this->assertStringContainsString('tabs', $history);
		$this->assertStringContainsString('backslashes', $history);
		$this->assertStringContainsString('unicode: ñáéíóú 🚀', $history);
	}

	/**
	 * Test conversation with JSON excluded IDs
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testJsonExcludedIdsHandling(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		$excludedIds = ['10', '20', '30', '40', '50'];
		$this->saveUser(
			'test-conversation-5',
			'test-model-5',
			'Show me results excluding some items',
			35,
			'NEW',
			'results excluding items',
			'items to exclude',
			$excludedIds
		);

		// Test search context retrieval with JSON
		$searchContext = $this->getLatestSearchContext('test-conversation-5');
		$this->assertIsArray($searchContext);
		$this->assertEquals('results excluding items', $searchContext['search_query']);
		$this->assertEquals('items to exclude', $searchContext['exclude_query']);

		// Verify JSON is properly stored and retrieved
		$retrievedIds = json_decode($searchContext['excluded_ids'], true);
		$this->assertEquals($excludedIds, $retrievedIds);
	}

	/**
	 * Test table creation with real database
	 *
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 * @throws ManticoreSearchResponseError
	 */
	public function testTableCreationWithRealDatabase(): void {
		// This should create the table without errors
		$this->conversationManager->initializeTable($this->client);

		// Verify table exists by trying to insert into it
		$this->saveUser(
			'test-conversation-6',
			'test-model-6',
			'Test message for table creation',
			10,
			'NEW',
			'table creation test',
			'',
			[]
		);

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
	 * @return array{search_query: string, exclude_query: string, excluded_ids: string}|null
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function getLatestSearchContext(string $conversationUuid): ?array {
		return $this->conversationManager->getConversationMessages($conversationUuid)->latestSearchContext();
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function getConversationHistoryForQueryGeneration(string $conversationUuid): string {
		return $this->conversationManager->getConversationMessages($conversationUuid)->format();
	}

	/**
	 * @param array<int, string> $excludedIds
	 * @throws JsonException
	 * @throws ManticoreSearchClientError
	 */
	private function saveUser(
		string $conversationUuid,
		string $modelUuid,
		string $message,
		int $tokensUsed,
		string $intent,
		string $searchQuery,
		string $excludeQuery,
		array $excludedIds
	): void {
		$this->conversationManager->saveMessage(
			$conversationUuid,
			$modelUuid,
			ConversationMessage::userWithExcludedIds(
				$message,
				$intent,
				$searchQuery,
				$excludeQuery,
				$excludedIds
			),
			$tokensUsed
		);
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private function saveAssistant(
		string $conversationUuid,
		string $modelUuid,
		string $message,
		int $tokensUsed,
		string $intent
	): void {
		$this->conversationManager->saveMessage(
			$conversationUuid,
			$modelUuid,
			ConversationMessage::assistant($message, $intent),
			$tokensUsed
		);
	}
}
