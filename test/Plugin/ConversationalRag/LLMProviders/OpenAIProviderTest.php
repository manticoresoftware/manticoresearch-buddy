<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\OpenAIProvider;
use PHPUnit\Framework\TestCase;

class OpenAIProviderTest extends TestCase {
	private OpenAIProvider $provider;

	protected function setUp(): void {
		$this->provider = new OpenAIProvider();
		putenv('OPENAI_API_KEY=test-key-123');
	}

	protected function tearDown(): void {
		putenv('OPENAI_API_KEY');
	}

	public function testGetName(): void {
		$this->assertEquals('openai', $this->provider->getName());
	}

	public function testGenerateResponseMissingApiKey(): void {
		$this->provider->configure(['llm_provider' => 'openai']);
		putenv('OPENAI_API_KEY'); // Remove API key

		$result = $this->provider->generateResponse('Test prompt');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('OpenAI request failed', is_string($result['error']) ? $result['error'] : '');
		$details = $result['details'] ?? $result['error'];
		$this->assertStringContainsString('not found or empty', is_string($details) ? $details : '');
	}

	public function testGenerateResponseDefaultModel(): void {
		$this->provider->configure(['llm_provider' => 'openai']);

		// Create a partial mock to avoid actual HTTP calls
		$mockProvider = $this->getMockBuilder(OpenAIProvider::class)
			->onlyMethods(['makeRequest'])
			->getMock();

		$mockProvider->configure(['llm_provider' => 'openai']);

		$mockResponse = [
			'choices' => [['message' => ['content' => 'Default model response']]],
			'usage' => ['total_tokens' => 100],
		];

		$mockProvider->expects($this->once())
			->method('makeRequest')
			->willReturn(['success' => true, 'data' => $mockResponse]);

		$result = $mockProvider->generateResponse('Test prompt');

		$this->assertTrue($result['success']);
		$this->assertEquals('Default model response', $result['content']);
	}

	public function testGenerateResponseCustomSettings(): void {
		$mockProvider = $this->getMockBuilder(OpenAIProvider::class)
			->onlyMethods(['makeRequest'])
			->getMock();

		$mockProvider->configure(
			[
			'llm_provider' => 'openai',
			'llm_model' => 'gpt-4o',
			'temperature' => 0.8,
			'max_tokens' => 2000,
			'top_p' => 0.9,
			]
		);

		$mockResponse = [
			'choices' => [['message' => ['content' => 'Custom settings response']]],
			'usage' => ['total_tokens' => 150],
		];

		$mockProvider->expects($this->once())
			->method('makeRequest')
			->willReturn(['success' => true, 'data' => $mockResponse]);

		$result = $mockProvider->generateResponse('Test prompt');

		$this->assertTrue($result['success']);
		$this->assertEquals('Custom settings response', $result['content']);
	}

	public function testGenerateResponseMakeRequestFailure(): void {
		$mockProvider = $this->getMockBuilder(OpenAIProvider::class)
			->onlyMethods(['makeRequest'])
			->getMock();

		$mockProvider->configure(['llm_provider' => 'openai']);

		$mockProvider->expects($this->once())
			->method('makeRequest')
			->willReturn(['success' => false, 'error' => 'API Error']);

		$result = $mockProvider->generateResponse('Test prompt');

		$this->assertFalse($result['success']);
		$this->assertEquals('API Error', $result['error']);
	}

	public function testGenerateResponseExceptionHandling(): void {
		$mockProvider = $this->getMockBuilder(OpenAIProvider::class)
			->onlyMethods(['getApiKey'])
			->getMock();

		$mockProvider->configure(['llm_provider' => 'openai']);

		$mockProvider->expects($this->once())
			->method('getApiKey')
			->willThrowException(new Exception('API key error'));

		$result = $mockProvider->generateResponse('Test prompt');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('OpenAI request failed', is_string($result['error']) ? $result['error'] : '');
	}

	public function testCreateClientReturnsCurlHandle(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('createClient');
		$method->setAccessible(true);

		$client = $method->invoke($this->provider);

		$this->assertInstanceOf(CurlHandle::class, $client);
	}
}
