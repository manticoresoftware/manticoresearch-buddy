<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LLMProviders;

use CurlHandle;
use Exception;

/**
 * OpenAI LLM Provider implementation
 */
class OpenAIProvider extends BaseProvider {
	private const string BASE_URL = 'https://api.openai.com/v1';
	private const DEFAULT_MODEL = 'gpt-4o-mini';

	/**
	 * Get provider name
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'openai';
	}

	/**
	 * Generate response from OpenAI
	 *
	 * @param string $prompt
	 * @param array{ temperature?: string|float, max_tokens?: string|int,
	 *   k_results?: string|int, similarity_threshold?: string|int,
	 *   max_document_length?: string|int} $options
	 *
	 * @return array<string, mixed>
	 */
	public function generateResponse(string $prompt, array $options = []): array {
		try {
			// Provider handles its own base URL - no user configuration needed
			$baseUrl = self::BASE_URL;

			$apiKey = $this->getApiKey();
			$model = $this->getConfig('llm_model', self::DEFAULT_MODEL);
			$settings = $this->getSettings($options);
			$stylePrompt = $this->getStylePrompt();

			// Build messages array
			$messages = [];

			if (!empty($stylePrompt)) {
				$messages[] = ['role' => 'system', 'content' => $stylePrompt];
			}

			$messages[] = ['role' => 'user', 'content' => $prompt];

			// Prepare request data
			$data = [
				'model' => $model,
				'messages' => $messages,
				'temperature' => $settings['temperature'] ?? 0.7,
				'max_tokens' => $settings['max_tokens'] ?? 4000,
			];

			// Optional parameters
			if (isset($settings['top_p'])) {
				$data['top_p'] = $settings['top_p'];
			}
			if (isset($settings['frequency_penalty'])) {
				$data['frequency_penalty'] = $settings['frequency_penalty'];
			}
			if (isset($settings['presence_penalty'])) {
				$data['presence_penalty'] = $settings['presence_penalty'];
			}

			$startTime = microtime(true);
			$response = $this->makeRequest($baseUrl, $apiKey, 'chat/completions', $data);
			$responseTime = (int)((microtime(true) - $startTime) * 1000);

			if (!$response['success']) {
				$error = $response['error'] ?? 'OpenAI API request failed';
				return $this->formatError(is_string($error) ? $error : 'OpenAI API request failed');
			}

			$result = $response['data'];
			if (!is_array($result)) {
				return $this->formatError('Invalid API response format');
			}

			$content = $result['choices'][0]['message']['content'] ?? '';
			$usage = $result['usage'] ?? [];

			return $this->formatSuccess(
				$content, [
				'tokens_used' => (int)($usage['total_tokens'] ?? 0),
				'input_tokens' => (int)($usage['prompt_tokens'] ?? 0),
				'output_tokens' => (int)($usage['completion_tokens'] ?? 0),
				'response_time_ms' => $responseTime,
				'finish_reason' => $result['choices'][0]['finish_reason'] ?? 'unknown',
				]
			);
		} catch (Exception $e) {
			return $this->formatError('OpenAI request failed', $e);
		}
	}

	/**
	 * Generate streaming response from OpenAI
	 *
	 * @param string $prompt
	 * @param array{temperature?: string|float, max_tokens?: string|int,
	 *   k_results?: string|int, similarity_threshold?: string|int,
	 *   max_document_length?: string|int} $options
	 * @param callable|null $callback
	 * @return array{success: true, content: string, metadata: array{provider: string,
	 *   model: string}}|array{success: false, error: string}
	 */

	/**
	 * Make HTTP request to OpenAI API
	 *
	 * @param string $baseUrl
	 * @param string $apiKey
	 * @param string $endpoint
	 * @param array{model:mixed, messages:array<int, array{role:string, content:string}>,
	 *   temperature: mixed, max_tokens:mixed, top_p?:mixed, frequency_penalty?:mixed,
	 *   presence_penalty?:mixed} $data
	 *
	 * @return array<string, mixed>
	 * @throws \JsonException
	 */
	protected function makeRequest(
		string $baseUrl,
		string $apiKey,
		string $endpoint,
		array $data
	): array {
		/** @var CurlHandle $curl */
		$curl = $this->getClient();

		$url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

		/** @var array<int, mixed> $curlOptions */
		$curlOptions = [
			CURLOPT_URL => $url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($data),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $apiKey,
			],
		];
		curl_setopt_array($curl, $curlOptions);

		$response = (string)curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error = curl_error($curl);

		if ($error) {
			return ['success' => false, 'error' => 'HTTP request failed: ' . $error];
		}

		$response = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
		if (JSON_ERROR_NONE !== json_last_error() || !is_array($response)) {
			return ['success' => false, 'error' => 'Invalid JSON response'];
		}
		if ($httpCode !== 200) {
			return ['success' => false, 'error' => $response['error']['message'] ?? "HTTP {$httpCode} error"];
		}

		return ['success' => true, 'data' => $response];
	}

	/**
	 * Create HTTP client
	 *
	 * @return CurlHandle
	 */
	protected function createClient(): CurlHandle {
		$curl = curl_init();

		/** @var array<int, mixed> $curlOptions */
		$curlOptions = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_USERAGENT => 'ManticoreSearch-Buddy-RAG/1.0',
		];
		curl_setopt_array($curl, $curlOptions);

		return $curl;
	}
}
