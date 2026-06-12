<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\Lib\SqlEscapingTrait;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Throwable;

/**
 * Manages chat model persistence and database operations
 */
class ModelManager {
	use SqlEscapingTrait;
	private const int DEFAULT_MAX_DOCUMENT_LENGTH = 2000;

	/**
	 * Create a new chat model
	 *
	 * @param HTTPClient $client
	 * @param array{name: string, model: string, description?: string, api_key?: string,
	 *   base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
	 *   max_document_length?: string|int} $config
	 *
	 * @return string Model name
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError|QueryParseError
	 */
	public function createModel(HTTPClient $client, array $config): string {
		$modelName = $config['name'];
		$tableName = ResourceTable::name(ResourceTable::RESOURCE_CHAT_MODEL, $modelName);

		// Check if the model already exists
		if ($client->hasTable($tableName)) {
			throw ManticoreSearchClientError::create("chat model '$modelName' already exists");
		}

		// Prepare settings
		$settings = $this->extractSettings($config);

		// Insert model
		$currentTime = time();
		$this->createModelTable($client, $tableName);

		$sql = sprintf(
			'INSERT INTO %s (name, description, model, settings, created_at, updated_at) ' .
			'VALUES (%s, %s, %s, %s, %d, %d)',
			$tableName,
			$this->quote($config['name']),
			$this->quote($config['description'] ?? ''),
			$this->quote($config['model']),
			$this->quote($this->encodeSettings($settings)),
			$currentTime,
			$currentTime
		);

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create('Failed to create model: ' . $response->getError());
		}

		new ConversationManager($client, $modelName);

		return $modelName;
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private function createModelTable(HTTPClient $client, string $tableName): void {
		$sql
			= /** @lang Manticore */
			'CREATE TABLE IF NOT EXISTS ' . $tableName . ' (
			name string,
			description text,
			model text,
			settings json,
			created_at bigint,
			updated_at bigint
		)';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create('Failed to create model table: ' . $response->getError());
		}
	}

	/**
	 * @return list<string>
	 * @throws ManticoreSearchClientError|QueryParseError
	 */
	private function getModelTableNames(HTTPClient $client): array {
		$modelNames = [];
		foreach (ResourceTable::list($client, ResourceTable::TABLE_PREFIX_CHAT_MODEL) as $tableName) {
			$modelNames[] = substr($tableName, strlen(ResourceTable::TABLE_PREFIX_CHAT_MODEL));
		}

		return $modelNames;
	}

	/**
	 * Extract settings from config into separate array
	 *
	 * @param array<string, mixed> $config
	 *
	 * @return array<string, mixed>
	 */
	private function extractSettings(array $config): array {
		/** @var array<int, string> $coreFields */
		$coreFields = ['id', 'name', 'description', 'model'];
		/** @var array<string, mixed> $settings */
		$settings = [];

		foreach ($config as $key => $value) {
			if (in_array($key, $coreFields, true)) {
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
	 * Get all chat models
	 *
	 * @param HTTPClient $client
	 *
	 * @return array<int, array{id: string, name: string,
	 *   description: string, model: string, created_at: string}>
	 * @throws ManticoreSearchResponseError
	 * @throws ManticoreSearchClientError
	 */
	public function getAllModels(HTTPClient $client): array {
		$models = [];
		foreach ($this->getModelTableNames($client) as $modelName) {
			$tableName = ResourceTable::name(ResourceTable::RESOURCE_CHAT_MODEL, $modelName);
			try {
				$model = $this->readModelInfo($client, $tableName);
			} catch (ManticoreSearchResponseError $e) {
				if ($this->isPermissionDenied($e->getResponseError())) {
					continue;
				}

				throw $e;
			}
			if ($model === null) {
				continue;
			}

			unset($model['settings'], $model['updated_at']);
			/** @var array{id: string, name: string, description: string, model: string, created_at: string} $model */
			$models[] = $model;
		}

		usort($models, static fn(array $left, array $right): int => $right['created_at'] <=> $left['created_at']);

		return $models;
	}

	private function isPermissionDenied(string $error): bool {
		return stripos($error, 'Permission denied for user') !== false;
	}

	/**
	 * Delete chat model by name
	 *
	 * @param HTTPClient $client
	 * @param string $modelName
	 * @param bool $ifExists
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 * @throws QueryParseError
	 */
	public function deleteModel(
		HTTPClient $client,
		string $modelName,
		bool $ifExists = false
	): void {
		$tableName = ResourceTable::name(ResourceTable::RESOURCE_CHAT_MODEL, $modelName);
		if (!$client->hasTable($tableName)) {
			if ($ifExists) {
				return;
			}

			throw ManticoreSearchClientError::create("chat model '$modelName' not found");
		}

		$sql = sprintf(
			/** @lang Manticore */
			'DROP TABLE IF EXISTS %s',
			$tableName
		);

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create('Failed to delete model: ' . $response->getError());
		}

		$this->dropModelHistoryTable($client, $modelName);
	}

	/**
	 * @throws ManticoreSearchClientError|QueryParseError
	 */
	private function dropModelHistoryTable(HTTPClient $client, string $modelName): void {
		$sql = /** @lang Manticore */
			'DROP TABLE IF EXISTS ' . ResourceTable::name(ResourceTable::RESOURCE_CHAT_HISTORY, $modelName);

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create(
				'Failed to drop conversation history table: ' . $response->getError()
			);
		}
	}

	/**
	 * @return array{
	 *   id:string,
	 *   name:string,
	 *   description:string,
	 *   model:string,
	 *   settings:array{max_document_length:int}&array<string, mixed>,
	 *   created_at:string,
	 *   updated_at:string
	 * }|null
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function readModelInfo(
		HTTPClient $client,
		string $tableName
	): ?array {
		$sql
			= /** @lang Manticore */
			'SELECT * FROM ' . $tableName . ' LIMIT 1';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			$error = (string)$response->getError();
			throw ManticoreSearchResponseError::create('Failed to get model: ' . $error);
		}

		/** @var array<int, array{data: array<int, array<string, mixed>>}> $data */
		$data = $response->getResult();
		if (empty($data[0]['data'])) {
			return null;
		}

		/** @var array{
		 *   id:string,
		 *   name:string,
		 *   description:string,
		 *   model:string,
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
	 * Get model by name (returns environment variable names, does not resolve them)
	 *
	 * @param HTTPClient $client
	 * @param string $modelName Model name
	 *
	 * @return array{
	 *   id:string,
	 *   name:string,
	 *   description:string,
	 *   model:string,
	 *   settings:array{max_document_length:int}&array<string, mixed>,
	 *   created_at:string,
	 *   updated_at:string
	 * }
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function getModel(HTTPClient $client, string $modelName): array {
		$tableName = ResourceTable::name(ResourceTable::RESOURCE_CHAT_MODEL, $modelName);
		try {
			$model = $this->readModelInfo($client, $tableName);
		} catch (ManticoreSearchResponseError $e) {
			if (str_contains($e->getResponseError(), "unknown local table(s) '$tableName'")) {
				throw ManticoreSearchClientError::create("chat model '$modelName' not found");
			}

			throw $e;
		}
		if ($model === null) {
			throw ManticoreSearchClientError::create("chat model '$modelName' not found");
		}

		return $model;
	}
}
