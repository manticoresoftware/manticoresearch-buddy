<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders;

use Exception;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ModelManager;
use Manticoresearch\Buddy\Core\Error\QueryParseError;

/**
 * Base class for LLM providers
 */
abstract class BaseProvider {
	/**
	 * @var array<string, mixed>
	 */
	protected array $config = [];
	protected ?object $client = null;

	/**
	 * Configure provider with model settings
	 *
	 * @param array<string, mixed> $config
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
	 * @param array{ temperature?: string|float, max_tokens?: string|int,
	 *   k_results?: string|int, similarity_threshold?: string|int,
	 *   max_document_length?: string|int} $options
	 *
	 * @return array{error?:string,success:bool,content:string,
	 *   metadata?:array{tokens_used:integer, input_tokens:integer,
	 *   output_tokens:integer, response_time_ms:integer, finish_reason:string}}
	 */
	abstract public function generateResponse(string $prompt, array $options = []): array;

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
	 * Merge settings from configuration and overrides
	 *
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	protected function getSettings(array $overrides = []): array {
		$settings = [];

		// Extract settings from main config
		if (isset($this->config['settings']) && is_string($this->config['settings'])) {
			$settings = json_decode($this->config['settings'], true) ?? [];
		// Convert string numeric values to proper types
			$settings = $this->convertSettingsTypes(is_array($settings) ? $settings : []);
		} elseif (isset($this->config['settings']) && is_array($this->config['settings'])) {
			$settings = $this->config['settings'];
		}

		// Merge direct config values with type conversion
		$directSettings = [
			'temperature' => $this->convertToFloat($this->getConfig('temperature')),
			'max_tokens' => $this->convertToInt($this->getConfig('max_tokens')),
			'top_p' => $this->convertToFloat($this->getConfig('top_p')),
			'frequency_penalty' => $this->convertToFloat($this->getConfig('frequency_penalty')),
			'presence_penalty' => $this->convertToFloat($this->getConfig('presence_penalty')),
		];

		foreach ($directSettings as $key => $value) {
			if ($value === null) {
				continue;
			}

			$settings[$key] = $value;
		}

		// Apply overrides with type conversion
		$overrides = $this->convertSettingsTypes($overrides);
		return array_merge($settings, $overrides);
	}

	/**
	 * Convert settings array types from strings to proper types
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	protected function convertSettingsTypes(array $settings): array {
		$numericFields = ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty', 'k_results'];

		foreach ($numericFields as $field) {
			if (!isset($settings[$field]) || !is_string($settings[$field]) || !is_numeric($settings[$field])) {
				continue;
			}

			// Convert to int for integer fields, float for others
			if (in_array($field, ['max_tokens', 'k_results'])) {
				$settings[$field] = (int)$settings[$field];
			} else {
				$settings[$field] = (float)$settings[$field];
			}
		}

		return $settings;
	}

	/**
	 * Convert value to float if it's a numeric string
	 */
	protected function convertToFloat(mixed $value): mixed {
		if (is_string($value) && is_numeric($value)) {
			return (float)$value;
		}
		return $value;
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
	 * Convert value to integer if it's a numeric string
	 */
	protected function convertToInt(mixed $value): mixed {
		if (is_string($value) && is_numeric($value)) {
			return (int)$value;
		}
		return $value;
	}

	/**
	 * Build style prompt
	 *
	 * @return string
	 */
	protected function getStylePrompt(): string {
		/** @var string $prompt */
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
	 * @param Exception|null $exception
	 * @return array{success:bool, error:string, details: string|null, provider:string}
	 */
	protected function formatError(string $message, ?Exception $exception = null): array {
		return [
			'success' => false,
			'error' => $message,
			'details' => $exception?->getMessage(),
			'provider' => $this->getName(),
		];
	}

	/**
	 * Get the provider name
	 *
	 * @return string
	 */
	abstract public function getName(): string;

	/**
	 * Format success response
	 *
	 * @param string $content
	 * @param array<string, mixed> $metadata
	 * @return array<string, mixed>
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
		return $this->getApiKeyForProvider(is_string($provider) ? $provider : '');
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
				"Unsupported LLM provider: '$provider'. Supported providers: {$supportedProviders}"
			);
		}

		$envVarName = ModelManager::PROVIDER_ENV_VARS[$provider];

		$actualApiKey = getenv($envVarName);
		if (empty($actualApiKey)) {
			throw new QueryParseError(
				"Environment variable '$envVarName' not found or empty. Please set this variable with your API key."
			);
		}

		return $actualApiKey;
	}
}
