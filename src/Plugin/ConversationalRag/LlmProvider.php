<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Exception;
use Throwable;

/**
 * LLM provider implemented via the `llm` PHP extension.
 *
 * The extension exposes global classes: `Llm`, `Message`, `MessageCollection`, `Response`, `Usage`.
 */
class LlmProvider {
	private const string DEFAULT_MODEL = 'gpt-4o-mini';

	/**
	 * @var array<string, mixed>
	 */
	private array $config = [];

	private ?object $client = null;
	private ?string $clientModelId = null;

	/**
	 * @param array<string, mixed> $config
	 * @return void
	 */
	public function configure(array $config): void {
		$this->config = $config;
		$this->client = null;
		$this->clientModelId = null;
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
	public function generateResponse(string $prompt, array $options = []): array {
		try {
			if (!extension_loaded('llm') || !class_exists('\\Llm')) {
				return $this->formatError('LLM extension is not available (expected PHP extension: llm)');
			}

			$provider = $this->getConfig('llm_provider');
			if (!is_string($provider) || $provider === '') {
				return $this->formatError('LLM provider not configured');
			}

			$model = $this->getConfig('llm_model', self::DEFAULT_MODEL);
			$modelId = $this->buildModelId($provider, is_string($model) ? $model : self::DEFAULT_MODEL);

			$settings = $this->getSettings($options);
			$stylePrompt = $this->getStylePrompt();

			/** @var \Llm $llm */
			$llm = $this->getClientForModel($modelId);

			if (isset($settings['temperature']) && is_numeric($settings['temperature'])) {
				$llm->setTemperature((float)$settings['temperature']);
			}
			if (isset($settings['max_tokens']) && is_numeric($settings['max_tokens'])) {
				$llm->setMaxTokens((int)$settings['max_tokens']);
			}
			if (isset($settings['top_p']) && is_numeric($settings['top_p'])) {
				$llm->setTopP((float)$settings['top_p']);
			}
			if (isset($settings['frequency_penalty']) && is_numeric($settings['frequency_penalty'])) {
				$llm->setFrequencyPenalty((float)$settings['frequency_penalty']);
			}
			if (isset($settings['presence_penalty']) && is_numeric($settings['presence_penalty'])) {
				$llm->setPresencePenalty((float)$settings['presence_penalty']);
			}

			$messages = [];
			if ($stylePrompt !== '') {
				$messages[] = \Message::system($stylePrompt);
			}
			$messages[] = \Message::user($prompt);

			$startTime = microtime(true);
			/** @var \Response $response */
			$response = $llm->complete($messages);
			$responseTime = (int)((microtime(true) - $startTime) * 1000);

			/** @var \Usage $usage */
			$usage = $response->getUsage();

			return $this->formatSuccess(
				$response->getContent(),
				[
					'tokens_used' => $usage->getTotalTokens(),
					'input_tokens' => $usage->getPromptTokens(),
					'output_tokens' => $usage->getOutputTokens(),
					'response_time_ms' => $responseTime,
					'finish_reason' => $response->getFinishReason(),
				]
			);
		} catch (Throwable $e) {
			return $this->formatError('LLM request failed', $e);
		}
	}

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
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private function getSettings(array $overrides = []): array {
		$settings = [];

		if (isset($this->config['settings']) && is_string($this->config['settings'])) {
			$settings = json_decode($this->config['settings'], true) ?? [];
			$settings = $this->convertSettingsTypes(is_array($settings) ? $settings : []);
		} elseif (isset($this->config['settings']) && is_array($this->config['settings'])) {
			$settings = $this->config['settings'];
		}

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

		$overrides = $this->convertSettingsTypes($overrides);
		return array_merge($settings, $overrides);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function convertSettingsTypes(array $settings): array {
		$numericFields = ['temperature', 'max_tokens', 'top_p', 'frequency_penalty', 'presence_penalty', 'k_results'];

		foreach ($numericFields as $field) {
			if (!isset($settings[$field]) || !is_string($settings[$field]) || !is_numeric($settings[$field])) {
				continue;
			}

			if (in_array($field, ['max_tokens', 'k_results'])) {
				$settings[$field] = (int)$settings[$field];
			} else {
				$settings[$field] = (float)$settings[$field];
			}
		}

		return $settings;
	}

	private function convertToFloat(mixed $value): mixed {
		if (is_string($value) && is_numeric($value)) {
			return (float)$value;
		}
		return $value;
	}

	private function convertToInt(mixed $value): mixed {
		if (is_string($value) && is_numeric($value)) {
			return (int)$value;
		}
		return $value;
	}

	private function getConfig(string $key, mixed $default = null): mixed {
		return $this->config[$key] ?? $default;
	}

	private function getStylePrompt(): string {
		/** @var string $prompt */
		$prompt = $this->getConfig('style_prompt', '');

		if ($prompt === '') {
			$prompt = 'You are a helpful AI assistant. Answer questions based on the provided context.';
		}

		return $prompt;
	}

	/**
	 * @return array{success:bool, error:string, details: string|null, provider:string}
	 */
	private function formatError(string $message, ?Exception $exception = null): array {
		return [
			'success' => false,
			'error' => $message,
			'details' => $exception?->getMessage(),
			'provider' => $this->getName(),
		];
	}

	private function getName(): string {
		$provider = $this->getConfig('llm_provider');
		if (is_string($provider) && $provider !== '') {
			return $provider;
		}

		return 'llm';
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return array{success:bool, content:string, metadata:array<string, mixed>}
	 */
	private function formatSuccess(string $content, array $metadata = []): array {
		return [
			'success' => true,
			'content' => $content,
			'metadata' => array_merge(
				[
					'provider' => $this->getName(),
					'model' => $this->getConfig('llm_model'),
				],
				$metadata
			),
		];
	}

	private function buildModelId(string $provider, string $model): string {
		$model = trim($model);
		if ($model === '') {
			$model = self::DEFAULT_MODEL;
		}

		if (str_contains($model, ':')) {
			return $model;
		}

		return $provider . ':' . $model;
	}

	/**
	 * @param string $modelId
	 *
	 * @return \Llm
	 */
	private function getClientForModel(string $modelId): object {
		if ($this->client === null || !$this->client instanceof \Llm || $this->clientModelId !== $modelId) {
			$this->client = new \Llm($modelId);
			$this->clientModelId = $modelId;
		}

		/** @var \Llm $llm */
		$llm = $this->client;
		return $llm;
	}
}
