<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviderManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\BaseProvider;
use PHPUnit\Framework\TestCase;

class LLMProviderManagerTest extends TestCase {
	public function testGetConnection_CachesInstances(): void {
		$manager = new LLMProviderManager();

		$modelId = 'test-model-123';
		$modelConfig = [
			'llm_provider' => 'openai',
			'llm_model' => 'gpt-4',
			'temperature' => 0.7,
			'max_tokens' => 1000,
		];

		// Get connection twice
		$connection1 = $manager->getConnection($modelId, $modelConfig);
		$connection2 = $manager->getConnection($modelId, $modelConfig);

		// Should return the same instance
		$this->assertSame($connection1, $connection2);
		$this->assertInstanceOf(BaseProvider::class, $connection1);
	}

	public function testGetConnection_CreatesNewInstanceForDifferentModel(): void {
		$manager = new LLMProviderManager();

		$modelConfig1 = [
			'llm_provider' => 'openai',
			'llm_model' => 'gpt-4',
			'temperature' => 0.7,
		];

		$modelConfig2 = [
			'llm_provider' => 'openai',
			'llm_model' => 'gpt-3.5-turbo',
			'temperature' => 0.8,
		];

		$connection1 = $manager->getConnection('model1', $modelConfig1);
		$connection2 = $manager->getConnection('model2', $modelConfig2);

		// Should return different instances
		$this->assertNotSame($connection1, $connection2);
		$this->assertInstanceOf(BaseProvider::class, $connection1);
		$this->assertInstanceOf(BaseProvider::class, $connection2);
	}

	public function testGetProvider_CachesInstances(): void {
		$manager = new LLMProviderManager();

		// Get provider twice
		$provider1 = $manager->getProvider('openai');
		$provider2 = $manager->getProvider('openai');

		// Should return the same instance
		$this->assertSame($provider1, $provider2);
		$this->assertInstanceOf(BaseProvider::class, $provider1);
	}

	public function testGetProvider_OpenAI(): void {
		$manager = new LLMProviderManager();

		$provider = $manager->getProvider('openai');

		$this->assertInstanceOf(BaseProvider::class, $provider);
		$this->assertInstanceOf(\Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\OpenAIProvider::class, $provider);
	}

	public function testGetProvider_UnsupportedProvider(): void {
		$manager = new LLMProviderManager();

		$this->expectException(\Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError::class);

		$manager->getProvider('unsupported');
	}

	public function testCreateProvider_OpenAI(): void {
		$manager = new LLMProviderManager();

		// Use reflection to access private method
		$reflection = new ReflectionClass($manager);
		$method = $reflection->getMethod('createProvider');
		$method->setAccessible(true);

		$provider = $method->invoke($manager, 'openai');

		$this->assertInstanceOf(BaseProvider::class, $provider);
		$this->assertInstanceOf(\Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\OpenAIProvider::class, $provider);
	}

	public function testCreateProvider_UnsupportedProvider(): void {
		$manager = new LLMProviderManager();

		// Use reflection to access private method
		$reflection = new ReflectionClass($manager);
		$method = $reflection->getMethod('createProvider');
		$method->setAccessible(true);

		$this->expectException(\Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError::class);

		$method->invoke($manager, 'unsupported');
	}
}
