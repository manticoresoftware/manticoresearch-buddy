<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationHistory;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationMessage;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Intent;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\IntentClassifier;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LlmProvider;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IntentClassifierTest extends TestCase {
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
	 * @param array<string, array{user?: string, assistant?: string}> $turns
	 */
	private function history(array $turns): ConversationHistory {
		$messages = [];
		foreach ($turns as $turn) {
			if (isset($turn['user'])) {
				$messages[] = ConversationMessage::user($turn['user'], Intent::NEW);
			}

			if (!isset($turn['assistant'])) {
				continue;
			}

			$messages[] = ConversationMessage::assistant($turn['assistant'], Intent::NEW);
		}

		return new ConversationHistory($messages);
	}

	public function testClassifyIntentRejection(): void {
		$intentClassifier = new IntentClassifier();

			$modelConfig = ['model' => 'openai:gpt-4'];

			// Mock LLM provider
			/** @var MockObject&LlmProvider $mockProvider */
			$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
			$mockProvider->method('generateResponse')
				->willReturn(
					[
					'success' => true,
					'content' => 'REJECT',
					'metadata' => [],
					]
				);

		$history = $this->history(
			[
			'1970-01-01T00:00:00.000000Z' => [
				'user' => 'I want to watch a comedy',
				'assistant' => 'I recommend The Office',
			],
			'1970-01-01T00:00:01.000000Z' => [
				'user' => 'I already watched that',
			],
			]
		);

		$intent = $intentClassifier->classifyIntent(
			'I already watched that movie', $history, $mockProvider, $modelConfig
		);

		$this->assertEquals('REJECT', $intent);
	}

	public function testClassifyIntentAlternatives(): void {
		$intentClassifier = new IntentClassifier();

			$modelConfig = ['model' => 'openai:gpt-4'];

			// Mock LLM provider
			/** @var MockObject&LlmProvider $mockProvider */
			$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => 'EXPAND',
				'metadata' => [],
				]
			);

		$history = $this->history(
			[
			'1970-01-01T00:00:00.000000Z' => [
				'user' => 'Show me comedies',
				'assistant' => 'I recommend The Office',
			],
			'1970-01-01T00:00:01.000000Z' => [
				'user' => 'What else do you have?',
			],
			]
		);

		$intent = $intentClassifier->classifyIntent(
			'What else do you have?', $history, $mockProvider, $modelConfig
		);

		$this->assertEquals('EXPAND', $intent);
	}

	public function testClassifyIntentNewSearch(): void {
		$intentClassifier = new IntentClassifier();

			$modelConfig = ['model' => 'openai:gpt-4'];

			// Mock LLM provider
			/** @var MockObject&LlmProvider $mockProvider */
			$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => 'NEW',
				'metadata' => [],
				]
			);

		$intent = $intentClassifier->classifyIntent(
			'Show me action movies',
			$this->history([]),
			$mockProvider,
			$modelConfig
		);

		$this->assertEquals('NEW', $intent);
	}

	public function testClassifyIntentLLMFailure(): void {
		$intentClassifier = new IntentClassifier();

			$modelConfig = ['model' => 'openai:gpt-4'];

			// Mock LLM provider that fails
			/** @var MockObject&LlmProvider $mockProvider */
			$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => false,
				'error' => 'API Error',
				'metadata' => [],
				]
			);

		$intent = $intentClassifier->classifyIntent(
			'What movies do you recommend?',
			$this->history([]),
			$mockProvider,
			$modelConfig
		);

		// Should fallback to NEW on failure
		$this->assertEquals('NEW', $intent);
	}

	public function testClassifyIntentUnexpectedResponseFallsBackToNew(): void {
		$intentClassifier = new IntentClassifier();

		$modelConfig = ['model' => 'openai:gpt-4'];

		/** @var MockObject&LlmProvider $mockProvider */
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
					'success' => true,
					'content' => 'MAYBE',
					'metadata' => [],
				]
			);

		$intent = $intentClassifier->classifyIntent(
			'What movies do you recommend?',
			$this->history([]),
			$mockProvider,
			$modelConfig
		);

		$this->assertEquals('NEW', $intent);
	}

	public function testClassifyIntentUnclearFallsBackToNew(): void {
		$intentClassifier = new IntentClassifier();

		$modelConfig = ['model' => 'openai:gpt-4'];

		/** @var MockObject&LlmProvider $mockProvider */
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
					'success' => true,
					'content' => 'UNCLEAR',
					'metadata' => [],
				]
			);

		$intent = $intentClassifier->classifyIntent(
			'???',
			$this->history([]),
			$mockProvider,
			$modelConfig
		);

		$this->assertEquals('NEW', $intent);
	}

	public function testGenerateQueriesWithExclusions(): void {
		$intentClassifier = new IntentClassifier();

			$modelConfig = ['model' => 'openai:gpt-4'];

			// Mock LLM provider
			/** @var MockObject&LlmProvider $mockProvider */
			$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => '{"search_keywords":[{"term":"action movies","confidence":95}],'
					. '"exclude_query":["comedy movies"]}',
				'metadata' => [],
				]
			);

		$result = $intentClassifier->generateQueries(
			'I want action movies but not comedies',
			'NEW',
			[],
			$mockProvider,
			$modelConfig
		);

		$this->assertEquals('action movies', $result['search_query']);
		$this->assertEquals('comedy movies', $result['exclude_query']);
	}

	public function testGenerateQueriesNoExclusions(): void {
		$intentClassifier = new IntentClassifier();

			$modelConfig = ['model' => 'openai:gpt-4'];

			// Mock LLM provider
			/** @var MockObject&LlmProvider $mockProvider */
			$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => '{"search_keywords":[{"term":"science fiction movies","confidence":92}],'
					. '"exclude_query":["none"]}',
				'metadata' => [],
				]
			);

		$result = $intentClassifier->generateQueries(
			'Show me science fiction movies',
			'NEW',
			[],
			$mockProvider,
			$modelConfig
		);

		$this->assertEquals('science fiction movies', $result['search_query']);
		$this->assertEquals('', $result['exclude_query']); // Should be empty when 'none'
	}

	public function testGenerateQueriesIntentBased(): void {
		$intentClassifier = new IntentClassifier();

			$modelConfig = ['model' => 'openai:gpt-4'];

			// Mock LLM provider
			/** @var MockObject&LlmProvider $mockProvider */
			$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => '{"search_keywords":[{"term":"similar to Inception","confidence":88}],'
					. '"exclude_query":["Inception"]}',
				'metadata' => [],
				]
			);

		$result = $intentClassifier->generateQueries(
			'I liked Inception, what else?',
			'REFINE',
			[
				'1970-01-01T00:00:00.000000Z' => [
					'user' => 'Show me Inception',
					'assistant' => "Here's Inception",
				],
				'1970-01-01T00:00:01.000000Z' => [
					'user' => 'I liked Inception, what else?',
				],
			],
			$mockProvider,
			$modelConfig
		);

		$this->assertEquals('similar to Inception', $result['search_query']);
		$this->assertEquals('Inception', $result['exclude_query']);
	}

	public function testGenerateQueriesParsesFencedJson(): void {
		$intentClassifier = new IntentClassifier();

		$modelConfig = ['model' => 'openai:gpt-4'];

		/** @var MockObject&LlmProvider $mockProvider */
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
					'success' => true,
					'content' => "```json\n"
						. '{"search_keywords":[{"term":"RAG explanation","confidence":95}],'
						. '"exclude_query":["none"]}' . "\n```",
					'metadata' => [],
				]
			);

		$result = $intentClassifier->generateQueries(
			'Explain RAG',
			'NEW',
			[],
			$mockProvider,
			$modelConfig
		);

		$this->assertEquals('RAG explanation', $result['search_query']);
		$this->assertEquals('', $result['exclude_query']);
	}

	public function testGenerateQueriesFallsBackToUserQueryWhenLlmReturnsEmptyKeywords(): void {
		$intentClassifier = new IntentClassifier();

		$modelConfig = ['model' => 'openai:gpt-4'];

		/** @var MockObject&LlmProvider $mockProvider */
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
					'success' => true,
					'content' => '{"search_keywords":[],"exclude_query":["none"]}',
					'metadata' => [],
				]
			);

		$result = $intentClassifier->generateQueries(
			'ok bye',
			'NEW',
			[],
			$mockProvider,
			$modelConfig
		);

		$this->assertEquals('ok bye', $result['search_query']);
		$this->assertEquals('', $result['exclude_query']);
	}

	public function testGenerateQueriesThrowsOnInvalidJson(): void {
		$intentClassifier = new IntentClassifier();

		$modelConfig = ['model' => 'openai:gpt-4'];

		/** @var MockObject&LlmProvider $mockProvider */
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
					'success' => true,
					'content' => '```json',
					'metadata' => [],
				]
			);

		$this->expectException(ManticoreSearchClientError::class);

		$intentClassifier->generateQueries(
			'Explain RAG',
			'NEW',
			[],
			$mockProvider,
			$modelConfig
		);
	}

	public function testGenerateQueriesFailureIncludesProviderDetails(): void {
		$intentClassifier = new IntentClassifier();

		$modelConfig = ['model' => 'openrouter:google/gemma-4-31b-it:free'];

		/** @var MockObject&LlmProvider $mockProvider */
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
					'success' => false,
					'error' => 'LLM request failed',
					'content' => '',
					'provider' => 'llm',
					'details' => 'OpenRouter API error 429 Too Many Requests',
				]
			);

		try {
			$intentClassifier->generateQueries(
				'tv shows?',
				'NEW',
				[],
				$mockProvider,
				$modelConfig
			);
			$this->fail('Expected query generation failure');
		} catch (ManticoreSearchClientError $e) {
			$this->assertStringContainsString(
				'Query generation failed: LLM request failed: OpenRouter API error 429 Too Many Requests',
				$e->getResponseError()
			);
		}
	}

	public function testBuildHistoryPayloadShortHistory(): void {
		$messages = [
			['role' => 'user', 'message' => 'hello'],
			['role' => 'assistant', 'message' => 'hi'],
			['role' => 'user', 'message' => 'how are you?'],
			['role' => 'assistant', 'message' => 'good'],
		];

		$this->assertEquals(
			[
				'1970-01-01T00:00:00.000000Z' => [
					'user' => 'hello',
					'assistant' => 'hi',
				],
				'1970-01-01T00:00:01.000000Z' => [
					'user' => 'how are you?',
					'assistant' => 'good',
				],
			],
			$this->createConversationHistory($messages)->payload()
		);
	}

	public function testBuildHistoryPayloadLimitsHistory(): void {
		$messages = [];
		for ($i = 0; $i < 12; $i++) {
			$messages[] = ['role' => 'user', 'message' => "message {$i}"];
			$messages[] = ['role' => 'assistant', 'message' => "response {$i}"];
		}

		$result = $this->createConversationHistory($messages)->payload();

		$this->assertCount(10, $result);
		$this->assertEquals(
			[
				'user' => 'message 2',
				'assistant' => 'response 2',
			],
			$result['1970-01-01T00:00:00.000000Z']
		);
		$this->assertEquals(
			[
				'user' => 'message 11',
				'assistant' => 'response 11',
			],
			$result['1970-01-01T00:00:09.000000Z']
		);
	}

	public function testBuildHistoryPayloadPreservesMultilineMessages(): void {
		$result = $this->createConversationHistory(
			[
				['role' => 'user', 'message' => 'tv shows?'],
				[
					'role' => 'assistant',
					'message' => "1. Game of Thrones\n\n2. Breaking Bad\n\n3. Stranger Things",
				],
				['role' => 'user', 'message' => 'what is the cast in GoT?'],
				[
					'role' => 'assistant',
					'message' => "Main cast:\n\n- Emilia Clarke\n- Kit Harington",
				],
				['role' => 'user', 'message' => 'aaa, I saw this show'],
			]
		)->payload();

		$this->assertEquals(
			[
				'user' => 'tv shows?',
				'assistant' => "1. Game of Thrones\n\n2. Breaking Bad\n\n3. Stranger Things",
			],
			$result['1970-01-01T00:00:00.000000Z']
		);
		$this->assertEquals(
			[
				'user' => 'what is the cast in GoT?',
				'assistant' => "Main cast:\n\n- Emilia Clarke\n- Kit Harington",
			],
			$result['1970-01-01T00:00:01.000000Z']
		);
		$this->assertEquals(
			[
				'user' => 'aaa, I saw this show',
			],
			$result['1970-01-01T00:00:02.000000Z']
		);
	}

	public function testBuildHistoryPayloadGroupsTurnsByTimestamp(): void {
		$result = $this->createConversationHistory(
			[
				['role' => 'user', 'message' => 'tv shows?'],
				['role' => 'assistant', 'message' => "1. **Game of Thrones**\n- fantasy drama"],
				['role' => 'user', 'message' => 'what is the cast in GoT?'],
				['role' => 'assistant', 'message' => "Main cast:\n- Emilia Clarke\n- Kit Harington"],
				['role' => 'user', 'message' => 'aaa, I saw this show'],
			]
		)->payload();

		$this->assertEquals(
			[
				'1970-01-01T00:00:00.000000Z' => [
					'user' => 'tv shows?',
					'assistant' => "1. **Game of Thrones**\n- fantasy drama",
				],
				'1970-01-01T00:00:01.000000Z' => [
					'user' => 'what is the cast in GoT?',
					'assistant' => "Main cast:\n- Emilia Clarke\n- Kit Harington",
				],
				'1970-01-01T00:00:02.000000Z' => [
					'user' => 'aaa, I saw this show',
				],
			],
			$result
		);
	}

	public function testValidateIntentValidIntents(): void {
		$intentClassifier = new IntentClassifier();

		$validIntents = [
			'FOLLOW_UP', 'REFINE', 'EXPAND', 'REJECT', 'NEW', 'UNCLEAR',
		];

		// Use reflection to access private method
		$reflection = new ReflectionClass($intentClassifier);
		$method = $reflection->getMethod('validateIntent');
		$method->setAccessible(true);

		foreach ($validIntents as $intent) {
			$result = $method->invoke($intentClassifier, $intent);
			$this->assertEquals($intent, $result);
		}

		$result = $method->invoke($intentClassifier, ' REJECT ');
		$this->assertEquals('REJECT', $result);

		// Test invalid intent
		$result = $method->invoke($intentClassifier, 'I think this is REJECT because...');
		$this->assertNull($result);

		$result = $method->invoke($intentClassifier, 'INVALID_INTENT');
		$this->assertNull($result);
	}

	/**
	 * @param array<int, array{role:string, message:string}> $messages
	 */
	private function createConversationHistory(array $messages): ConversationHistory {
		$conversationMessages = [];
		foreach ($messages as $message) {
			$conversationMessages[] = new ConversationMessage(
				$message['role'],
				$message['message'],
				'',
				'',
				'',
				''
			);
		}

		return new ConversationHistory($conversationMessages);
	}
}
