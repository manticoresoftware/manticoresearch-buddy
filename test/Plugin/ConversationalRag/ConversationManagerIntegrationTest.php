<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationManager;
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
	 */
	public function testCompleteConversationFlowWithRealDatabase(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// Save a user message
		$this->conversationManager->saveMessage(
			'test-conversation-1',
			'test-model-1',
			'user',
			'What is machine learning?',
			50,
			'NEW_QUESTION',
			'machine learning information',
			'',
			[]
		);

		// Small delay to ensure different timestamps
		sleep(1);

		// Save an assistant response
		$this->conversationManager->saveMessage(
			'test-conversation-1',
			'test-model-1',
			'assistant',
			'Machine learning is a subset of artificial intelligence that focuses '
				. 'on algorithms that can learn from data.',
			75,
			'ANSWER',
			'',
			'',
			[]
		);

		// Retrieve conversation history
		$history = $this->conversationManager->getConversationHistory('test-conversation-1');

		$expectedHistory = "user: What is machine learning?\n"
			. 'assistant: Machine learning is a subset of artificial intelligence that focuses '
			. "on algorithms that can learn from data.\n";

		$this->assertEquals($expectedHistory, $history);

		// Test search context retrieval
		$searchContext = $this->conversationManager->getLatestSearchContext('test-conversation-1');
		$this->assertIsArray($searchContext);
		$this->assertEquals('machine learning information', $searchContext['search_query']);
		$this->assertEquals('', $searchContext['exclude_query']);
		$this->assertEquals('', $searchContext['excluded_ids']); // Database stores empty array as empty string

		// Test filtered history for query generation
		$filteredHistory = $this->conversationManager->getConversationHistoryForQueryGeneration('test-conversation-1');
		$this->assertEquals($expectedHistory, $filteredHistory);
	}

	/**
	 * Test conversation with multiple exchanges and search context
	 */
	public function testMultipleConversationExchangesWithSearchContext(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// First exchange with search context
		$this->conversationManager->saveMessage(
			'test-conversation-2',
			'test-model-2',
			'user',
			'Show me movies about space',
			30,
			'NEW_SEARCH',
			'movies about space',
			'Star Wars',
			[1, 2, 3]
		);

		$this->conversationManager->saveMessage(
			'test-conversation-2',
			'test-model-2',
			'assistant',
			'Here are some great space movies: 2001: A Space Odyssey, Interstellar, Gravity...',
			60,
			'ANSWER',
			'',
			'',
			[]
		);

		// Second exchange with different search context
		$this->conversationManager->saveMessage(
			'test-conversation-2',
			'test-model-2',
			'user',
			'What about documentaries?',
			25,
			'NEW_SEARCH',
			'documentaries about space',
			'fiction movies',
			[4, 5, 6]
		);

		$this->conversationManager->saveMessage(
			'test-conversation-2',
			'test-model-2',
			'assistant',
			'Here are some space documentaries: Cosmos, The Farthest, Apollo 13...',
			55,
			'ANSWER',
			'',
			'',
			[]
		);

		// Test that we get the latest search context
		$searchContext = $this->conversationManager->getLatestSearchContext('test-conversation-2');
		$this->assertEquals('documentaries about space', $searchContext['search_query']);
		$this->assertEquals('fiction movies', $searchContext['exclude_query']);
		$this->assertEquals('[4,5,6]', $searchContext['excluded_ids']);

		// Test complete history
		$history = $this->conversationManager->getConversationHistory('test-conversation-2');
		$this->assertStringContainsString('Show me movies about space', $history);
		$this->assertStringContainsString('What about documentaries?', $history);
		$this->assertStringContainsString('Here are some great space movies', $history);
		$this->assertStringContainsString('Here are some space documentaries', $history);
	}

	/**
	 * Test conversation with CONTENT_QUESTION intent filtering
	 */
	public function testContentQuestionIntentFiltering(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// Regular search question
		$this->conversationManager->saveMessage(
			'test-conversation-3',
			'test-model-3',
			'user',
			'What are the best sci-fi movies?',
			25,
			'NEW_SEARCH',
			'best sci-fi movies',
			'',
			[]
		);

		$this->conversationManager->saveMessage(
			'test-conversation-3',
			'test-model-3',
			'assistant',
			'Here are some highly-rated sci-fi movies: Blade Runner 2049, Arrival, The Matrix...',
			50,
			'ANSWER',
			'',
			'',
			[]
		);

		// Content question (should be filtered out in query generation)
		$this->conversationManager->saveMessage(
			'test-conversation-3',
			'test-model-3',
			'user',
			'Tell me more about Blade Runner 2049',
			20,
			'CONTENT_QUESTION',
			'',
			'',
			[]
		);

		$this->conversationManager->saveMessage(
			'test-conversation-3',
			'test-model-3',
			'assistant',
			'Blade Runner 2049 is a 2017 science fiction film directed by Denis Villeneuve...',
			45,
			'ANSWER',
			'',
			'',
			[]
		);

		// Test that complete history includes everything
		$this->assertStringContainsString(
			'What are the best sci-fi movies?',
			$this->conversationManager->getConversationHistory('test-conversation-3')
		);
		$this->assertStringContainsString(
			'Tell me more about Blade Runner 2049',
			$this->conversationManager->getConversationHistory('test-conversation-3')
		);

		// Test that filtered history excludes CONTENT_QUESTION
		$filteredHistory = $this->conversationManager->getConversationHistoryForQueryGeneration('test-conversation-3');
		$this->assertStringContainsString('What are the best sci-fi movies?', $filteredHistory);
		$this->assertStringNotContainsString('Tell me more about Blade Runner 2049', $filteredHistory);

		// Test that search context is not affected by CONTENT_QUESTION
		$searchContext = $this->conversationManager->getLatestSearchContext('test-conversation-3');
		$this->assertEquals('best sci-fi movies', $searchContext['search_query']);
	}

	/**
	 * Test empty conversation handling
	 */
	public function testEmptyConversationHandling(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// Test history for non-existent conversation
		$history = $this->conversationManager->getConversationHistory('non-existent-conversation');
		$this->assertEquals('', $history);

		// Test filtered history for non-existent conversation
		$this->assertEquals(
			'',
			$this->conversationManager->getConversationHistoryForQueryGeneration('non-existent-conversation')
		);

		// Test search context for non-existent conversation
		$searchContext = $this->conversationManager->getLatestSearchContext('non-existent-conversation');
		$this->assertNull($searchContext);
	}

	/**
	 * Test conversation with special characters and long messages
	 */
	public function testSpecialCharactersAndLongMessages(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		// Message with special characters
		$specialMessage = "This message contains: quotes 'single' and \"double\", " .
			"newlines\nand\ttabs, backslashes\\, and unicode: ñáéíóú 🚀";

		$this->conversationManager->saveMessage(
			'test-conversation-4',
			'test-model-4',
			'user',
			$specialMessage,
			100,
			'NEW_SEARCH',
			'special characters test',
			'',
			[]
		);

		// Retrieve and verify the message is preserved correctly
		$history = $this->conversationManager->getConversationHistory('test-conversation-4');
		$this->assertStringContainsString('quotes \'single\' and "double"', $history);
		$this->assertStringContainsString('newlines', $history);
		$this->assertStringContainsString('tabs', $history);
		$this->assertStringContainsString('backslashes', $history);
		$this->assertStringContainsString('unicode: ñáéíóú 🚀', $history);
	}

	/**
	 * Test conversation with JSON excluded IDs
	 */
	public function testJsonExcludedIdsHandling(): void {
		// Initialize the conversations table
		$this->conversationManager->initializeTable($this->client);

		$excludedIds = [10, 20, 30, 40, 50];
		$this->conversationManager->saveMessage(
			'test-conversation-5',
			'test-model-5',
			'user',
			'Show me results excluding some items',
			35,
			'NEW_SEARCH',
			'results excluding items',
			'items to exclude',
			$excludedIds
		);

		// Test search context retrieval with JSON
		$searchContext = $this->conversationManager->getLatestSearchContext('test-conversation-5');
		$this->assertIsArray($searchContext);
		$this->assertEquals('results excluding items', $searchContext['search_query']);
		$this->assertEquals('items to exclude', $searchContext['exclude_query']);

		// Verify JSON is properly stored and retrieved
		$retrievedIds = json_decode($searchContext['excluded_ids'], true);
		$this->assertEquals($excludedIds, $retrievedIds);
	}

	/**
	 * Test table creation with real database
	 */
	public function testTableCreationWithRealDatabase(): void {
		// This should create the table without errors
		$this->conversationManager->initializeTable($this->client);

		// Verify table exists by trying to insert into it
		$this->conversationManager->saveMessage(
			'test-conversation-6',
			'test-model-6',
			'user',
			'Test message for table creation',
			10,
			'NEW_SEARCH',
			'table creation test',
			'',
			[]
		);

		// Verify we can retrieve the message
		$history = $this->conversationManager->getConversationHistory('test-conversation-6');
		$this->assertStringContainsString('Test message for table creation', $history);
	}
}
