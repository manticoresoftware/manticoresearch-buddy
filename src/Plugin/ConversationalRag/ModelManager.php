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
use Manticoresearch\Buddy\Core\Lib\SqlEscapingTrait;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Random\RandomException;
use Throwable;

/**
 * Manages RAG model persistence and database operations
 */
class ModelManager {
	use SqlEscapingTrait;

	public const string MODELS_TABLE = 'system.rag_models';
	private const int DEFAULT_MAX_DOCUMENT_LENGTH = 2000;
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
		$sql
			= /** @lang Manticore */
			'CREATE TABLE IF NOT EXISTS ' . self::MODELS_TABLE . ' (
			uuid string,
			name string,
			description text,
			model text,
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
	 * @param array{name: string, model: string, description?: string, style_prompt?:string, api_key?: string,
	 *   base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
	 *   max_document_length?: string|int} $config
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
			'INSERT INTO %s (uuid, name, description, model, style_prompt, settings, created_at, updated_at) ' .
			'VALUES (%s, %s, %s, %s, %s, %s, %d, %d)',
			self::MODELS_TABLE,
			$this->quote($modelUuid),
			$this->quote($config['name']),
			$this->quote($config['description'] ?? ''),
			$this->quote($config['model']),
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
	 *
	 * @return array<string, mixed>
	 * @throws ManticoreSearchClientError
	 */
	private function extractSettings(array $config): array {
		/** @var array<int, string> $coreFields */
		$coreFields = ['id', 'name', 'description', 'model', 'style_prompt'];
		/** @var array<string, mixed> $settings */
		$settings = [];

		foreach ($config as $key => $value) {
			if (in_array($key, $coreFields)) {
				continue;
			}

			$settings[$key] = $value;
		}

		$maxDocumentLength = $settings['max_document_length'] ?? null;
		if (!is_int($maxDocumentLength) && !is_string($maxDocumentLength) && $maxDocumentLength !== null) {
			$maxDocumentLength = null;
		}

		$settings['max_document_length'] = $this->normalizeMaxDocumentLength($maxDocumentLength);

		return $settings;
	}

	private function normalizeMaxDocumentLength(int|string|null $value): int {
		if ($value === null) {
			return self::DEFAULT_MAX_DOCUMENT_LENGTH;
		}

		$maxDocumentLength = (int)$value;
		if ($maxDocumentLength === 0 || ($maxDocumentLength >= 100 && $maxDocumentLength <= 65536)) {
			return $maxDocumentLength;
		}

		return self::DEFAULT_MAX_DOCUMENT_LENGTH;
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
	 *   description: string, model: string, created_at: string}>
	 * @throws ManticoreSearchResponseError
	 * @throws ManticoreSearchClientError
	 */
	public function getAllModels(HTTPClient $client): array {
		$sql
			= /** @lang manticore */
			'SELECT id, uuid, name, description, model, created_at
				FROM ' . self::MODELS_TABLE . '
				ORDER BY created_at DESC';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Failed to get all models: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array{id: string, uuid: string, name: string,
		 *   description: string, model: string, created_at: string}>}> $result
		 */
		$result = $response->getResult();
		return $result[0]['data'];
	}

	/**
	 * Delete RAG model by UUID or name
	 *
	 * @param HTTPClient $client
	 * @param string $modelUuidOrName
	 * @param bool $ifExists
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function deleteModelByUuidOrName(
		HTTPClient $client,
		string $modelUuidOrName,
		bool $ifExists = false
	): void {
		$model = $this->findModelByUuidOrName($client, $modelUuidOrName);
		if ($model === null) {
			if ($ifExists) {
				return;
			}

			throw ManticoreSearchClientError::create("RAG model '$modelUuidOrName' not found");
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
	 * @param HTTPClient $client
	 * @param string $modelNameOrUuid
	 *
	 * @return array{
	 *   id:string,
	 *   uuid:string,
	 *   name:string,
	 *   description:string,
	 *   model:string,
	 *   style_prompt:string,
	 *   settings:array{max_document_length:int}&array<string, mixed>,
	 *   created_at:string,
	 *   updated_at:string
	 * }|null
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function findModelByUuidOrName(HTTPClient $client, string $modelNameOrUuid): ?array {
		$sql
			= /** @lang Manticore */
			'SELECT id, uuid, name, description, model, style_prompt, settings, created_at, updated_at FROM '
			. self::MODELS_TABLE .
			' WHERE (name = ' . $this->quote($modelNameOrUuid) . ' OR uuid = ' . $this->quote($modelNameOrUuid) . ')';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw  ManticoreSearchResponseError::create('Failed to get model by UUID/name: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array{
		 *   id:string,
		 *   uuid:string,
		 *   name:string,
		 *   description:string,
		 *   model:string,
		 *   style_prompt:string,
		 *   settings:mixed,
		 *   created_at:string,
		 *   updated_at:string
		 * }>}> $data
		 */
		$data = $response->getResult();
		if (empty($data[0]['data'])) {
			return null;
		}

		/** @var array{
		 *   id:string,
		 *   uuid:string,
		 *   name:string,
		 *   description:string,
		 *   model:string,
		 *   style_prompt:string,
		 *   settings:array{max_document_length:int}&array<string, mixed>,
		 *   created_at:string,
		 *   updated_at:string
		 * } $model
		 */
		$model = $data[0]['data'][0];
		$model['settings'] = $this->decodeModelSettings($model);

		return $model;
	}

	/**
	 * @param array<string, mixed> $model
	 *
	 * @return array{max_document_length:int}&array<string, mixed>
	 * @throws ManticoreSearchClientError
	 */
	private function decodeModelSettings(array $model): array {
		if (!array_key_exists('settings', $model) || !is_string($model['settings'])) {
			throw ManticoreSearchClientError::create('Invalid model settings JSON: settings must be a JSON string');
		}

		try {
			$decoded = simdjson_decode($model['settings'], true);
		} catch (Throwable $e) {
			throw ManticoreSearchClientError::create('Invalid model settings JSON: ' . $e->getMessage());
		}

		if (!is_array($decoded)) {
			throw ManticoreSearchClientError::create('Invalid model settings JSON');
		}

		/** @var array{max_document_length:int}&array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * Get model by name or UUID (returns environment variable names, does not resolve them)
	 *
	 * @param HTTPClient $client
	 * @param string $modelNameOrUuid Model name or UUID
	 *
	 * @return array{
	 *   id:string,
	 *   uuid:string,
	 *   name:string,
	 *   description:string,
	 *   model:string,
	 *   style_prompt:string,
	 *   settings:array{max_document_length:int}&array<string, mixed>,
	 *   created_at:string,
	 *   updated_at:string
	 * }
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function getModelByUuidOrName(HTTPClient $client, string $modelNameOrUuid): array {
		$model = $this->findModelByUuidOrName($client, $modelNameOrUuid);
		if ($model !== null) {
			return $model;
		}

		throw ManticoreSearchClientError::create("RAG model '$modelNameOrUuid' not found");
	}
}
