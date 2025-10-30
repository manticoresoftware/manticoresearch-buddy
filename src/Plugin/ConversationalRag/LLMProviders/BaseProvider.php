<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders;

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ModelManager;
use Manticoresearch\Buddy\Core\Error\QueryParseError;

/**
 * Base class for LLM providers
 */
abstract class BaseProvider {
	protected array $config = [];
	protected ?object $client = null;

	/**
	 * Configure the provider with model settings
	 *
	 * @param array $config
	 * @return void
	 */
	public function configure(array $config): void {
		$this->config = $config;
		$this->client = null; // Reset client to force recreation
	}

	/**
	 * Generate a response from the LLM
	 *
	 * @param string $prompt
	 * @param array $options
	 * @return array
	 */
	abstract public function generateResponse(string $prompt, array $options = []): array;



	/**
	 * Get the provider name
	 *
	 * @return string
	 */
	abstract public function getName(): string;

	/**
	 * Get supported models for this provider
	 *
	 * @return array
	 */
	abstract public function getSupportedModels(): array;

	/**
	 * Estimate token count for a text
	 *
	 * @param string $text
	 * @return int
	 */
	public function estimateTokens(string $text): int {
		// Simple estimation: ~4 characters per token
		return (int)ceil(strlen($text) / 4);
	}



	/**
	 * Get configuration value
	 *
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 */
	protected function getConfig(string $key, mixed $default = null): mixed {
		return $this->config[$key] ?? $default;
	}

	/**
	 * Get or create HTTP client
	 *
	 * @return object
	 */
	protected function getClient(): object {
		if ($this->client === null) {
			$this->client = $this->createClient();
		}

		return $this->client;
	}

	/**
	 * Create HTTP client for the provider
	 *
	 * @return object
	 */
	abstract protected function createClient(): object;

	/**
	 * Validate required configuration fields
	 *
	 * @param array $config
	 * @param array $required
	 * @return void
	 * @throws QueryParseError
	 */
	protected function validateConfig(array $config, array $required): void {
		foreach ($required as $field) {
			if (!isset($config[$field]) || empty($config[$field])) {
				throw new QueryParseError("Required configuration field '{$field}' is missing");
			}
		}
	}

	/**
	 * Merge settings from configuration
	 *
	 * @param array $overrides
	 * @return array
	 */
	protected function getSettings(array $overrides = []): array {
		$settings = [];

		// Extract settings from main config
		if (isset($this->config['settings']) && is_string($this->config['settings'])) {
			$settings = json_decode($this->config['settings'], true) ?? [];
		} elseif (isset($this->config['settings']) && is_array($this->config['settings'])) {
			$settings = $this->config['settings'];
		}

		// Merge direct config values
		$directSettings = [
			'temperature' => $this->getConfig('temperature'),
			'max_tokens' => $this->getConfig('max_tokens'),
			'top_p' => $this->getConfig('top_p'),
			'frequency_penalty' => $this->getConfig('frequency_penalty'),
			'presence_penalty' => $this->getConfig('presence_penalty'),
		];

		foreach ($directSettings as $key => $value) {
			if ($value === null) {
				continue;
			}

			$settings[$key] = $value;
		}

		// Apply overrides
		return array_merge($settings, $overrides);
	}

	/**
	 * Build style prompt
	 *
	 * @return string
	 */
	protected function getStylePrompt(): string {
		$prompt = $this->getConfig('style_prompt', '');

		if (empty($prompt)) {
			$prompt = 'You are a helpful AI assistant. Answer questions based on the provided context.';
		}

		return $prompt;
	}

	/**
	 * Format error response
	 *
	 * @param string $message
	 * @param \Exception|null $exception
	 * @return array
	 */
	protected function formatError(string $message, ?\Exception $exception = null): array {
		return [
			'success' => false,
			'error' => $message,
			'details' => $exception ? $exception->getMessage() : null,
			'provider' => $this->getName(),
		];
	}

	/**
	 * Format success response
	 *
	 * @param string $content
	 * @param array $metadata
	 * @return array
	 */
	protected function formatSuccess(string $content, array $metadata = []): array {
		return [
			'success' => true,
			'content' => $content,
			'metadata' => array_merge(
				[
				'provider' => $this->getName(),
				'model' => $this->getConfig('llm_model'),
				], $metadata
			),
		];
	}

	/**
	 * Get API key for the current provider
	 *
	 * @return string
	 * @throws QueryParseError
	 */
	protected function getApiKey(): string {
		$provider = $this->getConfig('llm_provider');
		if ($provider === null || $provider === '') {
			throw new QueryParseError('LLM provider not configured');
		}
		return $this->getApiKeyForProvider($provider);
	}

	/**
	 * Get API key for a given provider (consolidated method)
	 *
	 * @param string $provider Provider name (e.g., 'openai')
	 * @return string Actual API key value (e.g., 'sk-proj-abc123...')
	 * @throws QueryParseError If provider unsupported or env var missing/empty
	 */
	private function getApiKeyForProvider(string $provider): string {
		if (empty($provider)) {
			throw new QueryParseError('LLM provider not configured');
		}


		if (!isset(ModelManager::PROVIDER_ENV_VARS[$provider])) {
			$supportedProviders = implode(', ', array_keys(ModelManager::PROVIDER_ENV_VARS));
			throw new QueryParseError(
				"Unsupported LLM provider: '{$provider}'. Supported providers: {$supportedProviders}"
			);
		}

		$envVarName = ModelManager::PROVIDER_ENV_VARS[$provider];

		$actualApiKey = getenv($envVarName);
		if (empty($actualApiKey)) {
			throw new QueryParseError(
				"Environment variable '{$envVarName}' not found or empty. Please set this variable with your API key."
			);
		}

		return $actualApiKey;
	}
}
