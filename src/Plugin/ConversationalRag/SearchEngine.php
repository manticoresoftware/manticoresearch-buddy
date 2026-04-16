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

	private const int DEFAULT_RETRIEVAL_LIMIT = 5;
	/** @var array<string, TableSchema> */
	private array $schemaCache = [];

	public function __construct(private readonly HTTPClient $client) {
	}

	/**
	 * Get excluded document IDs for a given exclusion query
	 *
	 * @param string $table
	 * @param string $excludeQuery
	 *
	 * @return array<int, string|int>
	 * @throws ManticoreSearchResponseError|ManticoreSearchClientError
	 */
	public function getExcludedIds(
		string $table,
		string $excludeQuery
	): array {
		if (empty($excludeQuery) || $excludeQuery === 'none') {
			return [];
		}

		return $this->findExcludedIds(
			$table, $excludeQuery, $this->inspectTableSchema($table)
		);
	}

	/**
	 * @param string $table
	 * @param string $excludeQuery
	 * @param TableSchema $schema
	 *
	 * @return array<int, string|int>
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function findExcludedIds(
		string $table,
		string $excludeQuery,
		TableSchema $schema
	): array {
		if (empty($excludeQuery) || $excludeQuery === 'none') {
			return [];
		}

		$excludeEscaped = $this->escapeString($excludeQuery);
		$sql
			= /** @lang manticore */
			"SELECT id, knn_dist() as knn_dist FROM {$table}
				WHERE knn($schema->vectorField, 15, '$excludeEscaped')
				AND knn_dist < 0.75";

		Buddy::debugv("\nRAG: [DEBUG EXCLUSION QUERY]");
		Buddy::debugv("RAG: ├─ Exclude query: '$excludeQuery'");
		Buddy::debugv("RAG: ├─ Table: $table");
		Buddy::debugv("RAG: ├─ Vector field: $schema->vectorField");
		Buddy::debugv('RAG: ├─ Threshold: 0.75');
		Buddy::debugv("RAG: ├─ Final SQL: $sql");

		$response = $this->client->sendRequest($sql);
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
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function inspectTableSchema(string $table): TableSchema {
		if (isset($this->schemaCache[$table])) {
			return $this->schemaCache[$table];
		}

		$this->schemaCache[$table] = $this->inspectCreateTableSchema(
			$table,
			$this->getCreateTableStatement($table)
		);
		return $this->schemaCache[$table];
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private function inspectCreateTableSchema(string $table, string $createTable): TableSchema {
		$vectorDefinitions = $this->extractVectorFieldDefinitions($createTable);
		if ($vectorDefinitions === []) {
			throw ManticoreSearchClientError::create("Table '$table' has no FLOAT_VECTOR field");
		}

		$vectorFields = array_keys($vectorDefinitions);
		$vectorField = $vectorFields[0];
		$vectorDefinition = $vectorDefinitions[$vectorField];
		if (!preg_match("/\\bfrom\\s*=\\s*'([^']+)'/i", $vectorDefinition, $matches)) {
			throw ManticoreSearchClientError::create(
				"FLOAT_VECTOR field '$vectorField' has no auto-embedding source fields"
			);
		}

		$contentFields = trim($matches[1]);
		if ($contentFields === '') {
			throw ManticoreSearchClientError::create(
				"FLOAT_VECTOR field '$vectorField' has empty auto-embedding source fields"
			);
		}

		return new TableSchema($vectorField, $vectorFields, $contentFields);
	}

	/**
	 * @throws ManticoreSearchResponseError|ManticoreSearchClientError
	 */
	private function getCreateTableStatement(string $table): string {
		$response = $this->client->sendRequest("SHOW CREATE TABLE $table");
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Show create table inspection failed: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array<string, mixed>>}> $result */
		$result = $response->getResult();
		foreach ($result[0]['data'][0] as $value) {
			if (is_string($value) && str_contains(strtoupper($value), 'CREATE TABLE')) {
				return $value;
			}
		}

		throw ManticoreSearchResponseError::create('Show create table inspection returned no CREATE TABLE statement');
	}

	/**
	 * @return array<string, string>
	 */
	private function extractVectorFieldDefinitions(string $createTable): array {
		$pattern = '/(?:^|[\s,(])`?(?P<field>[A-Za-z_][A-Za-z0-9_]*)`?\s+FLOAT_VECTOR\b(?P<definition>.*?)
			(?=,\s*`?[A-Za-z_][A-Za-z0-9_]*`?\s+[A-Za-z_]|\n\)|\)\s|$)/isx';
		$matchCount = preg_match_all($pattern, $createTable, $matches, PREG_SET_ORDER);
		if ($matchCount === false || $matchCount === 0) {
			return [];
		}

		$vectorFields = [];
		foreach ($matches as $match) {
			$vectorFields[$match['field']] = $match['definition'];
		}

		return $vectorFields;
	}

	/**
	 * Perform vector search.
	 *
	 * @param string $table
	 * @param string $searchQuery
	 * @param array<int, string|int> $excludedIds
	 * @param array{model: string, settings:array<string, mixed>} $modelConfig
	 * @param float $threshold
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function search(
		string $table,
		string $searchQuery,
		array $excludedIds,
		array $modelConfig,
		float $threshold
	): array {
		return $this->runVectorSearch(
			$table,
			$searchQuery,
			$excludedIds,
			$modelConfig,
			$threshold,
			$this->inspectTableSchema($table)
		);
	}

	/**
	 * @param string $table
	 * @param string $searchQuery
	 * @param array<int, string|int> $excludedIds
	 * @param array{model: string, settings:array<string, mixed>} $modelConfig
	 * @param float $threshold
	 * @param TableSchema $schema
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function runVectorSearch(
		string $table,
		string $searchQuery,
		array $excludedIds,
		array $modelConfig,
		float $threshold,
		TableSchema $schema
	): array {
		$retrievalLimit = $this->getRetrievalLimit($modelConfig);

		$knnK = $retrievalLimit;
		$excludeClause = '';
		if (!empty($excludedIds)) {
			$safeExcludeIds = array_map('intval', $excludedIds);
			$excludeClause = 'AND id NOT IN (' . implode(',', $safeExcludeIds) . ')';
			$knnK += sizeof($excludedIds) + 5;
		}
		$sql = $this->buildVectorSearchSql(
			$table,
			$schema->vectorField,
			$searchQuery,
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

		$response = $this->client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Vector search failed: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array<string, mixed>>}> $responseResult */
		$responseResult = $response->getResult();
		$result = $responseResult[0]['data'] ?? [];
		Buddy::debugv('RAG: └─ Results found: ' . sizeof($result));

		return $this->filterVectorFields($result, $schema);
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

	/**
	 * @throws ManticoreSearchClientError
	 */
	private function buildVectorSearchSql(
		string $table,
		string $vectorField,
		string $searchQuery,
		int $knnK,
		float $threshold,
		int $limit,
		string $excludeClause
	): string {
		$searchTerms = $this->splitSearchQueryTerms($searchQuery);
		$whereClauses = [
			$this->buildKnnWhereSql($vectorField, $knnK, $searchTerms),
			"knn_dist < $threshold",
		];
		if ($excludeClause !== '') {
			$whereClauses[] = $excludeClause;
		}

		$sql = sprintf(
		/** @lang manticore */
			'SELECT *, knn_dist() as knn_dist FROM %s WHERE %s LIMIT %d',
			$table,
			implode(' AND ', $whereClauses),
			$limit
		);

		if (sizeof($searchTerms) > 1) {
			$sql .= " OPTION fusion_method='rrf'";
		}

		return $sql;
	}

	/**
	 * @return array<int, string>
	 * @throws ManticoreSearchClientError
	 */
	private function splitSearchQueryTerms(string $searchQuery): array {
		$terms = [];
		foreach (explode(',', $searchQuery) as $term) {
			$normalizedTerm = trim($term);
			if ($normalizedTerm === '') {
				continue;
			}

			$terms[] = $normalizedTerm;
		}

		if ($terms === []) {
			throw ManticoreSearchClientError::create('Search query must contain at least one term');
		}

		return $terms;
	}

	/**
	 * @param array<int, string> $searchTerms
	 */
	private function buildKnnWhereSql(string $vectorField, int $knnK, array $searchTerms): string {
		$knnClauses = [];
		foreach ($searchTerms as $searchTerm) {
			$searchEscaped = $this->escapeString($searchTerm);
			$knnClauses[] = "knn($vectorField, $knnK, '$searchEscaped')";
		}

		return implode(' AND ', $knnClauses);
	}

	/**
	 * Remove embedding vector fields from search results (matches original php_rag behavior)
	 *
	 * @param array<int, array<string, mixed>> $results
	 * @param TableSchema $schema
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function filterVectorFields(array $results, TableSchema $schema): array {
		if (empty($results)) {
			return $results;
		}

		return array_map(
			function ($result) use ($schema) {
				foreach ($schema->vectorFields as $field) {
					unset($result[$field]);
				}
				return $result;
			}, $results
		);
	}

}
