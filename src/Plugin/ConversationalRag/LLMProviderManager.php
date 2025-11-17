<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\BaseProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders\OpenAIProvider;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;

/**
 * Manages LLM providers and connections
 */
class LLMProviderManager {
	/**
	 * @var array<string, BaseProvider>
	 */
	private array $providers = [];

	/**
	 * @var array<string, BaseProvider>
	 */
	private array $connections = [];

	/**
	 * Get configured connection for a model
	 *
	 * @param string $modelId
	 * @param array<string, mixed> $modelConfig
	 * @return BaseProvider
	 * @throws ManticoreSearchClientError
	 */
	public function getConnection(string $modelId, array $modelConfig): BaseProvider {
		if (!isset($this->connections[$modelId])) {
			/** @var string $providerName */
			$providerName = $modelConfig['llm_provider'];
			$provider = $this->createProvider($providerName);
			$provider->configure($modelConfig);
			$this->connections[$modelId] = $provider;
		}

		return $this->connections[$modelId];
	}

	/**
	 * Create provider instance by name
	 *
	 * @param string $providerName
	 * @return BaseProvider
	 * @throws ManticoreSearchClientError
	 */
	private function createProvider(string $providerName): BaseProvider {
		return match ($providerName) {
			'openai' => new OpenAIProvider(),
			default => throw ManticoreSearchClientError::create(
				"Unsupported LLM provider: {$providerName}. Only 'openai' is supported."
			)
		};
	}

	/**
	 * Get provider instance by name
	 *
	 * @param string $providerName
	 * @return BaseProvider
	 * @throws ManticoreSearchClientError
	 */
	public function getProvider(string $providerName): BaseProvider {
		if (!isset($this->providers[$providerName])) {
			$this->providers[$providerName] = $this->createProvider($providerName);
		}

		return $this->providers[$providerName];
	}






}
