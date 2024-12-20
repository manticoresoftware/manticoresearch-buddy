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
 * This is the parent class to handle erroneous Manticore queries
 */
class AddAliasHandler extends BaseEntityHandler {

	use QueryMapLoaderTrait;

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
			/** @var array{actions:array<mixed>} */
			$request = json_decode($payload->body, true);
			if (!is_array($request)) {
				throw new \Exception('Cannot parse request');
			}
			[$index, $alias] = self::extractIndexInfo($request['actions']);

			$query = "SHOW TABLES LIKE '{$index}'";
			/** @var array{0:array{data?:array<mixed>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (!isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
				throw new \Exception("Index '{$index}' does not exist");
			}

			$query = "CREATE TABLE IF NOT EXISTS {$payload->table} (index string, alias string)";
			$manticoreClient->sendRequest($query);

			$query = "SELECT 1 FROM {$payload->table} WHERE index='{$index}'";
			/** @var array{0:array{data:array<mixed>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			$isExistingIndex = (bool)$queryResult[0]['data'];
			$query = $isExistingIndex
				? "UPDATE {$payload->table} SET alias='{$alias}' WHERE index='{$index}'"
				: "INSERT INTO {$payload->table} (index, alias) VALUES('{$index}', '{$alias}')";
			/** @var array{error?:string,0:array{data?:array<mixed>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (isset($queryResult['error'])) {
				throw new \Exception('Unknown error on template creation');
			}
			if (!$isExistingIndex) {
				self::refreshAliasedEntities($index, $alias, $manticoreClient);
			}

			return TaskResult::raw(['acknowledged' => 'true']);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 *
	 * @param array<mixed> $actions
	 * @return array{0:string,1:string}
	 * @throws RuntimeException
	 */
	protected static function extractIndexInfo(array $actions): array {
		$index = $alias = '';
		foreach ($actions as $action) {
			if (is_array($action) && array_key_exists('add', $action)) {
				extract($action['add']);
				return [$index, $alias];
			}
		}
		throw new \Exception('Unknown error on alias creation');
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
		/** @var array{settings:array{index:array{created:string,uuid:string}}} $sourceObj */
		$sourceObj = self::$queryMap[$queryMapName][$alias];
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
