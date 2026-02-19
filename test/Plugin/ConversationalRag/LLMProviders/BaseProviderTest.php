<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LlmProvider;
use PHPUnit\Framework\TestCase;

class BaseProviderTest extends TestCase {
	private LlmProvider $provider;

	protected function setUp(): void {
		$this->provider = new LlmProvider();
	}

	protected function tearDown(): void {
		// Nothing to clean up
	}

	public function testConfigureResetsClient(): void {
		$config = ['llm_provider' => 'openai', 'llm_model' => 'gpt-4'];

		// Configure once
		$this->provider->configure($config);

		// Initialize client via reflection
		$reflection = new ReflectionClass($this->provider);
		$clientProperty = $reflection->getProperty('client');
		$clientProperty->setAccessible(true);
		$clientProperty->setValue($this->provider, (object)['test' => 'client']);

		// Configure again
		$this->provider->configure($config);

		// Client should be reset
		$this->assertNull($clientProperty->getValue($this->provider));
	}

	public function testGetSettingsMergesOverrides(): void {
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
		$result = (array)$method->invoke($this->provider, $overrides);

		$this->assertEquals(0.8, $result['temperature']); // Override takes precedence
		$this->assertEquals(500, $result['max_tokens']); // From settings
		$this->assertEquals(0.9, $result['top_p']); // From config
		$this->assertEquals(0.1, $result['frequency_penalty']); // From overrides
	}

	public function testGetSettingsFromJsonString(): void {
		$config = [
			'settings' => '{"temperature":0.6,"max_tokens":600}',
			'temperature' => 0.7, // This should override the JSON
		];

		$this->provider->configure($config);

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getSettings');
		$method->setAccessible(true);

		$result = (array)$method->invoke($this->provider, []);

		$this->assertEquals(0.7, $result['temperature']); // Direct config overrides JSON
		$this->assertEquals(600, $result['max_tokens']); // From JSON
	}

	public function testConvertSettingsTypesNumericStrings(): void {
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

		$result = (array)$method->invoke($this->provider, $settings);

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

	public function testConvertToFloatValidNumeric(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('convertToFloat');
		$method->setAccessible(true);

		$this->assertEquals(3.14, $method->invoke($this->provider, '3.14'));
		$this->assertEquals(42.0, $method->invoke($this->provider, '42'));
		$this->assertEquals('not_numeric', $method->invoke($this->provider, 'not_numeric'));
		$this->assertEquals(3.14, $method->invoke($this->provider, 3.14)); // Already float
	}

	public function testConvertToIntValidNumeric(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('convertToInt');
		$method->setAccessible(true);

		$this->assertEquals(42, $method->invoke($this->provider, '42'));
		$this->assertEquals(3, $method->invoke($this->provider, '3.14')); // Truncated
		$this->assertEquals('not_numeric', $method->invoke($this->provider, 'not_numeric'));
		$this->assertEquals(42, $method->invoke($this->provider, 42)); // Already int
	}

	public function testGetConfigWithDefault(): void {
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

	public function testGetStylePromptDefault(): void {
		$this->provider->configure([]);

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getStylePrompt');
		$method->setAccessible(true);

		$result = $method->invoke($this->provider);
		$this->assertStringContainsString('helpful AI assistant', is_string($result) ? $result : '');
	}

	public function testGetStylePromptCustom(): void {
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
			'provider' => 'llm',
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
				'provider' => 'llm',
				'model' => 'test-model',
				'tokens' => 100,
			],
			], $result
		);
	}
}
