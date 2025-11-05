<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;

/**
 * Manages RAG model persistence and database operations
 */
class ModelManager {
	use SqlEscapeTrait;
	public const MODELS_TABLE = 'system.rag_models';

	// Mapping of LLM providers to their environment variable names
	public const PROVIDER_ENV_VARS = [
		'openai' => 'OPENAI_API_KEY',
		'anthropic' => 'ANTHROPIC_API_KEY',
		'grok' => 'GROK_API_KEY',
		'mistral' => 'MISTRAL_API_KEY',
		'ollama' => 'OLLAMA_API_KEY',
	];

	private bool $tablesInitialized = false;

	/**
	 * Initialize database tables
	 *
	 * @param HTTPClient $client
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	public function initializeTables(HTTPClient $client): void {
		if ($this->tablesInitialized) {
			return;
		}

		$this->createModelsTable($client);

		$this->tablesInitialized = true;
	}

	/**
	 * Create RAG models table
	 *
	 * @param HTTPClient $client
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function createModelsTable(HTTPClient $client): void {
		$sql = /** @lang Manticore */ 'CREATE TABLE IF NOT EXISTS ' . self::MODELS_TABLE . ' (
			uuid string,
			name string,
			llm_provider text,
			llm_model text,
			style_prompt text,
			settings json,
			created_at bigint,
			updated_at bigint
		)';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create('Failed to create models table: ' . $response->getError());
		}
	}


	/**
	 * Create a new RAG model
	 *
	 * @param HTTPClient $client
	 * @param array $config
	 *
	 * @return string Model ID
	 * @throws ManticoreSearchClientError
	 */
	public function createModel(HTTPClient $client, array $config): string {
		$modelName = $config['name'];
		$modelUuid = $this->generateUuid();

		// Check if the model already exists
		if ($this->modelExists($client, $modelName)) {
			 throw ManticoreSearchClientError::create("RAG model '{$modelName}' already exists");
		}

		// Prepare settings
		$settings = $this->extractSettings($config);

			// Insert model
			$currentTime = time();
			$sql = sprintf(
				'INSERT INTO %s (uuid, name, llm_provider, llm_model, style_prompt, settings, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s, %d, %d)',
				self::MODELS_TABLE,
				$this->quote($modelUuid),
				$this->quote($config['name']),
				$this->quote($config['llm_provider']),
				$this->quote($config['llm_model']),
				$this->quote($config['style_prompt'] ?? ''),
				$this->quote(json_encode($settings)),
				$currentTime,
				$currentTime
			);

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create('Failed to create model: ' . $response->getError());
		}

		return $modelUuid;
	}

	/**
	 * Generate a UUID v4
	 *
	 * @return string
	 */
	private function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant 10
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Check if model exists
	 *
	 * @param HTTPClient $client
	 * @param string $modelName
	 * @return bool
	 */
	private function modelExists(HTTPClient $client, string $modelName): bool {
		$sql = sprintf(
			/** @lang Manticore */            'SELECT COUNT(*) as count FROM %s WHERE name = %s',
			self::MODELS_TABLE,
			$this->quote($modelName)
		);

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create('Failed to check model existence: ' . $response->getError());
		}
		$data = $response->getResult()[0]['data'] ?? [];

		return !empty($data) && ($data[0]['count'] ?? 0) > 0;
	}

	/**
	 * Extract settings from config into separate array
	 *
	 * @param array $config
	 * @return array
	 */
	private function extractSettings(array $config): array {
		$coreFields = ['id', 'name', 'llm_provider', 'llm_model', 'llm_api_key', 'style_prompt'];
		$settings = [];

		foreach ($config as $key => $value) {
			if (in_array($key, $coreFields)) {
				continue;
			}

			// If settings is a JSON string, parse it
			if ($key === 'settings' && is_string($value)) {
				$parsedSettings = json_decode($value, true);
				if (json_last_error() === JSON_ERROR_NONE) {
					$settings = array_merge($settings, $parsedSettings);
				} else {
					$settings[$key] = $value;
				}
			} elseif ($key === 'settings' && is_array($value)) {
				// If settings is already an array, merge it
				$settings = array_merge($settings, $value);
			} else {
				$settings[$key] = $value;
			}
		}

		return $settings;
	}

	/**
	 * Get all RAG models
	 *
	 * @param HTTPClient $client
	 *
	 * @return array
	 * @throws ManticoreSearchResponseError
	 * @throws ManticoreSearchClientError
	 */
	public function getAllModels(HTTPClient $client): array {
		$sql = 'SELECT id, uuid, name, llm_provider, llm_model, created_at
				FROM ' . self::MODELS_TABLE . '
				ORDER BY created_at DESC';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Failed to get all models: ' . $response->getError());
		}
		return $response->getResult()[0]['data'] ?? [];
	}

	/**
	 * Delete RAG model by name
	 *
	 * @param HTTPClient $client
	 * @param string $modelName
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function deleteModelByUuidOrName(HTTPClient $client, string $modelUuidOrName): void {

		$model = $this->getModelByUuidOrName($client, $modelUuidOrName);
		if (empty($model)) {
			throw ManticoreSearchClientError::create("RAG model '{$modelUuidOrName}' not found");
		}

		// Soft delete by setting is_active = false
		$sql = sprintf(
			/** @lang Manticore */            'DELETE FROM %s WHERE uuid = %s',
			self::MODELS_TABLE,
			$this->quote($model['uuid'])
		);

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create('Failed to delete model: ' . $response->getError());
		}
	}

	/**
	 * Clean up conversations for a deleted model
	 *
	 * @param HTTPClient $client
	 * @param string $modelUuid
	 *
	 * @return void
	 */

	/**
	 * Get model by name or UUID (returns environment variable names, does not resolve them)
	 *
	 * @param HTTPClient $client
	 * @param string $modelNameOrUuid Model name or UUID
	 *
	 * @return array|null
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function getModelByUuidOrName(HTTPClient $client, string $modelNameOrUuid): ?array {
		$sql = /** @lang Manticore */ 'SELECT * FROM ' . self::MODELS_TABLE . ' WHERE (name = ' . $this->quote($modelNameOrUuid) . ' OR uuid = ' . $this->quote($modelNameOrUuid) . ')';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw  ManticoreSearchResponseError::create('Failed to get model by UUID/name: ' . $response->getError());
		}
		$data = $response->getResult()[0]['data'] ?? [];

		if (empty($data)) {
			return null;
		}

		return $data[0];
	}

	/**
	 * Escape special characters for strings
	 *
	 * @param string $value
	 * @return string
	 */
	private function escape(string $value): string {
		// ManticoreSearch MATCH special characters that need escaping
		$specialChars = ['!', '"', '$', "'", '(', ')', '-', '/', '<', '@', '\\', '^', '|', '~'];

		// Create escaped versions
		$escapedChars = array_map(fn($char) => '\\' . $char, $specialChars);

		// Replace all in one call
		return str_replace($specialChars, $escapedChars, $value);
	}
}
