<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Enhanced KNN search engine based on the original php_rag implementation
 * No pattern-based detection - relies on LLM-generated queries and exclusions
 */
class SearchEngine {
	private const DEFAULT_SIMILARITY_THRESHOLD = 0.8;
	private const DEFAULT_K_RESULTS = 5;

	/**
	 * Perform enhanced KNN search with exclusions (based on original enhancedKNNSearch)
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $searchQuery
	 * @param string $excludeQuery
	 * @param array $modelConfig
	 * @param array $options
	 * @param float|null $threshold
	 * @return array
	 */
	public function performSearch(
		HTTPClient $client,
		string $table,
		string $searchQuery,
		string $excludeQuery,
		array $modelConfig,
		array $options = [],
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
			$options,
			$threshold ?? $this->getSimilarityThreshold($modelConfig, $options)
		);
	}

	/**
	 * Get excluded document IDs for a given exclusion query
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $excludeQuery
	 * @param array $modelConfig
	 * @return array
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
		$sql = "SELECT id FROM {$table}
				WHERE knn({$vectorField}, 15, '{$excludeEscaped}')
				AND knn_dist < 0.75";

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			return []; // Return empty array on error
		}

		$result = $response->getResult();
		$excludeResults = $result[0]['data'] ?? [];

		return array_column($excludeResults, 'id');
	}

	/**
	 * Detect vector field in table
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @return string|null
	 */
	private function detectVectorField(HTTPClient $client, string $table): ?string {

			$query = "DESCRIBE {$table}";
			$response = $client->sendRequest($query);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Schema detection failed: ' . $response->getError());
		}
			$schema = $response->getResult()[0]['data'] ?? [];

			// Look for FLOAT_VECTOR fields
		foreach ($schema as $field) {
			if (isset($field['Type']) && strpos(strtoupper($field['Type']), 'FLOAT_VECTOR') !== false) {
				return $field['Field'] ?? null;
			}
		}

		// Common vector field names from original implementation
		$commonNames = [
			'embedding_vector', 'embedding', 'vector', 'embeddings',
			'content_embedding', 'text_embedding',
		];
		foreach ($schema as $field) {
			$fieldName = strtolower($field['Field'] ?? '');
			if (in_array($fieldName, $commonNames)) {
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
	 * @param array $excludedIds
	 * @param array $modelConfig
	 * @param array $options
	 * @param float $threshold
	 * @return array
	 */
	public function performSearchWithExcludedIds(
		HTTPClient $client,
		string $table,
		string $searchQuery,
		array $excludedIds,
		array $modelConfig,
		array $options,
		float $threshold
	): array {
		$kResults = $this->getKResults($modelConfig, $options);
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

		$result = $response->getResult()[0]['data'] ?? [];
		Buddy::info('└─ Results found: ' . sizeof($result));

		return $this->filterVectorFields($result, $table, $client);
	}

	/**
	 * Get K results from configuration
	 *
	 * @param array $modelConfig
	 * @param array $options
	 * @return int
	 */
	private function getKResults(array $modelConfig, array $options): int {
		// Check overrides first
		if (isset($options['overrides']['k_results'])) {
			return (int)$options['overrides']['k_results'];
		}

		// Check direct config
		if (isset($modelConfig['k_results'])) {
			return (int)$modelConfig['k_results'];
		}

		// Check settings
		if (isset($modelConfig['settings'])) {
			$settings = is_string($modelConfig['settings'])
				? json_decode($modelConfig['settings'], true) ?? []
				: $modelConfig['settings'];

			if (isset($settings['k_results'])) {
				return (int)$settings['k_results'];
			}
		}

		return self::DEFAULT_K_RESULTS;
	}

	/**
	 * Remove embedding vector fields from search results (matches original php_rag behavior)
	 *
	 * @param array $results
	 * @param string $table
	 * @param HTTPClient $client
	 * @return array
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

		// Remove vector fields from each result (matches php_rag.php:511)
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
	 * @return array
	 */
	private function getVectorFields(HTTPClient $client, string $table): array {

		$query = "DESCRIBE {$table}";
		$response = $client->sendRequest($query);

		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Vector fields detection failed: ' . $response->getError());
		}
		$schema = $response->getResult()[0]['data'] ?? [];

		$vectorFields = [];
		foreach ($schema as $field) {
			$fieldType = strtoupper($field['Type'] ?? '');
			// Match any float_vector type
			if (strpos($fieldType, 'FLOAT_VECTOR') === false) {
				continue;
			}

			$vectorFields[] = $field['Field'];
		}

		return $vectorFields;
	}

	/**
	 * Get similarity threshold from configuration
	 *
	 * @param array $modelConfig
	 * @param array $options
	 * @return float
	 */
	private function getSimilarityThreshold(array $modelConfig, array $options): float {
		// Check overrides first
		if (isset($options['overrides']['similarity_threshold'])) {
			return (float)$options['overrides']['similarity_threshold'];
		}

		// Check direct config
		if (isset($modelConfig['similarity_threshold'])) {
			return (float)$modelConfig['similarity_threshold'];
		}

		// Check settings
		if (isset($modelConfig['settings'])) {
			$settings = is_string($modelConfig['settings'])
				? json_decode($modelConfig['settings'], true) ?? []
				: $modelConfig['settings'];

			if (isset($settings['similarity_threshold'])) {
				return (float)$settings['similarity_threshold'];
			}
		}

		return self::DEFAULT_SIMILARITY_THRESHOLD;
	}
}
