<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Random\RandomException;

/**
 * Manages RAG model persistence and database operations
 */
class ModelManager {
	use SqlEscapeTrait;
	public const string MODELS_TABLE = 'system.rag_models';

	// Mapping of LLM providers to their environment variable names
	/** @var array{openai: string, anthropic: string, grok: string, mistral: string, ollama: string} */
	public const array PROVIDER_ENV_VARS = [
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
	 * @param array{name: string, llm_provider: string, llm_model: string, style_prompt?:string,
	 *   settings?: string|array<string, mixed>, temperature?: string,
	 *   max_tokens?: string, k_results?: string, similarity_threshold?: string,
	 *   max_document_length?: string} $config
	 *
	 * @return string Model ID
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError|RandomException
	 */
	public function createModel(HTTPClient $client, array $config): string {
		$modelName = $config['name'];
		$modelUuid = $this->generateUuid();

		// Check if the model already exists
		if ($this->modelExists($client, $modelName)) {
			 throw ManticoreSearchClientError::create("RAG model '$modelName' already exists");
		}

		// Prepare settings
		$settings = $this->extractSettings($config);

			// Insert model
			$currentTime = time();
			$sql = sprintf(
				'INSERT INTO %s (uuid, name, llm_provider, llm_model, style_prompt, settings, created_at, updated_at) '.
				'VALUES (%s, %s, %s, %s, %s, %s, %d, %d)',
				self::MODELS_TABLE,
				$this->quote($modelUuid),
				$this->quote($config['name']),
				$this->quote($config['llm_provider']),
				$this->quote($config['llm_model']),
				$this->quote($config['style_prompt'] ?? ''),
				$this->quote($this->encodeSettings($settings)),
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
	 * @throws RandomException
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
	 *
	 * @return bool
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
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

		/** @var array<int, array{data: array<int, array{count: int}>}> $result */
		$result = $response->getResult();
		$data = $result[0]['data'] ?? [];

		return !empty($data) && ($data[0]['count'] ?? 0) > 0;
	}

	/**
	 * Extract settings from config into separate array
	 *
	 * @param array<string, mixed> $config
	 * @return array<string, mixed>
	 */
	private function extractSettings(array $config): array {
		/** @var array<int, string> $coreFields */
		$coreFields = ['id', 'name', 'llm_provider', 'llm_model', 'llm_api_key', 'style_prompt'];
		/** @var array<string, mixed> $settings */
		$settings = [];

		foreach ($config as $key => $value) {
			if (in_array($key, $coreFields)) {
				continue;
			}

			// If settings is a JSON string, parse it
			if ($key === 'settings' && is_string($value)) {
				$parsedSettings = json_decode($value, true);
				if (json_last_error() === JSON_ERROR_NONE && is_array($parsedSettings)) {
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
	 * Safely encode settings to JSON string
	 *
	 * @param array<string, mixed> $settings
	 *
	 * @return string
	 * @throws ManticoreSearchClientError
	 */
	private function encodeSettings(array $settings): string {
		$encoded = json_encode($settings);
		if (json_last_error() !== JSON_ERROR_NONE || empty($encoded)) {
			throw ManticoreSearchClientError::create('Failed to encode settings to JSON: ' . json_last_error_msg());
		}
		return $encoded;
	}

	/**
	 * Get all RAG models
	 *
	 * @param HTTPClient $client
	 *
	 * @return array<int, array{id: string, uuid: string, name: string,
	 *   llm_provider: string, llm_model: string, created_at: string}>
	 * @throws ManticoreSearchResponseError
	 * @throws ManticoreSearchClientError
	 */
	public function getAllModels(HTTPClient $client): array {
		$sql = /** @lang manticore */'SELECT id, uuid, name, llm_provider, llm_model, created_at
				FROM ' . self::MODELS_TABLE . '
				ORDER BY created_at DESC';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Failed to get all models: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array{id: string, uuid: string, name: string,
		 *   llm_provider: string, llm_model: string, created_at: string}>}> $result */
		$result = $response->getResult();
		return $result[0]['data'] ?? [];
	}

	/**
	 * Delete RAG model by UUID or name
	 *
	 * @param HTTPClient $client
	 * @param string $modelUuidOrName
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function deleteModelByUuidOrName(HTTPClient $client, string $modelUuidOrName): void {

		$model = $this->getModelByUuidOrName($client, $modelUuidOrName);

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
	 * @return array{id:string, uuid:string, name:string,llm_provider:string,llm_model:string,
	 *   style_prompt:string,settings:array{ temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold: string, max_document_length: string},created_at:string,updated_at:string}
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function getModelByUuidOrName(HTTPClient $client, string $modelNameOrUuid): array {
		$sql = /** @lang Manticore */ 'SELECT * FROM ' . self::MODELS_TABLE .
			' WHERE (name = ' . $this->quote($modelNameOrUuid) . ' OR uuid = ' . $this->quote($modelNameOrUuid) . ')';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw  ManticoreSearchResponseError::create('Failed to get model by UUID/name: ' . $response->getError());
		}

		$data = $response->getResult();
		if (is_array($data[0]) && !empty($data[0]['data'])) {
			$model = $data[0]['data'][0];
			if (isset($model['settings']) && $model['settings'] !== 'NULL') {
				$model['settings'] = json_decode($model['settings'], true);
			}

			return $model;
		}

		throw ManticoreSearchClientError::create("RAG model '$modelNameOrUuid' not found");
	}


}
