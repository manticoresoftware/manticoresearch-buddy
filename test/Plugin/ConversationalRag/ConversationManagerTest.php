<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationManager;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConversationManagerTest extends TestCase {
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
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	public function testInitializeTableCreatesTable(): void {
		$conversationManager = $this->createConversationManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$mockResponse = $this->createSuccessfulResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains(/** @lang manticore */ 'CREATE TABLE IF NOT EXISTS rag_conversations'))
			->willReturn($mockResponse);

		$conversationManager->initializeTable($mockClient);
	}

	private function createConversationManager(?HTTPClient $client = null): ConversationManager {
		return new ConversationManager($client ?? $this->createMock(HTTPClient::class));
	}

	private function createSuccessfulResponse(): Response {
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);

		return $mockResponse;
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	public function testInitializeTableAlreadyExists(): void {
		$conversationManager = $this->createConversationManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$mockResponse = $this->createSuccessfulResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains(/** @lang manticore */ 'CREATE TABLE IF NOT EXISTS rag_conversations'))
			->willReturn($mockResponse);

		$conversationManager->initializeTable($mockClient);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 */
	public function testSaveMessageSuccessful(): void {
		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$conversationManager = $this->createConversationManager($mockClient);

		$mockResponse = $this->createSuccessfulResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with(
				$this->callback(
					function ($sql) {
						return str_contains($sql, /** @lang manticore */ 'INSERT INTO rag_conversations')
							&& str_contains($sql, 'conversation_uuid')
							&& str_contains($sql, 'model_uuid')
							&& str_contains($sql, 'role')
							&& str_contains($sql, 'message');
					}
				)
			)
			->willReturn($mockResponse);

		$conversationManager->saveMessage(
			'conv-123',
			'model-456',
			'user',
			'Hello, how are you?',
			150
		);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 */
	public function testSaveMessageStoresExcludedIdsAsJsonArrayEvenWhenEmpty(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = $this->createConversationManager($mockClient);

		$mockResponse = $this->createSuccessfulResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with(
				$this->callback(
					static function (string $sql): bool {
						return str_contains($sql, 'excluded_ids, ttl')
							&& str_contains($sql, "'[]'");
					}
				)
			)
			->willReturn($mockResponse);

		$conversationManager->saveMessage(
			'conv-123',
			'model-456',
			'user',
			'Hello',
			0,
			'NEW_SEARCH',
			'query',
			'',
			[]
		);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetConversationHistoryOrdered(): void {
		// Mock HTTP client
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
							['role' => 'user', 'message' => 'Hello'],
							['role' => 'assistant', 'message' => 'Hi there!'],
							['role' => 'user', 'message' => 'How are you?'],
							['role' => 'assistant', 'message' => 'I am doing well, thank you!'],
						],
					],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains(/** @lang manticore */ 'SELECT role, message FROM rag_conversations'))
			->willReturn($mockResponse);

		$result = $conversationManager->getConversationHistory('conv-123');

		$expected = "user: Hello\nassistant: Hi there!\nuser: How are you?\nassistant: I am doing well, thank you!\n";
		$this->assertEquals($expected, $result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetConversationHistoryEmpty(): void {
		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = $this->createConversationManager($mockClient);
		$mockResponse = $this->createEmptyDataResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($mockResponse);

		$result = $conversationManager->getConversationHistory('conv-123');

		$this->assertEquals('', $result);
	}

	private function createEmptyDataResponse(): Response {
		/** @var Response&MockObject $mockResponse */
		$mockResponse = $this->createSuccessfulResponse();
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
					[
						'data' => [],
					],
				]
			)
		);

		return $mockResponse;
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetConversationHistoryWithLimit(): void {
		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$conversationManager = new ConversationManager($mockClient);

		// Mock response with limited data
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
					[
						'data' => [
							['role' => 'user', 'message' => 'First message'],
							['role' => 'assistant', 'message' => 'First response'],
							['role' => 'user', 'message' => 'Second message'],
						],
					],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains(/** @lang manticore */ 'SELECT role, message FROM rag_conversations'))
			->willReturn($mockResponse);

		$result = $conversationManager->getConversationHistory('conv-123', 5);

		$expected = "user: First message\nassistant: First response\nuser: Second message\n";
		$this->assertEquals($expected, $result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetLatestSearchContextReturnsCorrectData(): void {
		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$conversationManager = $this->createConversationManager($mockClient);

		$mockResponse = $this->createSearchContextResponse(
			[
				'search_query' => 'movies about space',
				'exclude_query' => 'Star Wars',
				'excluded_ids' => '[1,2,3]',
			]
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with(
				$this->callback(
					function ($sql) {
						return str_contains($sql, "intent != 'CONTENT_QUESTION'");
					}
				)
			)
			->willReturn($mockResponse);

		$result = $conversationManager->getLatestSearchContext('conv-123');

		$this->assertIsArray($result);
		$this->assertEquals('movies about space', $result['search_query']);
		$this->assertEquals('Star Wars', $result['exclude_query']);
		$this->assertEquals('[1,2,3]', $result['excluded_ids']);
	}

	/**
	 * @param array{search_query:string, exclude_query:string, excluded_ids:string} $row
	 */
	private function createSearchContextResponse(array $row): Response {
		/** @var Response&MockObject $mockResponse */
		$mockResponse = $this->createSuccessfulResponse();
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
					[
						'data' => [$row],
					],
				]
			)
		);

		return $mockResponse;
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetLatestSearchContextNoContextFound(): void {
		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$conversationManager = $this->createConversationManager($mockClient);

		$mockResponse = $this->createEmptyDataResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($mockResponse);

		$result = $conversationManager->getLatestSearchContext('conv-123');

		$this->assertNull($result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetLatestSearchContextTreatsWhitespaceExcludedIdsAsEmpty(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$conversationManager = $this->createConversationManager($mockClient);

		$mockResponse = $this->createSearchContextResponse(
			[
				'search_query' => 'movies about space',
				'exclude_query' => '',
				'excluded_ids' => " \n\t",
			]
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($mockResponse);

		$result = $conversationManager->getLatestSearchContext('conv-123');

		$this->assertIsArray($result);
		$this->assertEquals('', $result['excluded_ids']);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetConversationHistoryForQueryGenerationFiltersContentQuestions(): void {
		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$conversationManager = new ConversationManager($mockClient);

		// Mock response with conversation data AFTER SQL filtering (CONTENT_QUESTION records excluded)
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
					[
						'data' => [
							['role' => 'user', 'message' => 'Show me movies about space', 'intent' => 'NEW_SEARCH'],
							['role' => 'assistant', 'message' => 'Here are some space movies...', 'intent' => null],
							['role' => 'user', 'message' => 'Show me more like this', 'intent' => 'INTEREST'],
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
						return str_contains($sql, 'intent != \'CONTENT_QUESTION\'');
					}
				)
			)
			->willReturn($mockResponse);

		$result = $conversationManager->getConversationHistoryForQueryGeneration('conv-123');

		// Should exclude the CONTENT_QUESTION exchange
		$expected = "user: Show me movies about space\n" .
			"assistant: Here are some space movies...\n" .
			"user: Show me more like this\n";
		$this->assertEquals($expected, $result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws JsonException
	 */
	public function testSaveMessageWithSearchContextStoresAllFields(): void {
		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$conversationManager = $this->createConversationManager($mockClient);

		$mockResponse = $this->createSuccessfulResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with(
				$this->callback(
					function ($sql) {
						return str_contains($sql, /** @lang manticore */ 'INSERT INTO rag_conversations')
							&& str_contains($sql, 'intent')
							&& str_contains($sql, 'search_query')
							&& str_contains($sql, 'exclude_query')
							&& str_contains($sql, 'excluded_ids');
					}
				)
			)
			->willReturn($mockResponse);

		$conversationManager->saveMessage(
			'conv-123',
			'model-456',
			'user',
			'Show me movies about space',
			150,
			'NEW_SEARCH',
			'movies about space',
			'Star Wars',
			['1', '2', '3']
		);
	}
}
