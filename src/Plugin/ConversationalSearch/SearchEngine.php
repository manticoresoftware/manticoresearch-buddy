<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Lib\SqlEscapingTrait;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Tool\Buddy;

class SearchEngine {
	use SqlEscapingTrait;

	private const int DEFAULT_RETRIEVAL_LIMIT = 5;
	private const string FIELD_IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
	/** @var array<string, VectorFieldInfo> */
	private array $fieldInfoCache = [];

	public function __construct(private readonly HTTPClient $client) {
	}

	/**
	 * Get excluded document IDs for a given exclusion query
	 *
	 * @param string $table
	 * @param string $excludeQuery
	 * @param string $vectorField
	 *
	 * @return array<int, string|int>
	 * @throws ManticoreSearchResponseError|ManticoreSearchClientError
	 */
	public function getExcludedIds(
		string $table,
		string $excludeQuery,
		string $vectorField = ''
	): array {
		if (empty($excludeQuery) || $excludeQuery === 'none') {
			return [];
		}

		if ($vectorField === '') {
			$vectorField = $this->inspectVectorFieldInfo($table)->name;
		}

		return $this->findExcludedIds(
			$table,
			$excludeQuery,
			$vectorField
		);
	}

	/**
	 * @param string $table
	 * @param string $excludeQuery
	 * @param string $vectorField
	 *
	 * @return array<int, string|int>
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function findExcludedIds(
		string $table,
		string $excludeQuery,
		string $vectorField
	): array {
		if (empty($excludeQuery) || $excludeQuery === 'none') {
			return [];
		}

		$excludeEscaped = $this->escapeString($excludeQuery);
		$sql
			= /** @lang manticore */
			"SELECT id, knn_dist() as knn_dist FROM {$table}
				WHERE knn($vectorField, 15, '$excludeEscaped')
				AND knn_dist < 0.75";

		Buddy::debugv("\nChat: [DEBUG EXCLUSION QUERY]");
		Buddy::debugv("Chat: ├─ Exclude query: '$excludeQuery'");
		Buddy::debugv("Chat: ├─ Table: $table");
		Buddy::debugv("Chat: ├─ Vector field: $vectorField");
		Buddy::debugv('Chat: ├─ Threshold: 0.75');
		Buddy::debugv("Chat: ├─ Final SQL: $sql");

		$response = $this->client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Exclusion query failed: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array{id: string|int}>}> $result */
		$result = $response->getResult();
		$excludeResults = $result[0]['data'] ?? [];

		$excludedIds = array_column($excludeResults, 'id');
		Buddy::debugv('Chat: ├─ Raw results count: ' . sizeof($excludeResults));
		Buddy::debugv('Chat: └─ Excluded IDs found: [' . implode(', ', $excludedIds) . ']');

		return $excludedIds;
	}

	/**
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function inspectVectorFieldInfo(string $table, string $vectorField = ''): VectorFieldInfo {
		if ($vectorField !== '' && preg_match(self::FIELD_IDENTIFIER_PATTERN, $vectorField) !== 1) {
			throw ManticoreSearchClientError::create("Invalid vector field '$vectorField'");
		}

		$cacheKey = $vectorField === '' ? $table : "$table:$vectorField";
		if (isset($this->fieldInfoCache[$cacheKey])) {
			return $this->fieldInfoCache[$cacheKey];
		}

		$createTable = $this->getCreateTableStatement($table);
		$vectorDefinitions = $this->extractVectorFieldDefinitions($createTable);
		if ($vectorDefinitions === []) {
			throw ManticoreSearchClientError::create("Table '$table' has no FLOAT_VECTOR field");
		}

		$vectorFields = array_keys($vectorDefinitions);
		$selectedField = $vectorField !== '' ? $vectorField : $vectorFields[0];
		if (!isset($vectorDefinitions[$selectedField])) {
			throw ManticoreSearchClientError::create("FLOAT_VECTOR field '$selectedField' not found in table '$table'");
		}

		$vectorDefinition = $vectorDefinitions[$selectedField];
		if (!preg_match("/\\bfrom\\s*=\\s*'([^']+)'/i", $vectorDefinition, $matches)) {
			throw ManticoreSearchClientError::create(
				"FLOAT_VECTOR field '$selectedField' has no auto-embedding source fields"
			);
		}

		$contentFields = trim($matches[1]);
		if ($contentFields === '') {
			throw ManticoreSearchClientError::create(
				"FLOAT_VECTOR field '$selectedField' has empty auto-embedding source fields"
			);
		}

		$this->fieldInfoCache[$cacheKey] = new VectorFieldInfo($selectedField, $contentFields, $vectorFields);
		return $this->fieldInfoCache[$cacheKey];
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
	 * @param VectorFieldInfo|null $vectorFieldInfo
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
		?VectorFieldInfo $vectorFieldInfo = null
	): array {
		if ($vectorFieldInfo === null) {
			$vectorFieldInfo = $this->inspectVectorFieldInfo($table);
		}

		return $this->runVectorSearch(
			$table,
			$searchQuery,
			$excludedIds,
			$modelConfig,
			$threshold,
			$vectorFieldInfo
		);
	}

	/**
	 * @param string $table
	 * @param string $searchQuery
	 * @param array<int, string|int> $excludedIds
	 * @param array{model: string, settings:array<string, mixed>} $modelConfig
	 * @param float $threshold
	 * @param VectorFieldInfo $vectorFieldInfo
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
		VectorFieldInfo $vectorFieldInfo
	): array {
		$retrievalLimit = $this->getRetrievalLimit($modelConfig);

		$knnK = $retrievalLimit;
		$excludeClause = '';
		if (!empty($excludedIds)) {
			$safeExcludeIds = array_map('intval', $excludedIds);
			$excludeClause = 'id NOT IN (' . implode(',', $safeExcludeIds) . ')';
			$knnK += sizeof($excludedIds) + 5;
		}
		$sql = $this->buildVectorSearchSql(
			$table,
			$vectorFieldInfo->name,
			$searchQuery,
			$knnK,
			$threshold,
			$retrievalLimit,
			$excludeClause
		);

		Buddy::debugv("\nChat: [DEBUG KNN SEARCH]");
		Buddy::debugv("Chat: ├─ Search query: '$searchQuery'");
		Buddy::debugv('Chat: ├─ Excluded IDs: [' . implode(', ', $excludedIds) . ']');
		Buddy::debugv("Chat: ├─ retrieval_limit: $retrievalLimit");
		Buddy::debugv("Chat: ├─ Threshold: $threshold");
		Buddy::debugv("Chat: ├─ Final SQL: $sql");

		$response = $this->client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchResponseError::create('Vector search failed: ' . $response->getError());
		}

		/** @var array<int, array{data: array<int, array<string, mixed>>}> $responseResult */
		$responseResult = $response->getResult();
		$result = $responseResult[0]['data'] ?? [];
		Buddy::debugv('Chat: └─ Results found: ' . sizeof($result));

		return $this->stripVectorFields($result, $vectorFieldInfo->vectorFields);
	}

	/**
	 * @param array<int, array<string, mixed>> $results
	 * @param array<int, string> $vectorFields
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stripVectorFields(array $results, array $vectorFields): array {
		return array_map(
			function (array $result) use ($vectorFields): array {
				foreach ($vectorFields as $field) {
					unset($result[$field]);
				}
				return $result;
			},
			$results
		);
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

}
