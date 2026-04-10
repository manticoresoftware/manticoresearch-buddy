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
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Enhanced KNN search engine based on the original php_rag implementation
 * No pattern-based detection - relies on LLM-generated queries and exclusions
 */
class SearchEngine {
	use SqlEscapingTrait;

	private const float DEFAULT_SIMILARITY_THRESHOLD = 0.8;
	private const int DEFAULT_RETRIEVAL_LIMIT = 5;
	private const string TABLE_IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/';

	/**
	 * Perform enhanced KNN search with exclusions (based on original enhancedKNNSearch)
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $searchQuery
	 * @param string $excludeQuery
	 * @param array{model: string, settings:array<string, mixed>} $modelConfig
	 * @param float|null $threshold
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws ManticoreSearchResponseError|ManticoreSearchClientError
	 */
	public function performSearch(
		HTTPClient $client,
		string $table,
		string $searchQuery,
		string $excludeQuery,
		array $modelConfig,
		?float $threshold = null
	): array {
		$this->assertSearchableTable($client, $table);

		// Get excluded IDs first
		$excludedIds = $this->getExcludedIdsForValidatedTable($client, $table, $excludeQuery);

		// Use optimized search with pre-computed excluded IDs
		return $this->performSearchWithExcludedIdsForValidatedTable(
			$client,
			$table,
			$searchQuery,
			$excludedIds,
			$modelConfig,
			$threshold ?? $this->getSimilarityThreshold()
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
		$this->assertSearchableTable($client, $table);
		return $this->getExcludedIdsForValidatedTable($client, $table, $excludeQuery);
	}

	/**
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $excludeQuery
	 *
	 * @return array<int, string|int>
	 * @throws ManticoreSearchResponseError|ManticoreSearchClientError
	 */
	private function getExcludedIdsForValidatedTable(
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
		$sql
			= /** @lang manticore */
			"SELECT id, knn_dist() as knn_dist FROM {$table}
				WHERE knn($vectorField, 15, '$excludeEscaped')
				AND knn_dist < 0.75";

		Buddy::debugv("\nRAG: [DEBUG EXCLUSION QUERY]");
		Buddy::debugv("RAG: ├─ Exclude query: '$excludeQuery'");
		Buddy::debugv("RAG: ├─ Table: $table");
		Buddy::debugv("RAG: ├─ Vector field: $vectorField");
		Buddy::debugv('RAG: ├─ Threshold: 0.75');
		Buddy::debugv("RAG: ├─ Final SQL: $sql");

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Exclusion query failed: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array{id: string|int}>}> $result */
		$result = $response->getResult();
		$excludeResults = $result[0]['data'] ?? [];

		$excludedIds = array_column($excludeResults, 'id');
		Buddy::debugv('RAG: ├─ Raw results count: ' . sizeof($excludeResults));
		Buddy::debugv('RAG: └─ Excluded IDs found: [' . implode(', ', $excludedIds) . ']');

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
		$query = "DESCRIBE $table";
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
	 * Perform vector search with pre-computed excluded IDs (optimized for reuse)
	 *
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $searchQuery
	 * @param array<int, string|int> $excludedIds
	 * @param array{model: string, settings:array<string, mixed>} $modelConfig
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
		$this->assertSearchableTable($client, $table);
		return $this->performSearchWithExcludedIdsForValidatedTable(
			$client,
			$table,
			$searchQuery,
			$excludedIds,
			$modelConfig,
			$threshold
		);
	}

	/**
	 * @param HTTPClient $client
	 * @param string $table
	 * @param string $searchQuery
	 * @param array<int, string|int> $excludedIds
	 * @param array{model: string, settings:array<string, mixed>} $modelConfig
	 * @param float $threshold
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function performSearchWithExcludedIdsForValidatedTable(
		HTTPClient $client,
		string $table,
		string $searchQuery,
		array $excludedIds,
		array $modelConfig,
		float $threshold
	): array {
		$retrievalLimit = $this->getRetrievalLimit($modelConfig);
		$vectorField = $this->detectVectorField($client, $table);

		if (!$vectorField) {
			return [];
		}

		$searchEscaped = $this->escapeString($searchQuery);
		$knnK = $retrievalLimit;
		$excludeClause = '';
		if (!empty($excludedIds)) {
			$safeExcludeIds = array_map('intval', $excludedIds);
			$excludeClause = 'AND id NOT IN (' . implode(',', $safeExcludeIds) . ')';
			$knnK += sizeof($excludedIds) + 5;
		}
		$sql = $this->buildVectorSearchSql(
			$table,
			$vectorField,
			$searchEscaped,
			$knnK,
			$threshold,
			$retrievalLimit,
			$excludeClause
		);

		Buddy::debugv("\nRAG: [DEBUG KNN SEARCH]");
		Buddy::debugv("RAG: ├─ Search query: '$searchQuery'");
		Buddy::debugv('RAG: ├─ Excluded IDs: [' . implode(', ', $excludedIds) . ']');
		Buddy::debugv("RAG: ├─ retrieval_limit: $retrievalLimit");
		Buddy::debugv("RAG: ├─ Threshold: $threshold");
		Buddy::debugv("RAG: ├─ Final SQL: $sql");

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Vector search failed: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array<string, mixed>>}> $responseResult */
		$responseResult = $response->getResult();
		$result = $responseResult[0]['data'] ?? [];
		Buddy::debugv('RAG: └─ Results found: ' . sizeof($result));

		return $this->filterVectorFields($result, $table, $client);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function assertSearchableTable(HTTPClient $client, string $table): void {
		if (preg_match(self::TABLE_IDENTIFIER_PATTERN, $table) !== 1) {
			throw ManticoreSearchClientError::create('Invalid table identifier');
		}

		if (!$client->hasTable($table)) {
			throw ManticoreSearchClientError::create("Table '$table' not found");
		}
	}

	/**
	 * @param array{model: string, settings:array<string, mixed>} $modelConfig
	 *
	 * @return int
	 */
	private function getRetrievalLimit(array $modelConfig): int {
		if (!isset($modelConfig['settings']['retrieval_limit'])) {
			return self::DEFAULT_RETRIEVAL_LIMIT;
		}

		/** @var int|string $retrievalLimit */
		$retrievalLimit = $modelConfig['settings']['retrieval_limit'];
		return (int)$retrievalLimit;
	}

	private function buildVectorSearchSql(
		string $table,
		string $vectorField,
		string $searchEscaped,
		int $knnK,
		float $threshold,
		int $limit,
		string $excludeClause
	): string {
		$excludeSql = $excludeClause !== '' ? "\n\t\t\t\t\t$excludeClause" : '';

		return /** @lang manticore */ "SELECT *, knn_dist() as knn_dist
				FROM {$table}
				WHERE knn($vectorField, $knnK, '$searchEscaped')
				AND knn_dist < $threshold{$excludeSql}
				LIMIT $limit";
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
	 * @return float
	 */
	private function getSimilarityThreshold(): float {
		return self::DEFAULT_SIMILARITY_THRESHOLD;
	}
}
