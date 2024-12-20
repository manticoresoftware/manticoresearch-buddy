<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is a class to handle Manticore queries to Kibana meta tables
 */
class ImportKibanaHandler extends BaseEntityHandler {

	const IMPORT_ENTITY_TYPES = ['visualization', 'dashboard', 'index-pattern'];
	const INDEX_ALIAS = '.kibana';

	/**
	 *  Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, HTTPClient $manticoreClient): TaskResult {
			$query = 'CREATE TABLE IF NOT EXISTS ' . parent::ENTITY_TABLE
				. ' (_id string, _index string, _index_alias string, _type string, _source json)';
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (isset($queryResult['error'])) {
				throw new \Exception('Unknown error on entity table creation');
			}
			$queries = static::parseNdJSON($payload->body);
			$imported = 0;
			foreach ($queries as $i => $query) {
				if (!($i % 2)) {
					continue;
				}
				$imported += self::importFromQuery($query, $manticoreClient);
			}
			$resp = self::buildImportResponse($imported);

			return TaskResult::raw($resp);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 *
	 * @param string $query
	 * @param HTTPClient $manticoreClient
	 * @return int
	 */
	protected static function importFromQuery(string $query, HTTPClient $manticoreClient): int {
		// Checking if the entity can be imported
		$queryEntity = (array)json_decode($query, true);
		if (!array_key_exists('type', $queryEntity) || !in_array($queryEntity['type'], self::IMPORT_ENTITY_TYPES)) {
			return 0;
		}

		$entityType = $queryEntity['type'];
		// Removing data changes made by Elastic's Save API
		if (array_key_exists('attributes', $queryEntity)) {
			$queryEntity[$entityType] = $queryEntity['attributes'];
			unset($queryEntity['attributes']);
		}

		$entityId = "{$entityType}:{$queryEntity['id']}";
		$entitySource = $query;
		// Updating index pattern ids in imported entities if possible
		if ($entityType === 'index-pattern') {
			$entityType = 'import';
			$actualPatternId = self::checkEntityPatterns($query, 'index-pattern', $manticoreClient);
			if ($actualPatternId) {
				self::updateEntityPatterns($entityId, $actualPatternId, $manticoreClient);
			}
		} else {
			$patternId = 'index-pattern:' . $queryEntity['references'][0]['id'];
			$actualPatternId = '';
			$patternSource = self::getImportedPattern($patternId, $manticoreClient);
			if ($patternSource) {
				$actualPatternId = self::checkEntityPatterns(
					$patternSource,
					'index-pattern',
					$manticoreClient
				);
			}
			if ($actualPatternId) {
				$queryEntity['references'][0]['id'] = $actualPatternId;
			}
		}
		$entitySource = (string)json_encode($queryEntity);

		// Getting Kibana index name by its alias
		$index = self::getIndexByAlias($manticoreClient);
		// Checking if the entity has already been imported before
		/** @var array{found: boolean, source: array<mixed>} $savedEntities */
		$savedEntities = GetEntityHandler::get($entityId, $index, $manticoreClient);
		if ($savedEntities['found']) {
			UpdateEntityHandler::update($entitySource, '', $entityId, $index, $manticoreClient);
		} else {
			AddEntityHandler::add($entitySource, $entityId, $entityType, $index, self::INDEX_ALIAS, $manticoreClient);
		}

		return 1;
	}

	/**
	 *
	 * @param HTTPClient $manticoreClient
	 * @return string
	 */
	protected static function getIndexByAlias(HTTPClient $manticoreClient): string {
		$kibanaIndexes = GetAliasesHandler::get(self::INDEX_ALIAS, $manticoreClient);
		if (!$kibanaIndexes) {
			return '';
		}
		$indexNames = array_keys($kibanaIndexes);
		return end($indexNames);
	}

	/**
	 * @param string $patternId
	 * @param HTTPClient $manticoreClient
	 * @return string
	 */
	protected static function getImportedPattern(string $patternId, HTTPClient $manticoreClient): string {
		$query = 'SELECT _source FROM ' . parent::ENTITY_TABLE . " WHERE _id='{$patternId}'";
		/** @var array{error?:string,0:array{data?:array<array{_source:string}>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (isset($queryResult['error']) || !isset($queryResult[0]['data'])) {
			throw new \Exception('Unknown error on Kibana index pattern retrieval');
		}

		return $queryResult[0]['data'] ? $queryResult[0]['data'][0]['_source'] : '';
	}

	/**
	 * Checking if there're actual index-patterns corresponding to imported ones
	 *
	 * @param string $patternSource
	 * @param string $indexType
	 * @return string
	 */
	public static function checkEntityPatterns(
		string $patternSource,
		string $indexType,
		HTTPClient $manticoreClient
	): string {
		/** @var array{index-pattern?:array{title:string},attributes:array{title:string}} $pattern */
		$pattern = json_decode($patternSource, true);
		$indexName = isset($pattern['index-pattern'])
			? $pattern['index-pattern']['title'] : $pattern['attributes']['title'];
		$query = 'SELECT _id, _source FROM ' . parent::ENTITY_TABLE . " WHERE _type='{$indexType}'";
		/** @var array{error?:string,0:array{data?:array<array{_source:string,_id:string}>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (isset($queryResult['error']) || !isset($queryResult[0]['data'])) {
			throw new \Exception('Unknown error on Kibana entity retrieval');
		}
		foreach ($queryResult[0]['data'] as $patternInfo) {
			/** @var array{index-pattern?:array{title:string},attributes:array{title:string}} $savedPattern */
			$savedPattern = json_decode($patternInfo['_source'], true);
			$savedIndexName = isset($savedPattern['index-pattern'])
				? $savedPattern['index-pattern']['title'] : $savedPattern['attributes']['title'];
			if ($savedIndexName === $indexName) {
				return $patternInfo['_id'];
			}
		}

		return '';
	}

	/**
	 * Replacing imported index pattern ids with actual ones
	 *
	 * @param string $importedId
	 * @param string $actualId
	 * @param HTTPClient $manticoreClient
	 * @return void
	 */
	public static function updateEntityPatterns(
		string $importedId,
		string $actualId,
		HTTPClient $manticoreClient
	): void {
		// Removing `type` parts from ids since they're not used in respective _source fields
		$importedId = explode(':', $importedId)[1];
		$actualId = explode(':', $actualId)[1];

		$query = 'SELECT _id, _source FROM ' . parent::ENTITY_TABLE	. " WHERE _type='visualization'";
		/** @var array{error?:string,0:array{data?:array<array{_source:string,_id:string}>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (isset($queryResult['error']) || !isset($queryResult[0]['data'])) {
			throw new \Exception('Unknown error on Kibana imorted entity retrieval');
		}
		foreach ($queryResult[0]['data'] as $entityInfo) {
			/** @var array{references:array{0:array{id:string}}} $entitySource */
			$entitySource = json_decode($entityInfo['_source'], true);
			if ($entitySource['references'][0]['id'] !== $importedId) {
				return;
			}
			$entitySource['references'][0]['id'] = $actualId;
			$entitySource = str_replace('\\"', '\\\\"', (string)json_encode($entitySource));
			$query = 'UPDATE ' . parent::ENTITY_TABLE
				. " SET _source='{$entitySource}' WHERE _id='{$entityInfo['_id']}'";
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (isset($queryResult['error'])) {
				throw new \Exception('Unknown error on Kibana imported entity update');
			}
		}
	}

	/**
	 *
	 * @param int $imported
	 * @return array{errors:boolean,items:array<mixed>}
	 */
	protected static function buildImportResponse(int $imported): array {
		$respItems = [];
		for ($i = 0; $i < $imported; $i++) {
			$respItems[] = [
				'index' => [
					'_index' => 'kibana',
					'_primary_term' => 1,
					'_seq_no' => 0,
					'_shards' => [
						'failed' => 0,
						'successful' => 1,
						'total' => 1,
					],
					'_type' => 'doc',
					'_version' => 1,
					'result' => 'created',
					'status' => 201,
				],
			];
		}
		return [
			'errors' => false,
			'items' => $respItems,
		];
	}

	/**
	 * @param string $query
	 * @return Iterable<string>
	 */
	protected static function parseNdJSON($query): Iterable {
		do {
			$eolPos = strpos($query, PHP_EOL);
			if ($eolPos === false) {
				$eolPos = strlen($query);
			}
			$row = substr($query, 0, $eolPos);
			if ($row !== '') {
				yield $row;
			}
			$query = substr($query, $eolPos + 1);
		} while (strlen($query) > 0);
	}
}
