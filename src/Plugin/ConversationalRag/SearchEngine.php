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
		try {
			// Get search parameters
			$kResults = $this->getKResults($modelConfig, $options);
			$threshold = $threshold ?? $this->getSimilarityThreshold($modelConfig, $options);

			// Detect if table has vector embeddings
			$vectorField = $this->detectVectorField($client, $table);

			if ($vectorField) {
				// Perform vector search with exclusions
				return $this->performVectorSearch(
					$client,
					$table,
					$vectorField,
					$searchQuery,
					$excludeQuery,
					$kResults,
					$threshold
				);
			}

			// Fallback to full-text search
			return $this->performFullTextSearch(
				$client,
				$table,
				$searchQuery,
				$excludeQuery,
				$kResults
			);
		} catch (\Exception $e) {
			throw new ManticoreSearchClientError('Search failed: ' . $e->getMessage());
		}
	}

	/**
	 * Perform vector-based similarity search with exclusions (original approach)
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $vectorField
	 * @param string $searchQuery
	 * @param string $excludeQuery
	 * @param int $kResults
	 * @param float $threshold
	 * @return array
	 */
	private function performVectorSearch(
		HTTPClient $client,
		string $table,
		string $vectorField,
		string $searchQuery,
		string $excludeQuery,
		int $kResults,
		float $threshold
	): array {
		Buddy::info("\n[DEBUG KNN SEARCH]");
		Buddy::info("├─ Search query: '{$searchQuery}'");
		Buddy::info("├─ Exclude query: '{$excludeQuery}'");
		Buddy::info("├─ k: {$kResults}");
		Buddy::info("├─ Threshold: {$threshold}");

		$searchEscaped = $this->escapeString($searchQuery);
		$excludeIds = [];

		// Maximum exclusions limit to prevent massive IN clauses
		$maxExclusions = 50;

		// Step 1: Get IDs to exclude if needed (from original)
		if ($excludeQuery !== 'none' && !empty(trim($excludeQuery))) {
			$excludeEscaped = $this->escapeString($excludeQuery);
			$excludeSql = "SELECT id FROM {$table} WHERE knn({$vectorField}, 15, '{$excludeEscaped}') AND knn_dist < 0.75";

			try {
				$excludeResponse = $client->sendRequest($excludeSql);
				$excludeResults = $excludeResponse->getResult()[0]['data'] ?? [];
				$excludeIds = array_column($excludeResults, 'id');

				// Limit exclusions
				if (count($excludeIds) > $maxExclusions) {
					$excludeIds = array_slice($excludeIds, 0, $maxExclusions);
				}

				Buddy::info("├─ Exclusion SQL: {$excludeSql}");
				Buddy::info('├─ Excluded IDs: [' . implode(', ', $excludeIds) . ']');
				Buddy::info('├─ Exclusion count: ' . count($excludeIds));
			} catch (\Exception $e) {
				// Continue without exclusions if query fails
				$excludeIds = [];
			}
		} else {
			Buddy::info('├─ No exclusions');
		}

		// Step 2: Build KNN query with SQL-level exclusion (from original)
		// Increase k when we have exclusions to ensure we get enough results
		$adjustedK = !empty($excludeIds) ? ($kResults + count($excludeIds) + 5) : $kResults;

		// Ensure threshold is a valid float
		$threshold = floatval($threshold);

		Buddy::info("├─ Adjusted k: {$adjustedK}");

		if (!empty($excludeIds)) {
			// Sanitize IDs - they should be integers
			$safeExcludeIds = array_map('intval', $excludeIds);
			$excludeList = implode(',', $safeExcludeIds);

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

		Buddy::info("├─ Final SQL: {$sql}");

		try {
			$response = $client->sendRequest($sql);
			$result = $response->getResult()[0]['data'] ?? [];

			Buddy::info('└─ Results found: ' . count($result));

			// Filter out embedding vector fields (matches original php_rag behavior)
			return $this->filterVectorFields($result, $table, $client);
		} catch (\Exception $e) {
			// Return empty result structure
			Buddy::info('Vector search failed: ' . $e->getMessage());
			return [];
		}
	}

	/**
	 * Perform full-text search fallback
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $searchQuery
	 * @param string $excludeQuery
	 * @param int $kResults
	 * @return array
	 */
	private function performFullTextSearch(
		HTTPClient $client,
		string $table,
		string $searchQuery,
		string $excludeQuery,
		int $kResults
	): array {
		$escapedQuery = $this->escapeString($searchQuery);

		// Build full-text search
		$query = "SELECT *, WEIGHT() as _score FROM {$table} ";
		$query .= "WHERE MATCH('{$escapedQuery}') ";

		// Add exclusion conditions if provided
		if (!empty($excludeQuery) && $excludeQuery !== 'none') {
			$exclusions = $this->buildExclusionConditions($excludeQuery);
			if (!empty($exclusions)) {
				$query .= "AND {$exclusions} ";
			}
		}

		// Add ordering and limit
		$query .= "ORDER BY _score DESC LIMIT {$kResults}";

		try {
			$response = $client->sendRequest($query);
			$result = $response->getResult()[0]['data'] ?? [];
			// Filter out embedding vector fields (matches original php_rag behavior)
			return $this->filterVectorFields($result, $table, $client);
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Build exclusion conditions from exclude query
	 *
	 * @param string $excludeQuery
	 * @return string
	 */
	private function buildExclusionConditions(string $excludeQuery): string {
		if (empty($excludeQuery)) {
			return '';
		}

		// Parse exclusion terms (assumes they are quoted titles/names from LLM)
		preg_match_all('/"([^"]+)"/', $excludeQuery, $matches);
		$excludeTerms = $matches[1] ?? [];

		// If no quoted terms, split by common separators
		if (empty($excludeTerms)) {
			$excludeTerms = preg_split('/\s*(?:,|OR|\|)\s*/', $excludeQuery);
			$excludeTerms = array_filter(array_map('trim', $excludeTerms));
		}

		if (empty($excludeTerms)) {
			return '';
		}

		$conditions = [];
		foreach ($excludeTerms as $term) {
			$escapedTerm = $this->escapeString($term);
			// Try common title/name fields
			$conditions[] = "(title != '{$escapedTerm}' AND name != '{$escapedTerm}')";
		}

		return implode(' AND ', $conditions);
	}

	/**
	 * Detect vector field in table
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @return string|null
	 */
	private function detectVectorField(HTTPClient $client, string $table): ?string {
		try {
			// Get table schema
			$query = "DESCRIBE {$table}";
			$response = $client->sendRequest($query);
			$schema = $response->getResult()[0]['data'] ?? [];

			// Look for FLOAT_VECTOR fields
			foreach ($schema as $field) {
				if (isset($field['Type']) && strpos(strtoupper($field['Type']), 'FLOAT_VECTOR') !== false) {
					return $field['Field'] ?? null;
				}
			}

			// Common vector field names from original implementation
			$commonNames = ['embedding_vector', 'embedding', 'vector', 'embeddings', 'content_embedding', 'text_embedding'];
			foreach ($schema as $field) {
				$fieldName = strtolower($field['Field'] ?? '');
				if (in_array($fieldName, $commonNames)) {
					return $field['Field'];
				}
			}

			return null;
		} catch (\Exception $e) {
			// If we can't detect the schema, assume no vector field
			return null;
		}
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
		try {
			$query = "DESCRIBE {$table}";
			$response = $client->sendRequest($query);
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
		} catch (\Exception $e) {
			// If schema detection fails, fallback to common vector field names
			return ['embedding_vector', 'embedding', 'vector', 'embeddings'];
		}
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
}
