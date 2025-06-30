<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\DistributedInsert;

use Ds\Map;
use Ds\Set;
use Ds\Vector;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Table;
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
			$clusterMap = $this->getTableClusterMap(array_keys($this->payload->batch));
			foreach ($this->payload->batch as $table => $batch) {
				$shardRows = $this->processBatch($batch, $n, $positions, $table, $clusterMap);
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
	 * @param Map<string,string> $clusterMap
	 * @return array{string:array{info:array{name:string,url:string},rows:array<string>}}|array{}
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	protected function processBatch(
		Vector $batch,
		int &$n,
		array &$positions,
		string $table,
		Map $clusterMap,
	): array {
		$shards = $this->manticoreClient->getTableShards($table);
		$shardCount = sizeof($shards);

		// Group rows by shard
		$shardRows = [];
		foreach ($batch as $struct) {
			/** @var Struct<string,array{_id:string|int,_index:string}|string|int> $struct */
			if ($this->shouldAssignId($struct)) {
				$id = $this->assignId($struct);
				$idStr = (string)$id;

				$shard = jchash($idStr, $shardCount);
				$info = $shards[$shard];
				$shardName = $info['name'];
				$cluster = $clusterMap[$shardName] ?? '';
				$this->assignTable($struct, $cluster, $shardName);

				$positions[$idStr] = [
					'n' => $n++,
					'table' => $table,
					'cluster' => $cluster,
				];
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
	 * @return array{took:int,errors:bool,items:array<array<string,array{_id:string,_index:string}>>}
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
					$key = static::detectKeyWithTable($item);
					$id = $item[$key]['_id'];
					$info = $positions[$id];
					$index = $item[$key];
					$index['_index'] = $info['cluster'] ? "{$info['cluster']}:{$info['table']}" : $info['table'];
					$item[$key] = $index;
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
	 * @param Struct<string,string|int>|Struct<string,array{_id:string|int,_index:string}|string|int> $struct
	 * @return int
	 */
	protected function assignId(Struct $struct): int {
		static $idPool = [];

		$id = match (true) {
			// _bulk
			isset($struct['index']['_id']) => $struct['index']['_id'],
			isset($struct['create']['_id']) => $struct['create']['_id'],
			isset($struct['delete']['_id']) => $struct['delete']['_id'],
			isset($struct['update']['_id']) => $struct['update']['_id'],
			// insert, delete etc
			isset($struct['id']) => $struct['id'],
			// bulk
			isset($struct['insert']['id']) => $struct['insert']['id'],
			isset($struct['replace']['id']) => $struct['replace']['id'],
			isset($struct['update']['id']) => $struct['update']['id'],
			isset($struct['delete']['id']) => $struct['delete']['id'],
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
			/** @var Struct<"insert"|"replace"|"update"|"delete",array{id:string|int}> $struct */
			$key = match (true) {
				isset($struct['replace']) => 'replace',
				isset($struct['insert']) => 'insert',
				isset($struct['update']) => 'update',
				isset($struct['delete']) => 'delete',
				default => throw new \LogicException('Invalid payload type'),
			};
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
	 * @param Struct<string,string|int>|Struct<string,array{_id:string|int,_index:string}|string|int> $struct
	 * @param string $cluster
	 * @param string $shardName
	 * @return void
	 */
	protected function assignTable(Struct $struct, string $cluster, string $shardName): void {
		if ($this->payload->type === 'bulk') {
			/** @var Struct<string,array{_index:string,_id:int|string}> $struct */
			$key = static::detectKeyWithTable($struct);

			/** @var array{_id:string|int,_index:string} $index */
			$index = $struct[$key];
			if ($cluster) {
				$shardName = "{$cluster}:{$shardName}";
			}
			$index['_index'] = $shardName;
			$struct[$key] = $index;
		} elseif ($this->payload->type === 'sql') {
			/** @var Struct<"insert"|"replace"|"update"|"delete",array{table:string}> $struct */
			$key = match (true) {
				isset($struct['replace']) => 'replace',
				isset($struct['insert']) => 'insert',
				isset($struct['update']) => 'update',
				isset($struct['delete']) => 'delete',
				default => throw new \LogicException('Invalid payload type'),
			};
			$row = $struct[$key];
			/** @var array{table:string} $row */
			$row['table'] = $shardName;
			if ($cluster) {
				$row['cluster'] = $cluster;
			}
			$struct[$key] = $row;
		} else {
			/** @var Struct<string,string|int> $struct */
			$struct['table'] = $shardName;
			if ($cluster) {
				$struct['cluster'] = $cluster;
			}
		}
	}

	/**
	 * @param Struct<string,array{
	 *     _index: string,
	 *     _id: int|string
	 * }>|array<string,array{
	 *     _index: string,
	 *     _id: int|string
	 * }> $item
	 * @return string
	 */
	protected static function detectKeyWithTable(Struct|array $item): string {
		return match (true) {
			isset($item['index']['_index']) => 'index',
			isset($item['create']['_index']) => 'create',
			isset($item['delete']['_index']) => 'delete',
			isset($item['update']['_index']) => 'update',
			default => throw QueryParseError::create('Cannot detect key with table'),
		};
	}

	/**
	 * @param Struct<string,string|int>|Struct<string,array{_id:string|int,_index:string}|string|int> $struct
	 * @return bool
	 */
	protected function shouldAssignId(Struct $struct): bool {
		if ($this->payload->type === 'bulk') {
			// Elasticsearch like _bulk
			foreach (['index', 'create', 'delete', 'update'] as $key) {
				if (isset($struct[$key]['_index'])) {
					return true;
				}
			}

			// Our bulk
			foreach (['insert', 'replace', 'update', 'delete'] as $key) {
				if (isset($struct[$key]['table'])) {
					return true;
				}
			}

			return false;
		}
		return true;
	}

	/**
	 * Fetch and cache cluster for the table
	 * @param array<string> $tables
	 * @return Map<string,string>
	 */
	protected function getTableClusterMap(array $tables): Map {
		$tablesStr = "'" . implode("','", $tables) . "'";
		$query = "
		SELECT node, table, shards
		FROM system.sharding_table
		WHERE cluster != '' AND table IN ({$tablesStr})
		";

		/** @var Map<string,Set<string>> */
		$connections = new Map;
		$resp = $this->manticoreClient->sendRequest($query);
		// Any error here means we have not table or trying to right to not sharded one
		if ($resp->hasError()) {
			throw ManticoreSearchResponseError::create((string)$resp->getError())->setProxyOriginalError(true);
		}

		/** @var array{0:array{data:array<array{node:string, table:string, shards:string}>}} */
		$res = $resp->getResult();

		// Process the results to create a map of shards to nodes
		foreach ($res[0]['data'] as $row) {
			$node = $row['node'];
			$table = $row['table'];
			$shardsList = explode(',', $row['shards']);

			foreach ($shardsList as $shard) {
				$shard = (int)trim($shard);
				$shardName = Table::getTableShardName($table, $shard);
				if (!isset($connections[$shardName])) {
					$connections[$shardName] = new Set();
				}
				$connections[$shardName]?->add($node);
			}
		}
		/** @var Map<string,string> */
		$map = new Map;
		foreach ($connections as $shard => $nodes) {
			// Skip single node shards cuz no need cluster for them
			if (sizeof($nodes) <= 1) {
				continue;
			}

			$map[$shard] = Table::getClusterName($nodes);
		}

		return $map;
	}
}
