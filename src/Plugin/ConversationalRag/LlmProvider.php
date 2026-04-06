<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Throwable;
use UnexpectedValueException;

/**
 * LLM provider implemented via the `llm` PHP extension.
 *
 * The extension exposes global classes: `Llm`, `Message`, `MessageCollection`, `Response`, `Usage`.
 */
class LlmProvider {
	private const string DEFAULT_MODEL = 'gpt-4o-mini';
	/**
	 * Transport options accepted by the llm extension constructor.
	 *
	 * @var array<int, string>
	 */
	private const array CLIENT_OPTIONS = [
		'api_key',
		'base_url',
		'timeout',
	];

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
	 * @param array<string, string|int|float> $options
	 *
	 * @return (
	 *   array{
	 *     success:true,
	 *     content:string,
	 *     metadata:array{
	 *       tokens_used:int,
	 *       input_tokens:int,
	 *       output_tokens:int,
	 *       response_time_ms:int,
	 *       finish_reason:string
	 *     }
	 *   }
	 * )|(
	 *   array{
	 *     success:false,
	 *     error:string,
	 *     content:string,
	 *     provider:string,
	 *     details?:string|null
	 *   }
	 * )
	 */
	public function generateResponse(string $prompt, array $options = []): array {
		try {
			$modelId = $this->getModelId();

			$settings = $this->getSettings($options);
			$clientOptions = $this->extractClientOptions($settings);
			$llm = $this->getClientForModel($modelId, $clientOptions);
			$this->applySettingsToClient($llm, $settings);
			$messages = $this->buildMessages($prompt);

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
			if ($e instanceof UnexpectedValueException) {
				throw $e;
			}
			return $this->formatError('LLM request failed', $e);
		}
	}

	/**
	 * @return array<int, \Message>
	 */
	private function buildMessages(string $prompt): array {
		$stylePrompt = $this->getStylePrompt();

		$messages = [];
		if ($stylePrompt !== '') {
			$messages[] = \Message::system($stylePrompt);
		}
		$messages[] = \Message::user($prompt);

		return $messages;
	}

	/**
	 * @param \Llm $llm
	 * @param array<string, mixed> $settings
	 */
	private function applySettingsToClient(\Llm $llm, array $settings): void {
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
		if (!isset($settings['presence_penalty']) || !is_numeric($settings['presence_penalty'])) {
			return;
		}

		$llm->setPresencePenalty((float)$settings['presence_penalty']);
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
			$decoded = simdjson_decode($this->config['settings'], true);
			if (!is_array($decoded)) {
				throw new UnexpectedValueException('Invalid LLM settings JSON');
			}
			$settings = $this->convertSettingsTypes($decoded);
		} elseif (isset($this->config['settings']) && is_array($this->config['settings'])) {
			$settings = $this->config['settings'];
		}

		$overrides = $this->convertSettingsTypes($overrides);
		return array_merge($settings, $overrides);
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function convertSettingsTypes(array $settings): array {
		$intFields = ['timeout', 'max_tokens', 'retrieval_limit'];
		$floatFields = ['temperature', 'top_p', 'frequency_penalty', 'presence_penalty'];

		foreach ($intFields as $field) {
			if (!isset($settings[$field])) {
				continue;
			}

			$settings[$field] = $this->convertToInt($settings[$field]);
		}

		foreach ($floatFields as $field) {
			if (!isset($settings[$field])) {
				continue;
			}

			$settings[$field] = $this->convertToFloat($settings[$field]);
		}

		return $settings;
	}

	/**
	 * Pass through any extension-level options from model settings while excluding values handled by Buddy itself.
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	private function extractClientOptions(array $settings): array {
		$clientOptions = [];
		$allowedKeys = array_flip(self::CLIENT_OPTIONS);

		foreach ($settings as $key => $value) {
			if (!is_string($key) || !isset($allowedKeys[$key])) {
				continue;
			}

			$clientOptions[$key] = $value;
		}

		return $clientOptions;
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
	 * @return array{success:false, error:string, content:string, details?: string|null, provider:string}
	 */
	private function formatError(string $message, ?Throwable $exception = null): array {
		return [
			'success' => false,
			'error' => $message,
			'content' => '',
			'details' => $exception?->getMessage(),
			'provider' => $this->getName(),
		];
	}

	private function getName(): string {
		$model = $this->getConfig('model');
		if (is_string($model) && str_contains($model, ':')) {
			[$provider, $modelName] = array_pad(explode(':', trim($model), 2), 2, '');
			if ($provider !== '' && $modelName !== '') {
				return $provider;
			}
		}

		return 'llm';
	}

	/**
	 * @param array{
	 *   tokens_used:int,
	 *   input_tokens:int,
	 *   output_tokens:int,
	 *   response_time_ms:int,
	 *   finish_reason:string
	 * } $metadata
	 *
	 * @return array{success:true, content:string, metadata:array{
	 *   tokens_used:int,
	 *   input_tokens:int,
	 *   output_tokens:int,
	 *   response_time_ms:int,
	 *   finish_reason:string
	 * }}
	 */
	private function formatSuccess(string $content, array $metadata): array {
		return [
			'success' => true,
			'content' => $content,
			'metadata' => $metadata,
		];
	}

	private function getModelId(): string {
		$model = $this->getConfig('model', self::DEFAULT_MODEL);
		$modelId = is_string($model) ? trim($model) : self::DEFAULT_MODEL;
		[$provider, $modelName] = array_pad(explode(':', $modelId, 2), 2, '');
		if ($provider !== '' && $modelName !== '') {
			return $modelId;
		}

		throw new UnexpectedValueException("model must use 'provider:model' format");
	}

	/**
	 * @param string $modelId
	 * @param array<string, mixed> $options
	 *
	 * @return \Llm
	 */
	private function getClientForModel(string $modelId, array $options = []): \Llm {
		if ($this->client === null || !$this->client instanceof \Llm || $this->clientModelId !== $modelId) {
			$this->client = new \Llm($modelId, $options);
			$this->clientModelId = $modelId;
		}

		/** @var \Llm $llm */
		$llm = $this->client;
		return $llm;
	}
}
