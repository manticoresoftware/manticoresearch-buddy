<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Search\TableSchemaInspector;
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
	private TableSchemaInspector $schemaInspector;

	public function __construct(private readonly HTTPClient $client) {
		$this->schemaInspector = new TableSchemaInspector($client);
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
		return $this->schemaInspector->inspect($table);
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
		float $threshold,
		string $requestedFields = ''
	): array {
		$schema = $this->inspectTableSchema($table);
		$searchVectorFields = $this->resolveSearchVectorFields($requestedFields, $schema);

		return $this->runVectorSearch(
			$table,
			$searchQuery,
			$excludedIds,
			$modelConfig,
			$threshold,
			$schema,
			$searchVectorFields
		);
	}

	/**
	 * @param string $table
	 * @param string $searchQuery
	 * @param array<int, string|int> $excludedIds
	 * @param array{model: string, settings:array<string, mixed>} $modelConfig
	 * @param float $threshold
	 * @param TableSchema $schema
	 * @param array<int, string> $searchVectorFields
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
		TableSchema $schema,
		array $searchVectorFields
	): array {
		$retrievalLimit = $this->getRetrievalLimit($modelConfig);
		[$knnK, $excludeClause] = $this->buildSearchParams($retrievalLimit, $excludedIds);
		$mergedById = [];
		foreach ($searchVectorFields as $vectorField) {
			$rows = $this->executeVectorSearch(
				$table, $vectorField, $searchQuery, $knnK, $threshold, $retrievalLimit, $excludeClause, $excludedIds
			);
			$this->mergeRowsByBestDistance($mergedById, $rows);
		}

		$merged = array_values($mergedById);
		usort(
			$merged,
			function (array $left, array $right): int {
				$leftDist = $this->extractKnnDistance($left);
				$rightDist = $this->extractKnnDistance($right);
				return $leftDist <=> $rightDist;
			}
		);

		if (sizeof($merged) > $retrievalLimit) {
			$merged = array_slice($merged, 0, $retrievalLimit);
		}

		Buddy::debugv('RAG: └─ Merged results after vector fusion: ' . sizeof($merged));
		return $this->filterVectorFields($merged, $schema);
	}

	/**
	 * @param int $retrievalLimit
	 * @param array<int, string|int> $excludedIds
	 *
	 * @return array{int, string}
	 */
	private function buildSearchParams(int $retrievalLimit, array $excludedIds): array {
		$knnK = $retrievalLimit;
		$excludeClause = '';
		if (!empty($excludedIds)) {
			$safeExcludeIds = array_map('intval', $excludedIds);
			$excludeClause = 'id NOT IN (' . implode(',', $safeExcludeIds) . ')';
			$knnK += sizeof($excludedIds) + 5;
		}

		return [$knnK, $excludeClause];
	}

	/**
	 * @param array<int, string|int> $excludedIds
	 * @return array<int, array<string, mixed>>
	 * @throws ManticoreSearchResponseError
	 * @throws ManticoreSearchClientError
	 */
	private function executeVectorSearch(
		string $table,
		string $vectorField,
		string $searchQuery,
		int $knnK,
		float $threshold,
		int $retrievalLimit,
		string $excludeClause,
		array $excludedIds
	): array {
		$sql = $this->buildVectorSearchSql(
			$table,
			$vectorField,
			$searchQuery,
			$knnK,
			$threshold,
			$retrievalLimit,
			$excludeClause
		);

		Buddy::debugv("\nRAG: [DEBUG KNN SEARCH]");
		Buddy::debugv("RAG: ├─ Search query: '$searchQuery'");
		Buddy::debugv("RAG: ├─ Vector field: $vectorField");
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
		$rows = $responseResult[0]['data'] ?? [];
		Buddy::debugv('RAG: ├─ Results found: ' . sizeof($rows));
		return $rows;
	}

	/**
	 * @param array<string, array<string, mixed>> $mergedById
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function mergeRowsByBestDistance(array &$mergedById, array $rows): void {
		foreach ($rows as $row) {
			if (!isset($row['id']) || !is_scalar($row['id'])) {
				continue;
			}

			$rowId = (string)$row['id'];
			if (!isset($mergedById[$rowId])) {
				$mergedById[$rowId] = $row;
				continue;
			}

			$currentDist = $this->extractKnnDistance($mergedById[$rowId]);
			$newDist = $this->extractKnnDistance($row);
			if ($newDist >= $currentDist) {
				continue;
			}

			$mergedById[$rowId] = $row;
		}
	}

	/**
	 * @param string $requestedFields
	 * @param TableSchema $schema
	 *
	 * @return array<int, string>
	 */
	private function resolveSearchVectorFields(string $requestedFields, TableSchema $schema): array {
		if ($requestedFields === '') {
			return [$schema->vectorField];
		}

		$fields = array_values(
			array_filter(
				array_map('trim', explode(',', $requestedFields)),
				static fn (string $field): bool => $field !== ''
			)
		);
		if ($fields === []) {
			return [$schema->vectorField];
		}

		$selectedVectorFields = array_values(array_intersect($fields, $schema->vectorFields));
		if ($selectedVectorFields !== []) {
			return $selectedVectorFields;
		}

		return [$schema->vectorField];
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return float
	 */
	private function extractKnnDistance(array $row): float {
		if (!isset($row['knn_dist']) || !is_numeric($row['knn_dist'])) {
			return INF;
		}

		return (float)$row['knn_dist'];
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
		$normalizedSearchQuery = trim($searchQuery);
		if ($normalizedSearchQuery === '') {
			throw ManticoreSearchClientError::create('Search query must contain at least one term');
		}

		$whereClauses = [
			$this->buildKnnWhereSql($vectorField, $knnK, $normalizedSearchQuery),
			"knn_dist < $threshold",
		];
		if ($excludeClause !== '') {
			$whereClauses[] = $excludeClause;
		}

		return sprintf(
		/** @lang manticore */
			'SELECT *, knn_dist() as knn_dist FROM %s WHERE %s LIMIT %d',
			$table,
			implode(' AND ', $whereClauses),
			$limit
		);
	}

	private function buildKnnWhereSql(string $vectorField, int $knnK, string $searchQuery): string {
		$searchEscaped = $this->escapeString($searchQuery);
		return "knn($vectorField, $knnK, '$searchEscaped')";
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
