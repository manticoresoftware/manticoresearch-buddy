<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Search;

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\TableSchema;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;

final class TableSchemaInspector {
	/** @var array<string, TableSchema> */
	private array $schemaCache = [];

	public function __construct(private readonly HTTPClient $client) {
	}

	/**
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function inspect(string $table): TableSchema {
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
}
