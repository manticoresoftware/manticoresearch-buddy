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
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Enhanced KNN search engine based on the original php_rag implementation
 * No pattern-based detection - relies on LLM-generated queries and exclusions
 */
class SearchEngine {
	private const float DEFAULT_SIMILARITY_THRESHOLD = 0.8;
	private const int DEFAULT_K_RESULTS = 5;

	/**
	 * Perform enhanced KNN search with exclusions (based on original enhancedKNNSearch)
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $searchQuery
	 * @param string $excludeQuery
	 * @param array{llm_provider: string, llm_model: string, settings?: string|array<string, mixed>,
	 *   k_results?: string|int, similarity_threshold?: string|float} $modelConfig
	 * @param float|null $threshold
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function performSearch(
		HTTPClient $client,
		string $table,
		string $searchQuery,
		string $excludeQuery,
		array $modelConfig,
		?float $threshold = null
	): array {
		// Get excluded IDs first
		$excludedIds = $this->getExcludedIds($client, $table, $excludeQuery);

		// Use optimized search with pre-computed excluded IDs
		return $this->performSearchWithExcludedIds(
			$client,
			$table,
			$searchQuery,
			$excludedIds,
			$modelConfig,
			$threshold ?? $this->getSimilarityThreshold($modelConfig)
		);
	}

	/**
	 * Get excluded document IDs for a given exclusion query
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $excludeQuery
	 *
	 * @return array<int, string|int>
	 * @throws ManticoreSearchResponseError|ManticoreSearchClientError
	 */
	public function getExcludedIds(
		HTTPClient $client,
		string $table,
		string $excludeQuery
	): array {
		if (empty($excludeQuery) || $excludeQuery === 'none') {
			return [];
		}

		$vectorField = $this->detectVectorField($client, $table);
		if (!$vectorField) {
			return [];
		}

		$excludeEscaped = $this->escapeString($excludeQuery);
		$sql = "SELECT id, knn_dist() as knn_dist FROM {$table}
				WHERE knn({$vectorField}, 15, '{$excludeEscaped}')
				AND knn_dist < 0.75";

		Buddy::info("\n[DEBUG EXCLUSION QUERY]");
		Buddy::info("├─ Exclude query: '{$excludeQuery}'");
		Buddy::info("├─ Table: {$table}");
		Buddy::info("├─ Vector field: {$vectorField}");
		Buddy::info('├─ Threshold: 0.75');
		Buddy::info("├─ Final SQL: {$sql}");

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			Buddy::info('└─ Error: ' . $response->getError());
			return []; // Return empty array on error
		}

		/** @var array<int, array{data: array<int, array{id: string|int}>}> $result */
		$result = $response->getResult();
		$excludeResults = $result[0]['data'] ?? [];

		$excludedIds = array_column($excludeResults, 'id');
		Buddy::info('├─ Raw results count: ' . sizeof($excludeResults));
		Buddy::info('└─ Excluded IDs found: [' . implode(', ', $excludedIds) . ']');

		return $excludedIds;
	}

	/**
	 * Detect vector field in table
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 *
	 * @return string|null
	 * @throws ManticoreSearchResponseError|ManticoreSearchClientError
	 */
	private function detectVectorField(HTTPClient $client, string $table): ?string {

		$query = "DESCRIBE {$table}";
		$response = $client->sendRequest($query);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create(
				'Schema detection failed: ' . $response->getError()
			);
		}

		/** @var array<int, array{data: array<int, array{Type: string, Field: string}>}> $result */
		$result = $response->getResult();
		$schema = $result[0]['data'];

		// Look for FLOAT_VECTOR fields
		foreach ($schema as $field) {
			if (str_contains(strtoupper($field['Type']), 'FLOAT_VECTOR')) {
				return $field['Field'];
			}
		}
		return null;
	}

	/**
	 * Escape string for SQL safety
	 *
	 * @param string $string
	 * @return string
	 */
	private function escapeString(string $string): string {
		return str_replace("'", "''", $string);
	}

	/**
	 * Perform vector search with pre-computed excluded IDs (optimized for reuse)
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $searchQuery
	 * @param array<int, string|int> $excludedIds
	 * @param array{llm_provider: string, llm_model: string, settings?: string|array<string, mixed>,
	 *   k_results?: string|int, similarity_threshold?: string|float} $modelConfig
	 * @param float $threshold
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function performSearchWithExcludedIds(
		HTTPClient $client,
		string $table,
		string $searchQuery,
		array $excludedIds,
		array $modelConfig,
		float $threshold
	): array {
		$kResults = $this->getKResults($modelConfig);
		$vectorField = $this->detectVectorField($client, $table);

		if (!$vectorField) {
			return [];
		}

		$searchEscaped = $this->escapeString($searchQuery);

		if (!empty($excludedIds)) {
			// Use pre-computed excluded IDs - NO additional KNN search needed!
			$safeExcludeIds = array_map('intval', $excludedIds);
			$excludeList = implode(',', $safeExcludeIds);
			$adjustedK = $kResults + sizeof($excludedIds) + 5;

			$sql = "SELECT *, knn_dist() as knn_dist
					FROM {$table}
					WHERE knn({$vectorField}, {$adjustedK}, '{$searchEscaped}')
					AND knn_dist < {$threshold}
					AND id NOT IN ({$excludeList})
					LIMIT {$kResults}";
		} else {
			$sql = "SELECT *, knn_dist() as knn_dist
					FROM {$table}
					WHERE knn({$vectorField}, {$kResults}, '{$searchEscaped}')
					AND knn_dist < {$threshold}";
		}

		Buddy::info("\n[DEBUG KNN SEARCH]");
		Buddy::info("├─ Search query: '{$searchQuery}'");
		Buddy::info('├─ Excluded IDs: [' . implode(', ', $excludedIds) . ']');
		Buddy::info("├─ k: {$kResults}");
		Buddy::info("├─ Threshold: {$threshold}");
		Buddy::info("├─ Final SQL: {$sql}");

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Vector search failed: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array<string, mixed>>}> $responseResult */
		$responseResult = $response->getResult();
		$result = $responseResult[0]['data'] ?? [];
		Buddy::info('└─ Results found: ' . sizeof($result));

		return $this->filterVectorFields($result, $table, $client);
	}

	/**
	 * Get K results from configuration
	 *
	 * @param array{llm_provider: string, llm_model: string,
	 *   settings?: string|array<string, mixed>, k_results?: string|int} $modelConfig
	 * @return int
	 */
	private function getKResults(array $modelConfig): int {
		// Check direct config
		if (isset($modelConfig['k_results'])) {
			return (int)$modelConfig['k_results'];
		}

		// Check settings
		if (isset($modelConfig['settings'])) {
			$settings = is_string($modelConfig['settings'])
				? simdjson_decode($modelConfig['settings'], true) ?? []
				: $modelConfig['settings'];

			if (is_array($settings) && isset($settings['k_results'])) {
				return (int)$settings['k_results'];
			}
		}

		return self::DEFAULT_K_RESULTS;
	}

	/**
	 * Remove embedding vector fields from search results (matches original php_rag behavior)
	 *
	 * @param array<int, array<string, mixed>> $results
	 * @param string $table
	 * @param HTTPClient $client
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws ManticoreSearchResponseError|ManticoreSearchClientError
	 */
	private function filterVectorFields(array $results, string $table, HTTPClient $client): array {
		if (empty($results)) {
			return $results;
		}

		// Get all float_vector fields from table schema
		$vectorFields = $this->getVectorFields($client, $table);

		if (empty($vectorFields)) {
			return $results;
		}

		return array_map(
			function ($result) use ($vectorFields) {
				foreach ($vectorFields as $field) {
					unset($result[$field]);
				}
				return $result;
			}, $results
		);
	}

	/**
	 * Get all float_vector field names from table schema
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 *
	 * @return array<int, string>
	 * @throws ManticoreSearchResponseError|ManticoreSearchClientError
	 */
	private function getVectorFields(HTTPClient $client, string $table): array {

		$query = "DESCRIBE $table";
		$response = $client->sendRequest($query);

		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Vector fields detection failed: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array{Type: string, Field: string}>}> $result */
		$result = $response->getResult();
		$schema = $result[0]['data'];

		/** @var array<int, string> $vectorFields */
		$vectorFields = [];
		foreach ($schema as $field) {
			$fieldType = strtoupper($field['Type']);
			// Match any float_vector type
			if (!str_contains($fieldType, 'FLOAT_VECTOR')) {
				continue;
			}

			$vectorFields[] = $field['Field'];
		}

		return $vectorFields;
	}

	/**
	 * Get similarity threshold from configuration
	 *
	 * @param array{llm_provider: string, llm_model: string,
	 *   settings?: string|array<string, mixed>,
	 *   similarity_threshold?: string|float} $modelConfig
	 *
	 * @return float
	 */
	private function getSimilarityThreshold(array $modelConfig): float {

		// Check direct config
		if (isset($modelConfig['similarity_threshold'])) {
			return (float)$modelConfig['similarity_threshold'];
		}

		// Check settings
		if (isset($modelConfig['settings'])) {
			$settings = is_string($modelConfig['settings'])
				? json_decode($modelConfig['settings'], true) ?? []
				: $modelConfig['settings'];

			if (is_array($settings) && isset($settings['similarity_threshold'])) {
				return (float)$settings['similarity_threshold'];
			}
		}

		return self::DEFAULT_SIMILARITY_THRESHOLD;
	}
}
