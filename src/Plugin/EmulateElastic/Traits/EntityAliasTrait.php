<?php declare(strict_types=1);

/*
  Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\Traits;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\AddEntityHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use RuntimeException;

trait EntityAliasTrait {

	use QueryMapLoaderTrait;
	use KibanaVersionTrait;

	protected static string $defaulAliasTable = '_aliases';

	/**
	 *
	 * @param string $index
	 * @param string $alias
	 * @param HTTPClient $manticoreClient
	 * @param ?string $aliasTable
	 * @return void
	 */
	protected static function addEntityAlias(
		string $index,
		string $alias,
		HTTPClient $manticoreClient,
		?string $aliasTable = null
	): void {
		if (!isset($aliasTable)) {
			$aliasTable = self::$defaulAliasTable;
		}
		$query = "CREATE TABLE IF NOT EXISTS $aliasTable (index string, alias string)";
		$manticoreClient->sendRequest($query);

		$query = "SELECT 1 FROM $aliasTable WHERE index='{$index}'";
		/** @var array{0:array{data:array<mixed>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		$isExistingIndex = (bool)$queryResult[0]['data'];
		$query = $isExistingIndex
			? "UPDATE {$aliasTable} SET alias='{$alias}' WHERE index='{$index}'"
			: "INSERT INTO $aliasTable (index, alias) VALUES('{$index}', '{$alias}')";
		/** @var array{error?:string,0:array{data?:array<mixed>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (isset($queryResult['error'])) {
			throw new \Exception('Unknown error on template creation');
		}
		if ($isExistingIndex) {
			return;
		}
		self::refreshAliasedEntities($index, $alias, $manticoreClient);
	}

	/**
	 *
	 * @param HTTPClient $manticoreClient
	 * @param array<string> $tables
	 * @return array<mixed>
	 */
	protected static function getAliasData(HTTPClient $manticoreClient, array $tables = []): array {
		$query = "SHOW TABLES LIKE '" . self::$defaulAliasTable . "'";
		/** @var array{0?:array{data?:array<mixed>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (!isset($queryResult[0])) {
			return [];
		}

		$query = 'SELECT index, alias FROM ' . self::$defaulAliasTable;
		if ($tables) {
			array_walk(
				$tables,
				fn(&$value) => $value = "'" . $value . "'"
			);
			$tablesExpr = implode(',', $tables);
			$query .= " WHERE index IN ({$tablesExpr})";
		}
		/** @var array{0:array{data?:array<array{index:string,alias:string}>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (!isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
			return [];
		}

		return $queryResult[0]['data'];
	}

	/**
	 *
	 * @param string $index
	 * @param string $alias
	 * @param HTTPClient $manticoreClient
	 * @return void
	 * @throws RuntimeException
	 */
	protected static function refreshAliasedEntities(string $index, string $alias, HTTPClient $manticoreClient): void {
		$query = 'CREATE TABLE IF NOT EXISTS ' . parent::ENTITY_TABLE
		. ' (_id string, _index string, _index_alias string, _type string, _source json)';
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (isset($queryResult['error'])) {
			throw new \Exception('Unknown error on entity table creation');
		}

		$queryMapName = ($alias === '.kibana') ? 'Settings' : 'ManagerSettings';
		self::initQueryMap($queryMapName);
		/** @var array<mixed> $entityInfo */
		$entityInfo = self::$queryMap[$queryMapName][$alias];
		/** @var array{settings:array{index:array{created:string,uuid:string}}} $sourceObj */
		$sourceObj = self::getVersionedEntity($entityInfo, $manticoreClient);

		$created = time();
		$uuid = substr(md5(microtime()), 0, 16);
		$sourceObj['settings']['index']['created'] = $created;
		$sourceObj['settings']['index']['uuid'] = $uuid;
		$source = (string)json_encode($sourceObj);
		$entityId = "settings:{$index}";
		AddEntityHandler::add($source, $entityId, 'settings', $index, $alias, $manticoreClient);

		$query = 'UPDATE ' . parent::ENTITY_TABLE . " SET _index='{$index}' WHERE _index=''";
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (isset($queryResult['error'])) {
			throw new \Exception('Unknown error on entity alias update');
		}
	}

}
