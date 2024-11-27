<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\DistributedInsert;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithFlagCache;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/** @package Manticoresearch\Buddy\Base\Plugin\DistributedInsert */
final class Handler extends BaseHandlerWithFlagCache {

	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

  /**
	 * Process the request
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = function (): TaskResult {
			$requests = [];
			$positions = [];
			$n = 0;
			foreach ($this->payload->batch as $table => $batch) {
				[$cluster, $table] = $this->payload::parseCluster($table);
				$shards = $this->getShards($table);
				$shardCount = sizeof($shards);
				$idPool = $this->getNewDocIds(sizeof($batch));
				$rows = [];
				foreach ($batch as $struct) {
					/** @var Struct<string,string|int>|Struct<"index",array{_id:string|int,_index:string}|string|int> $struct */
					// TODO: we have issue here we need id but at this moment
					// we do not know exact shard to use for it
					$id = (string)($struct['index']['_id'] ?? $struct['id'] ?? '');
					if ($id === '%id%') {
						$id = (string)array_pop($idPool);
					}
					$positions[$id] = [
						'n' => $n++,
						'table' => $table,
						'cluster' => $cluster,
					];
					$shard = hexdec(substr(md5($id), 0, 8)) % $shardCount;
					$info = $shards[$shard];
					$shardName = $info['name'];
					$json = $struct->toJson();
					$row = strtr(
						$json, [
						'%id%' => $id,
						'%table%' => $cluster ? "$cluster:$shardName" : $shardName,
						]
					);

					$rows[] = $row;
				}

				$requests[] = [
					'url' => $info['url'],
					'path' => $this->payload->path,
					'request' => implode(PHP_EOL, $rows) . PHP_EOL,
				];
			}

			return $this->processRequests($requests, $positions);
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * Generate new document ids for document by using manticore query
	 * @return array<int>
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	protected function getNewDocIds(int $count = 1): array {
		$ids = [];
		$requests = array_fill(0, $count, ['url' => '', 'request' => 'SELECT uuid_short()']);
		$responses = $this->manticoreClient->sendMultiRequest($requests);
		foreach ($responses as $response) {
			/** @var array{0:array{data:array<array{"uuid_short()":string}>}} */
			$res = $response->getResult()->toArray();
			$id = (int)$res[0]['data'][0]['uuid_short()'];
			if (!$id) {
				throw new ManticoreSearchResponseError('Failed to get new document id');
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Get all shards for current distributed table from the schema
	 * @param string $table
	 * @return array<array{name:string,url:string}>
	 * @throws RuntimeException
	 */
	protected function getShards(string $table): array {
		static $map = [];
		if (isset($map[$table])) {
			return $map[$table];
		}
		[$locals, $agents] = $this->parseShards($table);

		$shards = [];
		// Add locals first
		foreach ($locals as $t) {
			$shards[] = [
				'name' => $t,
				'url' => '',
			];
		}
		// Add agents after
		foreach ($agents as $agent) {
			$ex = explode('|', $agent);
			$host = strtok($ex[0], ':');
			$port = (int)strtok(':');
			$t = strtok(':');
			$shards[] = [
				'name' => (string)$t,
				'url' => "$host:$port",
			];
		}
		$map[$table] = $shards;
		return $shards;
	}

	/**
	 * Helper to parse shards and return local and remote agents for current table
	 * @param string $table
	 * @return array{0:array<string>,1:array<string>}
	 */
	protected function parseShards($table): array {
		/** @var array{0:array{data:array<array{"Create Table":string}>}} */
		$res = $this->manticoreClient->sendRequest("SHOW CREATE TABLE $table")->getResult();
		$tableSchema = $res[0]['data'][0]['Create Table'] ?? '';
		if (!$tableSchema) {
			throw new RuntimeException("There is no such table: {$table}");
		}
		if (!str_contains($tableSchema, "type='distributed'")) {
			throw new RuntimeException('The table is not distributed');
		}

		if (!preg_match_all("/local='(?P<local>[^']+)'|agent='(?P<agent>[^']+)'/ius", $tableSchema, $m)) {
			throw new RuntimeException('Failed to match tables from the schema');
		}
		return [
			array_filter($m['local']),
			array_filter($m['agent']),
		];
	}

	/**
	 * @param array<array{url:string,path:string,request:string}> $requests
	 * @param array<string,array{n:int,table:string,cluster:string}> $positions
	 * @return TaskResult
	 * @throws ManticoreSearchClientError
	 */
	protected function processRequests(array $requests, array $positions): TaskResult {
		$result = match ($this->payload->type) {
			'bulk' => $this->processBulk($requests, $positions),
			default => $this->processSingle($requests, $positions),
		};

		return TaskResult::raw($result);
	}

	/**
	 * @param array<array{url:string,path:string,request:string}> $requests
	 * @param array<string,array{n:int,table:string,cluster:string}> $positions
	 * @return array{took:int,errors:bool,items:array<array{index:array{_id:string,_index:string}}>}
	 * @throws ManticoreSearchClientError
	 */
	protected function processBulk(array $requests, array $positions): array {
		$start = hrtime(true);
		$responses = $this->manticoreClient->sendMultiRequest($requests);
		$items = [];
		$errors = false;
		foreach ($responses as $n => $response) {
			/** @var array{errors:bool,items:array<array{index:array{_id:string,_index:string}}>} */
			$current = $response->getResult()->toArray();
			$errors = $errors || $current['errors'];
			foreach ($current['items'] as &$item) {
				$id = $item['index']['_id'];
				$info = $positions[$id];
				$index = $item['index'];
				$index['_index'] = $info['cluster'] ? "{$info['cluster']}:{$info['table']}" : $info['table'];
				$item['index'] = $index;
				$n = $info['n'];
				$items[$n] = $item;
			}
		}
		$took = hrtime(true) - $start;
		ksort($items);
		return [
			'took' => (int)($took / 1e6),
			'errors' => $errors,
			'items' => array_values($items),
		];
	}

	/**
	 * Process simple single request like insert or whatever
	 * @param array<array{url:string,path:string,request:string}> $requests
	 * @param array<string,array{n:int,table:string,cluster:string}> $positions
	 * @return array<mixed>
	 * @throws ManticoreSearchClientError
	 */
	protected function processSingle(array $requests, array $positions): array {
		$responses = $this->manticoreClient->sendMultiRequest($requests);
		/** @var array{_id:string,_index:string}|array{error:array{index:string},index:string} */
		$result = $responses[0]->getResult()->toArray();
		/** @var array{n:int,table:string,cluster:string} $info */
		$info = array_pop($positions);
		$table = $info['cluster'] ? "{$info['cluster']}:{$info['table']}" : $info['table'];
		if (isset($result['error'])) {
			$result['error']['index'] = $table;
		} elseif (isset($result['_id'])) {
			$result['_index'] = $table;
		} else {
			throw new ManticoreSearchClientError('Do not know how to handle response');
		}
		return $result;
	}
}
