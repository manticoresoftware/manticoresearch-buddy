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

	/**
	 * @param array<string, mixed> $config
	 * @return void
	 */
	public function configure(array $config): void {
		$this->config = $config;
	}

	/**
	 * Generate a response from the LLM
	 *
	 * @param string $prompt
	 * @param array<string, mixed> $options
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
			/** @var string $modelId */
			$modelId = $this->config['model'];
			$settings = $this->getSettings($options);
			$clientOptions = $this->extractClientOptions($settings);
			$llm = new \Llm($modelId, $clientOptions);
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
	 * Generate a tool call from the LLM.
	 *
	 * @param string $prompt
	 * @param array{name:string, description:string, parameters:array<string, mixed>} $toolDefinition
	 * @param array<string, mixed> $options
	 *
	 * @return (
	 *   array{
	 *     success:true,
	 *     content:string,
	 *     tool_calls:array<int, mixed>,
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
	public function generateToolCall(string $prompt, array $toolDefinition, array $options = []): array {
		try {
			/** @var string $modelId */
			$modelId = $this->config['model'];
			$settings = $this->getSettings($options);
			$clientOptions = $this->extractClientOptions($settings);
			$llm = new \Llm($modelId, $clientOptions);
			$toolBuilder = $llm->withTools([\Tool::fromArray($toolDefinition)]);
			$toolBuilder->setAutoExecute(false);
			$this->applySettingsToToolBuilder($toolBuilder, $settings);
			$messages = $this->buildMessages($prompt);

			$startTime = microtime(true);
			/** @var \ToolResponse $response */
			$response = $toolBuilder->complete($messages);
			$responseTime = (int)((microtime(true) - $startTime) * 1000);

			/** @var \Usage $usage */
			$usage = $response->getUsage();

			return [
				'success' => true,
				'content' => $response->getContent(),
				'tool_calls' => $response->getToolCalls(),
				'metadata' => [
					'tokens_used' => $usage->getTotalTokens(),
					'input_tokens' => $usage->getPromptTokens(),
					'output_tokens' => $usage->getOutputTokens(),
					'response_time_ms' => $responseTime,
					'finish_reason' => 'tool_calls',
				],
			];
		} catch (Throwable $e) {
			if ($e instanceof UnexpectedValueException) {
				throw $e;
			}
			return $this->formatError('LLM tool call failed', $e);
		}
	}

	/**
	 * @return array<int, \Message>
	 */
	private function buildMessages(string $prompt): array {
		return [\Message::user($prompt)];
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
	 * @param \ToolBuilder $toolBuilder
	 * @param array<string, mixed> $settings
	 */
	private function applySettingsToToolBuilder(\ToolBuilder $toolBuilder, array $settings): void {
		if (isset($settings['temperature']) && is_numeric($settings['temperature'])) {
			$toolBuilder->setTemperature((float)$settings['temperature']);
		}
		if (!isset($settings['max_tokens']) || !is_numeric($settings['max_tokens'])) {
			return;
		}

		$toolBuilder->setMaxTokens((int)$settings['max_tokens']);
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
	 * @param array{error:string, details?: mixed} $response
	 */
	public static function formatFailureMessage(string $prefix, array $response): string {
		$message = $prefix . ': ' . $response['error'];
		if (isset($response['details']) && is_string($response['details'])) {
			$details = trim($response['details']);
			if ($details !== '') {
				$message .= ': ' . $details;
			}
		}

		return $message;
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

	/**
	 * @return array{success:false, error:string, content:string, provider:string, details?: string|null}
	 */
	private function formatError(string $message, ?Throwable $exception = null): array {
		return [
			'success' => false,
			'error' => $message,
			'content' => '',
			'provider' => 'llm',
			'details' => $exception?->getMessage(),
		];
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

}
