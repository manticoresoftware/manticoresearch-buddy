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

	public function testGetSettingsMergesOverrides(): void {
		$config = [
			'settings' => ['api_key' => 'sk-test', 'timeout' => 30, 'temperature' => 0.5, 'max_tokens' => 500],
		];

		$this->provider->configure($config);

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getSettings');
		$method->setAccessible(true);

		$overrides = ['temperature' => 0.8, 'frequency_penalty' => 0.1];
		$result = (array)$method->invoke($this->provider, $overrides);

		$this->assertEquals('sk-test', $result['api_key']);
		$this->assertEquals(30, $result['timeout']);
		$this->assertEquals(0.8, $result['temperature']);
		$this->assertEquals(500, $result['max_tokens']);
		$this->assertEquals(0.1, $result['frequency_penalty']);
	}

	public function testGetSettingsFromJsonString(): void {
		$config = [
			'settings' => '{"temperature":0.6,"max_tokens":600}',
		];

		$this->provider->configure($config);

		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('getSettings');
		$method->setAccessible(true);

		$result = (array)$method->invoke($this->provider, []);

		$this->assertEquals(0.6, $result['temperature']);
		$this->assertEquals(600, $result['max_tokens']);
	}

	public function testConvertSettingsTypesNumericStrings(): void {
		$settings = [
			'temperature' => '0.7',
			'max_tokens' => '1000',
			'retrieval_limit' => '5',
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
		$this->assertIsInt($result['retrieval_limit']);
		$this->assertEquals(5, $result['retrieval_limit']);
		$this->assertIsFloat($result['top_p']);
		$this->assertEquals(0.9, $result['top_p']);
		$this->assertEquals('text', $result['non_numeric']); // Unchanged
	}

	public function testExtractClientOptionsKeepsOnlySupportedExtensionSettings(): void {
		$settings = [
			'api_key' => 'sk-test',
			'base_url' => 'http://host.docker.internal:8787/v1',
			'timeout' => 60,
			'temperature' => 0.7,
			'max_tokens' => 1000,
			'retrieval_limit' => 5,
			'custom_header' => 'x-test',
		];

		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('extractClientOptions');
		$method->setAccessible(true);

		$result = (array)$method->invoke($this->provider, $settings);

		$this->assertEquals(
			[
				'api_key' => 'sk-test',
				'base_url' => 'http://host.docker.internal:8787/v1',
				'timeout' => 60,
			],
			$result
		);
	}

	public function testGetSettingsPreservesTransportOptionsFromModelSettings(): void {
		$config = [
			'settings' => [
				'api_key' => 'sk-test',
				'base_url' => 'http://host.docker.internal:8787/v1',
				'timeout' => 60,
			],
		];

		$this->provider->configure($config);

		$reflection = new ReflectionClass($this->provider);
		$getSettings = $reflection->getMethod('getSettings');
		$getSettings->setAccessible(true);
		$extractClientOptions = $reflection->getMethod('extractClientOptions');
		$extractClientOptions->setAccessible(true);

		$settings = (array)$getSettings->invoke($this->provider, []);
		$result = (array)$extractClientOptions->invoke($this->provider, $settings);

		$this->assertEquals(
			[
				'api_key' => 'sk-test',
				'base_url' => 'http://host.docker.internal:8787/v1',
				'timeout' => 60,
			],
			$result
		);
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

	public function testEstimateTokens(): void {
		$this->assertEquals(1, $this->provider->estimateTokens('test')); // 4 chars / 4 = 1
		$this->assertEquals(1, $this->provider->estimateTokens('abcd')); // 4 chars / 4 = 1
		$this->assertEquals(2, $this->provider->estimateTokens('abcde')); // 5 chars / 4 = 1.25, ceil to 2
		$this->assertEquals(3, $this->provider->estimateTokens('abcdefghijk')); // 12 chars / 4 = 3
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
				'content' => '',
				'provider' => 'llm',
				'details' => 'Test exception',
				], $result
			);
	}

	public function testFormatFailureMessageIncludesDetails(): void {
		$result = LlmProvider::formatFailureMessage(
			'Query generation failed',
			[
				'error' => 'LLM request failed',
				'details' => 'OpenRouter API error 429 Too Many Requests',
			]
		);

		$this->assertEquals(
			'Query generation failed: LLM request failed: OpenRouter API error 429 Too Many Requests',
			$result
		);
	}

	public function testFormatSuccess(): void {
		// Use reflection to access protected method
		$reflection = new ReflectionClass($this->provider);
		$method = $reflection->getMethod('formatSuccess');
		$method->setAccessible(true);

		$this->provider->configure(['model' => 'test-model']);
		$result = $method->invoke($this->provider, 'Test content', ['tokens' => 100]);

			$this->assertEquals(
				[
				'success' => true,
				'content' => 'Test content',
				'metadata' => [
					'tokens' => 100,
				],
				], $result
			);
	}
}
