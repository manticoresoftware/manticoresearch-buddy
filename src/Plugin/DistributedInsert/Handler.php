<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\DistributedInsert;

use Ds\Vector;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
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
				$shardRows = $this->processBatch($batch, $n, $positions, $table);
				if (!$shardRows) {
					throw new ManticoreSearchClientError('Failed to prepare docs for insertion');
				}

				// Create requests for each shard
				foreach ($shardRows as $shardData) {
					$requests[] = $this->getRequest($shardData['info'], $shardData['rows']);
				}
			}

			return $this->processRequests($requests, $positions);
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * @param Vector<Struct<int|string,mixed>> $batch
	 * @param int &$n
	 * @param array<string,array{n:int,table:string,cluster:string}> &$positions
	 * @param string $table
	 * @return array{string:array{info:array{name:string,url:string},rows:array<string>}}|array{}
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	protected function processBatch(
		Vector $batch,
		int &$n,
		array &$positions,
		string $table,
	): array {
		[$cluster, $table] = $this->payload::parseCluster($table);
		$shards = $this->getShards($table);
		$shardCount = sizeof($shards);

		// Group rows by shard
		$shardRows = [];
		foreach ($batch as $struct) {
			/** @var Struct<string,string|int>|Struct<"index",array{_id:string|int,_index:string}|string|int> $struct */
			if ($this->shouldAssignId($struct)) {
				$id = $this->assignId($struct);
				$idStr = (string)$id;
				$positions[$idStr] = [
					'n' => $n++,
					'table' => $table,
					'cluster' => $cluster,
				];

				$shard = jchash($idStr, $shardCount);
				$info = $shards[$shard];
				$shardName = $info['name'];
				$this->assignTable($struct, $cluster, $shardName);
			}

			if (!isset($shardName)) {
				throw QueryParseError::create('Cannot find shard for table');
			}

			if (!isset($shardRows[$shardName]) && isset($info)) {
				$shardRows[$shardName] = [
					'info' => $info,
					'rows' => [],
				];
				unset($info);
			}

			$shardRows[$shardName]['rows'][] = $struct->toJson();
		}

		return $shardRows;
	}

	/**
	 * @param array{name:string,url:string} $info
	 * @param array<string> $rows
	 * @return array{url:string,path:string,request:string}
	 */
	protected function getRequest(array $info, array $rows): array {
		return [
			'url' => $info['url'],
			// We use for sql and default for others
			'path' => $this->payload->type === 'sql' ? 'bulk' : $this->payload->path,
			'request' => implode(PHP_EOL, $rows) . PHP_EOL,
		];
	}

	/**
	 * Generate new document ids for document by using manticore query
	 * @return array<int>
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	protected function getNewDocIds(int $count = 1): array {
		$ids = [];
		/** @var array{0:array{data:array<array{"uuid_short()":int}>}} */
		$result = $this->manticoreClient->sendRequest("CALL UUID_SHORT($count)")->getResult();
		foreach ($result[0]['data'] as $row) {
			$ids[] = $row['uuid_short()'];
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
		$res = $this->manticoreClient->sendRequest("SHOW CREATE TABLE $table OPTION force=1")->getResult();
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
	 * @param array<string|int,array{n:int,table:string,cluster:string}> $positions
	 * @return TaskResult
	 * @throws ManticoreSearchClientError
	 */
	protected function processRequests(array $requests, array $positions): TaskResult {
		$result = match ($this->payload->type) {
			'bulk' => $this->processBulk($requests, $positions),
			'sql' => $this->processSql($requests, $positions),
			default => $this->processSingle($requests, $positions),
		};

		return TaskResult::raw($result);
	}

	/**
	 * @param array<array{url:string,path:string,request:string}> $requests
	 * @param array<string|int,array{n:int,table:string,cluster:string}> $positions
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
				if ($this->payload->type === 'bulk') {
					$id = $item['index']['_id'];
					$info = $positions[$id];
					$index = $item['index'];
					$index['_index'] = $info['cluster'] ? "{$info['cluster']}:{$info['table']}" : $info['table'];
					$item['index'] = $index;
					$n = $info['n'];
					$items[$n] = $item;
				} else {
					/* $id = $item['insert']['id']; */
					/* $info = $positions[$id]; */
					/* $doc = $item['insert']; */
					/* $doc['table'] = $info['cluster'] ? "{$info['cluster']}:{$info['table']}" : $info['table']; */
					/* $item['insert'] = $doc; */
					/* $n = $info['n']; */
					/* $items[$n] = $item; */
				}
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
	 * @param array<string|int,array{n:int,table:string,cluster:string}> $positions
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
		} elseif ($this->payload->type === 'bulk') {
			$result['_index'] = $table;
		} else {
			$result['table'] = $table;
		}
		return $result;
	}

	/**
	 * @param array<array{url:string,path:string,request:string}> $requests
	 * @param array<string|int,array{n:int,table:string,cluster:string}> $positions
	 * @return array<mixed>
	 * @throws ManticoreSearchClientError
	 */
	protected function processSql(array $requests, array $positions): array {
		$result = $this->processBulk($requests, $positions);
		if ($result['errors'] && $this->payload->type === 'sql') {
			throw ManticoreSearchClientError::create('Failed to insert docs');
		}

		/** @var array<mixed> */
		return TaskResult::none()->getStruct();
	}

	/**
	 * @param Struct<string,string|int>|Struct<"index",array{_id:string|int,_index:string}|string|int> $struct
	 * @return int
	 */
	protected function assignId(Struct $struct): int {
		static $idPool = [];

		$id = match (true) {
			// _bulk
			isset($struct['index']['_id']) => $struct['index']['_id'],
			// insert, delete etc
			isset($struct['id']) => $struct['id'],
			// bulk
			isset($struct['insert']['id']) => $struct['insert']['id'],
			isset($struct['replace']['id']) => $struct['replace']['id'],
			default => array_pop($idPool),
		};
		// When id = 0 we generate
		if (!$id) {
			// When we have no pool we fetch it by batches for performance
			if (!$idPool) {
				$idPool = $this->getNewDocIds(1000);
			}

			$id = array_pop($idPool);
		}
		if (!$id) {
			throw new ManticoreSearchClientError('Failed to assign id');
		}
		if ($this->payload->type === 'bulk') {
			/** @var Struct<"index",array{_id:string|int,_index:string}> $struct */
			/** @var array{_id:string|int,_index:string} $index */
			$index = $struct['index'];
			$index['_id'] = "$id";
			$struct['index'] = $index;
		} elseif ($this->payload->type === 'sql') {
			/** @var Struct<"insert"|"replace",array{id:string|int}> $struct */
			$key = isset($struct['replace']) ? 'replace' : 'insert';
			$row = $struct[$key];
			$row['id'] = (int)$id;
			$struct[$key] = $row;
		} else {
			/** @var Struct<"id",string|int> $struct */
			$struct['id'] = $id;
		}
		return (int)$id;
	}

	/**
	 * @param Struct<string,string|int>|Struct<"index",array{_id:string|int,_index:string}|string|int> $struct
	 * @param string $cluster
	 * @param string $shardName
	 * @return void
	 */
	protected function assignTable(Struct $struct, string $cluster, string $shardName): void {
		$table = $cluster ? "$cluster:$shardName" : $shardName;
		if ($this->payload->type === 'bulk') {
			/** @var Struct<"index",array{_id:string|int,_index:string}> $struct */
			/** @var array{_id:string|int,_index:string} $index */
			$index = $struct['index'];
			$index['_index'] = $table;
			$struct['index'] = $index;
		} elseif ($this->payload->type === 'sql') {
			/** @var Struct<"insert"|"replace",array{table:string}> $struct */
			$key = isset($struct['replace']) ? 'replace' : 'insert';
			$row = $struct[$key];
			/** @var array{table:string} $row */
			$row['table'] = $table;
			$struct[$key] = $row;
		} else {
			/** @var Struct<string,string|int> $struct */
			$struct['table'] = $table;
		}
	}

	/**
	 * @param Struct<string,string|int>|Struct<"index",array{_id:string|int,_index:string}|string|int> $struct
	 * @return bool
	 */
	protected function shouldAssignId(Struct $struct): bool {
		if ($this->payload->type === 'bulk' && !isset($struct['index'])) {
			return false;
		}
		return true;
	}
}
