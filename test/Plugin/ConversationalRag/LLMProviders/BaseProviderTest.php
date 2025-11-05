<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\BaseProvider;
use PHPUnit\Framework\TestCase;

/**
 * Concrete implementation of BaseProvider for testing
 */
class TestableBaseProvider extends BaseProvider {
	public function generateResponse(string $prompt, array $options = []): array {
		return ['success' => true, 'content' => 'test response'];
	}

	public function getSupportedModels(): array {
		return ['test-model'];
	}

	protected function createClient(): object {
		return (object)['test' => 'client'];
	}

	public function getName(): string {
		return 'test_provider';
	}
}

class BaseProviderTest extends TestCase {
	private TestableBaseProvider $provider;

	protected function setUp(): void {
		$this->provider = new TestableBaseProvider();
	}

	protected function tearDown(): void {
		// Clean up environment variables
		putenv('OPENAI_API_KEY');
		putenv('ANTHROPIC_API_KEY');
	}

	public function testConfigure_ResetsClient(): void {
		$config = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		// Configure once
		$this->provider->configure($config);

		// Get client to initialize it using reflection
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getClient');
		$method->setAccessible(true);
		$client1 = $method->invoke($this->provider);

		// Configure again
		$this->provider->configure($config);

		// Client should be reset
		$client2 = $method->invoke($this->provider);

		$this->assertNotSame($client1, $client2);
	}

	public function testValidateConfig_MissingFields(): void {
		$config = ['field1' => 'value1'];
		$required = ['field1', 'field2'];

		$this->expectException(\Manticoresearch\Buddy\Core\Error\QueryParseError::class);
		$this->expectExceptionMessage("Required configuration field 'field2' is missing");

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('validateConfig');
		$method->setAccessible(true);

		$method->invoke($this->provider, $config, $required);
	}

	public function testValidateConfig_AllFieldsPresent(): void {
		$config = ['field1' => 'value1', 'field2' => 'value2'];
		$required = ['field1', 'field2'];

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('validateConfig');
		$method->setAccessible(true);

		// Should not throw exception
		$method->invoke($this->provider, $config, $required);
		$this->assertTrue(true); // If we get here, test passes
	}

	public function testGetSettings_MergesOverrides(): void {
		$config = [
			'settings' => ['temperature' => 0.5, 'max_tokens' => 500],
			'temperature' => 0.7,
			'top_p' => 0.9,
		];

		$this->provider->configure($config);

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getSettings');
		$method->setAccessible(true);

		$overrides = ['temperature' => 0.8, 'frequency_penalty' => 0.1];
		$result = $method->invoke($this->provider, $overrides);

		$this->assertEquals(0.8, $result['temperature']); // Override takes precedence
		$this->assertEquals(500, $result['max_tokens']); // From settings
		$this->assertEquals(0.9, $result['top_p']); // From config
		$this->assertEquals(0.1, $result['frequency_penalty']); // From overrides
	}

	public function testGetSettings_FromJsonString(): void {
		$config = [
			'settings' => '{"temperature":0.6,"max_tokens":600}',
			'temperature' => 0.7, // This should override the JSON
		];

		$this->provider->configure($config);

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getSettings');
		$method->setAccessible(true);

		$result = $method->invoke($this->provider, []);

		$this->assertEquals(0.7, $result['temperature']); // Direct config overrides JSON
		$this->assertEquals(600, $result['max_tokens']); // From JSON
	}

	public function testConvertSettingsTypes_NumericStrings(): void {
		$settings = [
			'temperature' => '0.7',
			'max_tokens' => '1000',
			'k_results' => '5',
			'top_p' => '0.9',
			'non_numeric' => 'text',
		];

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('convertSettingsTypes');
		$method->setAccessible(true);

		$result = $method->invoke($this->provider, $settings);

		$this->assertIsFloat($result['temperature']);
		$this->assertEquals(0.7, $result['temperature']);
		$this->assertIsInt($result['max_tokens']);
		$this->assertEquals(1000, $result['max_tokens']);
		$this->assertIsInt($result['k_results']);
		$this->assertEquals(5, $result['k_results']);
		$this->assertIsFloat($result['top_p']);
		$this->assertEquals(0.9, $result['top_p']);
		$this->assertEquals('text', $result['non_numeric']); // Unchanged
	}

	public function testConvertToFloat_ValidNumeric(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('convertToFloat');
		$method->setAccessible(true);

		$this->assertEquals(3.14, $method->invoke($this->provider, '3.14'));
		$this->assertEquals(42.0, $method->invoke($this->provider, '42'));
		$this->assertEquals('not_numeric', $method->invoke($this->provider, 'not_numeric'));
		$this->assertEquals(3.14, $method->invoke($this->provider, 3.14)); // Already float
	}

	public function testConvertToInt_ValidNumeric(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('convertToInt');
		$method->setAccessible(true);

		$this->assertEquals(42, $method->invoke($this->provider, '42'));
		$this->assertEquals(3, $method->invoke($this->provider, '3.14')); // Truncated
		$this->assertEquals('not_numeric', $method->invoke($this->provider, 'not_numeric'));
		$this->assertEquals(42, $method->invoke($this->provider, 42)); // Already int
	}

	public function testGetConfig_WithDefault(): void {
		$config = ['existing_key' => 'value'];
		$this->provider->configure($config);

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getConfig');
		$method->setAccessible(true);

		$this->assertEquals('value', $method->invoke($this->provider, 'existing_key'));
		$this->assertEquals('default', $method->invoke($this->provider, 'missing_key', 'default'));
		$this->assertNull($method->invoke($this->provider, 'missing_key'));
	}

	public function testEstimateTokens(): void {
		$this->assertEquals(1, $this->provider->estimateTokens('test')); // 4 chars / 4 = 1
		$this->assertEquals(1, $this->provider->estimateTokens('abcd')); // 4 chars / 4 = 1
		$this->assertEquals(2, $this->provider->estimateTokens('abcde')); // 5 chars / 4 = 1.25, ceil to 2
		$this->assertEquals(3, $this->provider->estimateTokens('abcdefghijk')); // 12 chars / 4 = 3
	}

	public function testGetStylePrompt_Default(): void {
		$this->provider->configure([]);

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getStylePrompt');
		$method->setAccessible(true);

		$result = $method->invoke($this->provider);
		$this->assertStringContainsString('helpful AI assistant', $result);
	}

	public function testGetStylePrompt_Custom(): void {
		$this->provider->configure(['style_prompt' => 'Custom prompt']);

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getStylePrompt');
		$method->setAccessible(true);

		$result = $method->invoke($this->provider);
		$this->assertEquals('Custom prompt', $result);
	}

	public function testFormatError(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('formatError');
		$method->setAccessible(true);

		$exception = new Exception('Test exception');
		$result = $method->invoke($this->provider, 'Test message', $exception);

		$this->assertEquals(
			[
			'success' => false,
			'error' => 'Test message',
			'details' => 'Test exception',
			'provider' => 'test_provider',
			], $result
		);
	}

	public function testFormatSuccess(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('formatSuccess');
		$method->setAccessible(true);

		$this->provider->configure(['llm_model' => 'test-model']);
		$result = $method->invoke($this->provider, 'Test content', ['tokens' => 100]);

		$this->assertEquals(
			[
			'success' => true,
			'content' => 'Test content',
			'metadata' => [
				'provider' => 'test_provider',
				'model' => 'test-model',
				'tokens' => 100,
			],
			], $result
		);
	}

	public function testGetApiKeyForProvider_Valid(): void {
		putenv('OPENAI_API_KEY=test-key-123');

		$this->provider->configure(['llm_provider' => 'openai']);

		// Use reflection to access private method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getApiKeyForProvider');
		$method->setAccessible(true);

		$result = $method->invoke($this->provider, 'openai');
		$this->assertEquals('test-key-123', $result);
	}

	public function testGetApiKeyForProvider_UnsupportedProvider(): void {
		$this->provider->configure(['llm_provider' => 'openai']);

		// Use reflection to access private method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getApiKeyForProvider');
		$method->setAccessible(true);

		$this->expectException(\Manticoresearch\Buddy\Core\Error\QueryParseError::class);
		$this->expectExceptionMessage("Unsupported LLM provider: 'unsupported'");

		$method->invoke($this->provider, 'unsupported');
	}

	public function testGetApiKeyForProvider_MissingEnvVar(): void {
		$this->provider->configure(['llm_provider' => 'openai']);

		// Use reflection to access private method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getApiKeyForProvider');
		$method->setAccessible(true);

		$this->expectException(\Manticoresearch\Buddy\Core\Error\QueryParseError::class);
		$this->expectExceptionMessage("Environment variable 'OPENAI_API_KEY' not found or empty");

		$method->invoke($this->provider, 'openai');
	}
}
