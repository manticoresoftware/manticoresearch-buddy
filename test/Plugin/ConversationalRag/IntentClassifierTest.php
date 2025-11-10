<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\IntentClassifier;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviderManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\BaseProvider;
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

	public function testClassifyIntentRejection(): void {
		$intentClassifier = new IntentClassifier();

		// Mock LLM provider
		/** @var BaseProvider $mockProvider */
		$mockProvider = $this->createMock(BaseProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => 'REJECTION',
				'metadata' => [],
				]
			);

		$mockProviderManager = $this->createMock(
			LLMProviderManager::class
		);
		$mockProviderManager->method('getConnection')
			->willReturn($mockProvider);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		$result = $intentClassifier->classifyIntent(
			'I already watched that movie',
			"user: I want to watch a comedy\nassistant: I recommend The Office\nuser: I already watched that",
			$mockProviderManager,
			$modelConfig
		);

		$this->assertEquals('REJECTION', $result['intent']);
		$this->assertIsFloat($result['confidence']);
	}

	public function testClassifyIntentAlternatives(): void {
		$intentClassifier = new IntentClassifier();

		// Mock LLM provider
		$mockProvider = $this->createMock(BaseProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => 'ALTERNATIVES',
				'metadata' => [],
				]
			);

		$mockProviderManager = $this->createMock(
			LLMProviderManager::class
		);
		$mockProviderManager->method('getConnection')
			->willReturn($mockProvider);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		$result = $intentClassifier->classifyIntent(
			'What else do you have?',
			"user: Show me comedies\nassistant: I recommend The Office\nuser: What else do you have?",
			$mockProviderManager,
			$modelConfig
		);

		$this->assertEquals('ALTERNATIVES', $result['intent']);
	}

	public function testClassifyIntentNewSearch(): void {
		$intentClassifier = new IntentClassifier();

		// Mock LLM provider
		$mockProvider = $this->createMock(BaseProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => 'NEW_SEARCH',
				'metadata' => [],
				]
			);

		$mockProviderManager = $this->createMock(
			LLMProviderManager::class
		);
		$mockProviderManager->method('getConnection')
			->willReturn($mockProvider);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		$result = $intentClassifier->classifyIntent(
			'Show me action movies',
			'', // No conversation history
			$mockProviderManager,
			$modelConfig
		);

		$this->assertEquals('NEW_SEARCH', $result['intent']);
	}

	public function testClassifyIntentLLMFailure(): void {
		$intentClassifier = new IntentClassifier();

		// Mock LLM provider that fails
		$mockProvider = $this->createMock(BaseProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => false,
				'error' => 'API Error',
				'metadata' => [],
				]
			);

		$mockProviderManager = $this->createMock(
			LLMProviderManager::class
		);
		$mockProviderManager->method('getConnection')
			->willReturn($mockProvider);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		$result = $intentClassifier->classifyIntent(
			'What movies do you recommend?',
			'',
			$mockProviderManager,
			$modelConfig
		);

		// Should fallback to NEW_SEARCH on failure
		$this->assertEquals('NEW_SEARCH', $result['intent']);
		$this->assertArrayHasKey('error', $result);
	}

	public function testGenerateQueriesWithExclusions(): void {
		$intentClassifier = new IntentClassifier();

		// Mock LLM provider
		$mockProvider = $this->createMock(BaseProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => "SEARCH_QUERY: action movies\nEXCLUDE_QUERY: comedy movies",
				'metadata' => [],
				]
			);

		$mockProviderManager = $this->createMock(
			LLMProviderManager::class
		);
		$mockProviderManager->method('getConnection')
			->willReturn($mockProvider);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		$result = $intentClassifier->generateQueries(
			'I want action movies but not comedies',
			'NEW_SEARCH',
			'',
			$mockProviderManager,
			$modelConfig
		);

		$this->assertEquals('action movies', $result['search_query']);
		$this->assertEquals('comedy movies', $result['exclude_query']);
	}

	public function testGenerateQueriesNoExclusions(): void {
		$intentClassifier = new IntentClassifier();

		// Mock LLM provider
		$mockProvider = $this->createMock(BaseProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => "SEARCH_QUERY: science fiction movies\nEXCLUDE_QUERY: none",
				'metadata' => [],
				]
			);

		$mockProviderManager = $this->createMock(
			LLMProviderManager::class
		);
		$mockProviderManager->method('getConnection')
			->willReturn($mockProvider);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		$result = $intentClassifier->generateQueries(
			'Show me science fiction movies',
			'NEW_SEARCH',
			'',
			$mockProviderManager,
			$modelConfig
		);

		$this->assertEquals('science fiction movies', $result['search_query']);
		$this->assertEquals('', $result['exclude_query']); // Should be empty when 'none'
	}

	public function testGenerateQueriesIntentBased(): void {
		$intentClassifier = new IntentClassifier();

		// Mock LLM provider
		$mockProvider = $this->createMock(BaseProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => "SEARCH_QUERY: similar to Inception\nEXCLUDE_QUERY: Inception",
				'metadata' => [],
				]
			);

		$mockProviderManager = $this->createMock(
			LLMProviderManager::class
		);
		$mockProviderManager->method('getConnection')
			->willReturn($mockProvider);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		$result = $intentClassifier->generateQueries(
			'I liked Inception, what else?',
			'INTEREST',
			"user: Show me Inception\nassistant: Here's Inception\nuser: I liked Inception, what else?",
			$mockProviderManager,
			$modelConfig
		);

		$this->assertEquals('similar to Inception', $result['search_query']);
		$this->assertEquals('Inception', $result['exclude_query']);
	}

	public function testLimitConversationHistoryShortHistory(): void {
		$intentClassifier = new IntentClassifier();

		$shortHistory = "user: hello\nassistant: hi\nuser: how are you?\nassistant: good";

		// Use reflection to access private method
		$reflection = new ReflectionClass($intentClassifier);
		$method = $reflection->getMethod('limitConversationHistory');
		$method->setAccessible(true);

		$result = $method->invoke($intentClassifier, $shortHistory);

		$this->assertEquals($shortHistory, $result);
	}

	public function testLimitConversationHistoryLongHistory(): void {
		$intentClassifier = new IntentClassifier();

		// Create history with more than 10 exchanges (20 lines)
		$longHistory = '';
		for ($i = 0; $i < 12; $i++) {
			$longHistory .= "user: message {$i}\nassistant: response {$i}\n";
		}

		// Use reflection to access private method
		$reflection = new ReflectionClass($intentClassifier);
		$method = $reflection->getMethod('limitConversationHistory');
		$method->setAccessible(true);

		$result = $method->invoke($intentClassifier, $longHistory);

		$lines = explode("\n", trim($result));
		$this->assertGreaterThanOrEqual(18, sizeof($lines)); // Should be limited, at least 9 exchanges (18 lines)
		$this->assertLessThanOrEqual(20, sizeof($lines)); // Should not exceed 10 exchanges (20 lines)
		$this->assertStringContainsString('message', $result); // Should contain messages
	}

	public function testValidateIntentValidIntents(): void {
		$intentClassifier = new IntentClassifier();

		$validIntents = [
			'REJECTION', 'ALTERNATIVES', 'TOPIC_CHANGE', 'INTEREST', 'NEW_SEARCH',
			'CONTENT_QUESTION', 'NEW_QUESTION', 'CLARIFICATION', 'UNCLEAR',
		];

		// Use reflection to access private method
		$reflection = new ReflectionClass($intentClassifier);
		$method = $reflection->getMethod('validateIntent');
		$method->setAccessible(true);

		foreach ($validIntents as $intent) {
			$result = $method->invoke($intentClassifier, $intent);
			$this->assertEquals($intent, $result);
		}

		// Test with extra text
		$result = $method->invoke($intentClassifier, 'I think this is REJECTION because...');
		$this->assertEquals('REJECTION', $result);

		// Test invalid intent
		$result = $method->invoke($intentClassifier, 'INVALID_INTENT');
		$this->assertEquals('NEW_SEARCH', $result); // Should default to NEW_SEARCH
	}
}
