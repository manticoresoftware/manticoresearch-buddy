<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\DynamicThresholdManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LlmProvider;
use PHPUnit\Framework\TestCase;

class DynamicThresholdManagerTest extends TestCase {
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

	public function testCalculateDynamicThresholdNoExpansion(): void {
		$thresholdManager = new DynamicThresholdManager();

		// Provider shouldn't be called when there is no history
		$mockProvider = $this->createMock(LlmProvider::class);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		$result = $thresholdManager->calculateDynamicThreshold(
			'Show me comedies',
			'', // No history
			$mockProvider,
			$modelConfig,
			0.8
		);

		$this->assertEquals(0.8, $result['threshold']); // Should use base threshold
		$this->assertEquals(0, $result['expansion_level']);
		$this->assertFalse($result['is_expanded']);
		$this->assertEquals(0, $result['expansion_percent']);
	}

	public function testCalculateDynamicThresholdWithExpansion(): void {
		$thresholdManager = new DynamicThresholdManager();

		// Mock LLM provider that says yes to expansion
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => 'yes',
				'metadata' => [],
				]
			);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		$result = $thresholdManager->calculateDynamicThreshold(
			'What else do you have?',
			"user: Show me comedies\nassistant: I recommend The Office\nuser: What else do you have?",
			$mockProvider,
			$modelConfig,
			0.8
		);

		$this->assertGreaterThan(0.8, $result['threshold']); // Should be expanded
		$this->assertEquals(1, $result['expansion_level']);
		$this->assertTrue($result['is_expanded']);
		$this->assertGreaterThan(0, $result['expansion_percent']);
	}

	public function testCalculateDynamicThresholdMaxExpansion(): void {
		$thresholdManager = new DynamicThresholdManager();

		// Mock LLM provider that always says yes
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => 'yes',
				'metadata' => [],
				]
			);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		// Call multiple times to reach max expansion
		for ($i = 0; $i < 6; $i++) {
			$result = $thresholdManager->calculateDynamicThreshold(
				'What else?',
				"user: Show me comedies\nassistant: I recommend The Office\nuser: What else?",
				$mockProvider,
				$modelConfig,
				0.8
			);
		}

		$this->assertEquals(5, $result['expansion_level']); // Max expansion level
		$this->assertTrue($result['expansion_limit_reached']);
		$this->assertEquals(0.8 * 1.2, $result['max_threshold']); // 20% expansion
	}

	public function testDetectExpansionIntentNoHistory(): void {
		$thresholdManager = new DynamicThresholdManager();

		$mockProvider = $this->createMock(LlmProvider::class);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		// Use reflection to access private method
		$reflection = new ReflectionClass($thresholdManager);
		$method = $reflection->getMethod('detectExpansionIntent');
		$method->setAccessible(true);

		$result = $method->invoke($thresholdManager, 'Show me movies', '', $mockProvider, $modelConfig);

		$this->assertFalse($result); // Should be false with no history
	}

	public function testDetectExpansionIntentWithHistory(): void {
		$thresholdManager = new DynamicThresholdManager();

		// Mock LLM provider that says yes
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => 'yes',
				'metadata' => [],
				]
			);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		// Use reflection to access private method
		$reflection = new ReflectionClass($thresholdManager);
		$method = $reflection->getMethod('detectExpansionIntent');
		$method->setAccessible(true);

		$result = $method->invoke(
			$thresholdManager, 'What else do you have?',
			"user: Show me comedies\nassistant: I recommend The Office\nuser: What else do you have?",
			$mockProvider, $modelConfig
		);

		$this->assertTrue($result);
	}

	public function testDetectExpansionIntentLLMFailure(): void {
		$thresholdManager = new DynamicThresholdManager();

		// Mock LLM provider that fails
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => false,
				'error' => 'API Error',
				'metadata' => [],
				]
			);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		// Use reflection to access private method
		$reflection = new ReflectionClass($thresholdManager);
		$method = $reflection->getMethod('detectExpansionIntent');
		$method->setAccessible(true);

		$result = $method->invoke(
			$thresholdManager, 'What else?',
			"user: Show me comedies\nassistant: I recommend The Office\nuser: What else?",
			$mockProvider, $modelConfig
		);

		$this->assertFalse($result); // Should return false on failure
	}

	public function testExpansionStateResetOnNewConversation(): void {
		$thresholdManager = new DynamicThresholdManager();

		// Mock LLM provider that says yes
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->method('generateResponse')
			->willReturn(
				[
				'success' => true,
				'content' => 'yes',
				'metadata' => [],
				]
			);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		// First call with one conversation
		$result1 = $thresholdManager->calculateDynamicThreshold(
			'What else?',
			'conversation1: user message',
			$mockProvider,
			$modelConfig,
			0.8
		);

		$this->assertEquals(1, $result1['expansion_level']);

		// Second call with different conversation (should reset)
		$result2 = $thresholdManager->calculateDynamicThreshold(
			'What else?',
			'conversation2: different user message',
			$mockProvider,
			$modelConfig,
			0.8
		);

		$this->assertEquals(1, $result2['expansion_level']); // Should be 1, not 2
	}

	public function testExpansionStateResetOnTopicChange(): void {
		$thresholdManager = new DynamicThresholdManager();

		// Mock LLM provider - first says yes, then no (topic change)
		$mockProvider = $this->createMock(LlmProvider::class);
		$mockProvider->expects($this->exactly(2))
			->method('generateResponse')
			->willReturnOnConsecutiveCalls(
				['success' => true, 'content' => 'yes', 'metadata' => []],
				['success' => true, 'content' => 'no', 'metadata' => []]
			);

		$modelConfig = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		// First call - expansion
		$result1 = $thresholdManager->calculateDynamicThreshold(
			'What else?',
			'conversation: user wants comedies',
			$mockProvider,
			$modelConfig,
			0.8
		);

		$this->assertEquals(1, $result1['expansion_level']);

		// Second call - no expansion (topic change)
		$result2 = $thresholdManager->calculateDynamicThreshold(
			'Now show me action movies',
			'conversation: user wants comedies, now wants action',
			$mockProvider,
			$modelConfig,
			0.8
		);

		$this->assertEquals(0, $result2['expansion_level']); // Should reset to 0
	}
}
