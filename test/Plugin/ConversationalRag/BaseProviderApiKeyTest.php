<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\BaseProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\OpenAIProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ModelManager;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use PHPUnit\Framework\TestCase;

/**
 * Test class for API key resolution in ConversationalRag plugin
 * Tests the runtime validation that happens during CALL RAG operations
 * This test can run standalone without the full Buddy bootstrap
 */
class BaseProviderApiKeyTest extends TestCase {
	private ModelManager $modelManager;
	private BaseProvider $baseProvider;

	/**
	 * Test successful API key resolution for supported providers
	 * This simulates what happens during CALL RAG when a valid provider and env var exist
	 */
	public function testApiKeyResolutionSuccessful(): void {
		$reflection = new ReflectionClass($this->baseProvider);
		$method = $reflection->getMethod('getApiKeyForProvider');
		$method->setAccessible(true);

		// Test OpenAI provider resolution - simulates CALL RAG with openai provider
		$result = $method->invoke($this->baseProvider, 'openai');
		$this->assertEquals('sk-test-key-12345678901234567890123456789012', $result);

		// Test Anthropic provider resolution - simulates CALL RAG with anthropic provider
		$result = $method->invoke($this->baseProvider, 'anthropic');
		$this->assertEquals('sk-ant-test123456789012345678901234567890', $result);
	}

	/**
	 * Test API key resolution fails for unsupported providers
	 * This simulates CALL RAG failing when an invalid provider is specified
	 */
	public function testApiKeyResolutionUnsupportedProvider(): void {
		$reflection = new ReflectionClass($this->baseProvider);
		$method = $reflection->getMethod('getApiKeyForProvider');
		$method->setAccessible(true);

		// Simulates CALL RAG failing when model has invalid provider like 'unsupported'
		$this->expectException(QueryParseError::class);
		$this->expectExceptionMessage("Unsupported LLM provider: 'unsupported'");

		$method->invoke($this->baseProvider, 'unsupported');
	}

	/**
	 * Test API key resolution fails when environment variable is missing
	 * This simulates CALL RAG failing when the required env var isn't set
	 */
	public function testApiKeyResolutionMissingEnvVar(): void {
		$reflection = new ReflectionClass($this->baseProvider);
		$method = $reflection->getMethod('getApiKeyForProvider');
		$method->setAccessible(true);

		// Simulates CALL RAG failing when GROK_API_KEY environment variable is not set
		$this->expectException(QueryParseError::class);
		$this->expectExceptionMessage("Environment variable 'GROK_API_KEY' not found or empty");

		$method->invoke($this->baseProvider, 'grok');
	}

	/**
	 * Test API key resolution fails when environment variable is empty
	 * This simulates CALL RAG failing when the env var exists but is empty
	 */
	public function testApiKeyResolutionEmptyEnvVar(): void {
		$reflection = new ReflectionClass($this->baseProvider);
		$method = $reflection->getMethod('getApiKeyForProvider');
		$method->setAccessible(true);

		// Simulates CALL RAG failing when GROK_API_KEY exists but is empty
		putenv('GROK_API_KEY=');

		try {
			$this->expectException(QueryParseError::class);
			$this->expectExceptionMessage("Environment variable 'GROK_API_KEY' not found or empty");

			$method->invoke($this->baseProvider, 'grok');
		} finally {
			// Clean up
			putenv('GROK_API_KEY');
		}
	}

	/**
	 * Test API key resolution fails when provider is not configured
	 * This simulates CALL RAG failing when no provider is specified in the model
	 */
	public function testApiKeyResolutionEmptyProvider(): void {
		$reflection = new ReflectionClass($this->baseProvider);
		$method = $reflection->getMethod('getApiKeyForProvider');
		$method->setAccessible(true);

		// Simulates CALL RAG failing when provider string is empty
		$this->expectException(QueryParseError::class);
		$this->expectExceptionMessage('LLM provider not configured');

		$method->invoke($this->baseProvider, '');
	}

	/**
	 * Test the getApiKey method works when provider is properly configured
	 * This simulates the normal CALL RAG flow with valid configuration
	 */
	public function testGetApiKeyWithValidConfig(): void {
		$reflection = new ReflectionClass($this->baseProvider);
		$method = $reflection->getMethod('getApiKey');
		$method->setAccessible(true);

		// Simulates normal CALL RAG flow: model configured with openai provider
		$this->baseProvider->configure(['llm_provider' => 'openai']);

		$result = $method->invoke($this->baseProvider);
		$this->assertEquals('sk-test-key-12345678901234567890123456789012', $result);
	}

	/**
	 * Test getApiKey fails when provider configuration is missing
	 * This simulates CALL RAG failing when the model wasn't configured with a provider
	 */
	public function testGetApiKeyMissingProviderConfig(): void {
		$reflection = new ReflectionClass($this->baseProvider);
		$method = $reflection->getMethod('getApiKey');
		$method->setAccessible(true);

		// Simulates CALL RAG failing when model was created without specifying llm_provider
		$this->baseProvider->configure([]);

		$this->expectException(QueryParseError::class);
		$this->expectExceptionMessage('LLM provider not configured');

		$method->invoke($this->baseProvider);
	}

	protected function setUp(): void {
		$this->modelManager = new ModelManager();
		$this->baseProvider = new OpenAIProvider();

		// Set up test environment variables
		putenv('OPENAI_API_KEY=sk-test-key-12345678901234567890123456789012');
		putenv('ANTHROPIC_API_KEY=sk-ant-test123456789012345678901234567890');
		putenv('EMPTY_KEY=');
	}

	protected function tearDown(): void {
		// Clean up environment variables
		putenv('OPENAI_API_KEY');
		putenv('ANTHROPIC_API_KEY');
		putenv('EMPTY_KEY');
	}
}
