<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Ds\Set;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * Handler for SHOW SHARDING STATUS [cluster:]table
 * Renders a per-shard status table: shard, node, status, cluster, rf, rf_status.
 * When no table is given, shows all sharded tables.
 */
class ShowStatusHandler extends BaseHandlerWithClient {

	/**
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Client $client, Payload $payload): TaskResult {
			// Fetch raw rows from the sharding state table
			$where = static::buildWhere($payload->cluster, $payload->table);
			$sql = 'SELECT * FROM system.sharding_table' . ($where ? " WHERE {$where}" : '');

			/** @var array{0?:array{data?:array<array{node:string,shards:string,cluster:string,table:string}>}} $res */
			$res = $client->sendRequest($sql)->getResult();
			$rawRows = $res[0]['data'] ?? [];

			$emptyResult = TaskResult::withData([])
				->column('table', Column::String)
				->column('shard', Column::Long)
				->column('node', Column::String)
				->column('status', Column::String)
				->column('cluster', Column::String)
				->column('replication_cluster', Column::String)
				->column('rf', Column::Long)
				->column('rf_status', Column::String);

			if (!$rawRows) {
				return $emptyResult;
			}

			// Collect inactive nodes per cluster (one SHOW STATUS call per unique cluster)
			$inactiveByCluster = static::getInactiveNodesByCluster($client, $rawRows);

			// Build shard → nodes map per (cluster, table) to compute RF and health
			// key: "cluster\0table\0shard" → Set<string> of nodes
			$shardNodes = [];
			foreach ($rawRows as $row) {
				foreach (Table::parseShards($row['shards']) as $shard) {
					$key = "{$row['cluster']}\0{$row['table']}\0{$shard}";
					$shardNodes[$key] ??= new Set;
					$shardNodes[$key]->add($row['node']);
				}
			}

			$outputRows = static::buildOutputRows($rawRows, $shardNodes, $inactiveByCluster);

			return TaskResult::withData($outputRows)
				->column('table', Column::String)
				->column('shard', Column::Long)
				->column('node', Column::String)
				->column('status', Column::String)
				->column('cluster', Column::String)
				->column('replication_cluster', Column::String)
				->column('rf', Column::Long)
				->column('rf_status', Column::String);
		};

		return Task::create($taskFn, [$this->manticoreClient, $this->payload])->run();
	}

	/**
	 * Build WHERE clause filtering by cluster and/or table (both optional)
	 */
	protected static function buildWhere(string $cluster, string $table): string {
		$parts = [];
		if ($cluster !== '') {
			$parts[] = "cluster = '{$cluster}'";
		}
		if ($table !== '') {
			$parts[] = "table = '{$table}'";
		}
		return implode(' AND ', $parts);
	}

	/**
	 * For each unique cluster name in the raw rows, fetch inactive nodes via SHOW STATUS.
	 * Returns map: clusterName → Set<string> of inactive node IDs.
	 *
	 * @param Client $client
	 * @param array<array{cluster:string,...}> $rawRows
	 * @return array<string, Set<string>>
	 */
	protected static function getInactiveNodesByCluster(Client $client, array $rawRows): array {
		$clusters = array_unique(array_column($rawRows, 'cluster'));
		$result   = [];
		foreach ($clusters as $name) {
			$cluster       = new Cluster($client, $name, '');
			$result[$name] = $cluster->getInactiveNodes();
		}
		return $result;
	}

	/**
	 * Build sorted output rows from raw sharding data
	 * @param array<array{node:string,shards:string,cluster:string,table:string}> $rawRows
	 * @param array<string, Set<string>> $shardNodes
	 * @param array<string, Set<string>> $inactiveByCluster
	 * @return array<array{table:string,shard:int,node:string,status:string,cluster:string,replication_cluster:string,rf:int,rf_status:string}>
	 */
	protected static function buildOutputRows(array $rawRows, array $shardNodes, array $inactiveByCluster): array {
		$outputRows = [];
		foreach ($rawRows as $row) {
			$clusterName = $row['cluster'];
			$inactive = $inactiveByCluster[$clusterName] ?? new Set;
			$isActive = !$inactive->contains($row['node']);

			foreach (Table::parseShards($row['shards']) as $shard) {
				$key = "{$clusterName}\0{$row['table']}\0{$shard}";
				$allNodes = $shardNodes[$key] ?? new Set;
				$rf = $allNodes->count();
				$aliveCount = $allNodes->filter(fn($n) => !$inactive->contains($n))->count();

				$outputRows[] = [
					'table' => $row['table'],
					'shard' => $shard,
					'node' => $row['node'],
					'status' => $isActive ? 'active' : 'inactive',
					'cluster' => $clusterName,
					'replication_cluster' => Table::getClusterName($allNodes),
					'rf' => $rf,
					'rf_status' => match (true) {
						$aliveCount === $rf => 'ok',
						$aliveCount > 0 => 'degraded',
						default => 'broken',
					},
				];
			}
		}

		usort(
			$outputRows,
			fn($a, $b) => [$a['table'], $a['shard'], $a['node']] <=> [$b['table'], $b['shard'], $b['node']]
		);
		return $outputRows;
	}
}
