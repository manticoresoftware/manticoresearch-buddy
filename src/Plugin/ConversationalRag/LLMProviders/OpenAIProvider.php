<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

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
	private const BASE_URL = 'https://api.openai.com/v1';
	private const DEFAULT_MODEL = 'gpt-4o-mini';

	// OpenAI pricing per 1K tokens (as of 2024)
	private const PRICING = [
		'gpt-4o' => ['input' => 0.005, 'output' => 0.015],
		'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
		'gpt-4-turbo' => ['input' => 0.01, 'output' => 0.03],
		'gpt-3.5-turbo' => ['input' => 0.001, 'output' => 0.002],
	];

	/**
	 * Get provider name
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'openai';
	}

	/**
	 * Get supported models
	 *
	 * @return array
	 */
	public function getSupportedModels(): array {
		return array_keys(self::PRICING);
	}

	/**
	 * Generate response from OpenAI
	 *
	 * @param string $prompt
	 * @param array $options
	 * @return array
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
				return $this->formatError($response['error'] ?? 'OpenAI API request failed');
			}

			$result = $response['data'];
			$content = $result['choices'][0]['message']['content'] ?? '';
			$usage = $result['usage'] ?? [];

			return $this->formatSuccess(
				$content, [
				'tokens_used' => $usage['total_tokens'] ?? 0,
				'input_tokens' => $usage['prompt_tokens'] ?? 0,
				'output_tokens' => $usage['completion_tokens'] ?? 0,
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
	 * @param array $options
	 * @param callable|null $callback
	 * @return array
	 */

	/**
	 * Make HTTP request to OpenAI API
	 *
	 * @param string $baseUrl
	 * @param string $apiKey
	 * @param string $endpoint
	 * @param array $data
	 * @return array
	 */
	protected function makeRequest(string $baseUrl, string $apiKey, string $endpoint, array $data): array {
		$curl = $this->getClient();

		$url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

		curl_setopt_array(
			$curl, [
			CURLOPT_URL => $url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($data),
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Authorization: Bearer ' . $apiKey,
			],
			]
		);

		$response = curl_exec($curl);
		$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error = curl_error($curl);

		if ($error) {
			return ['success' => false, 'error' => 'HTTP request failed: ' . $error];
		}

		if ($httpCode !== 200) {
			$errorData = json_decode($response, true);
			$errorMessage = $errorData['error']['message'] ?? "HTTP {$httpCode} error";
			return ['success' => false, 'error' => $errorMessage];
		}

		$decoded = json_decode($response, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return ['success' => false, 'error' => 'Invalid JSON response'];
		}

		return ['success' => true, 'data' => $decoded];
	}

	/**
	 * Create HTTP client
	 *
	 * @return CurlHandle
	 */
	protected function createClient(): CurlHandle {
		$curl = curl_init();

		curl_setopt_array(
			$curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_USERAGENT => 'ManticoreSearch-Buddy-RAG/1.0',
			]
		);

		return $curl;
	}
}
