<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Ds\Map;
use Ds\Set;
use Ds\Vector;
use Exception;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use RuntimeException;

/** @package Manticoresearch\Buddy\Base\Plugin\Sharding */
final class Table {
	public readonly string $table;

	/**
	 * Initialize with a given client
	 * @param Client $client
	 * @param string $name
	 * @return void
	 */
	public function __construct(
		protected Client $client,
		protected Cluster $cluster,
		public readonly string $name,
		protected readonly string $structure,
		protected readonly string $extra
	) {
		$this->table = 'system.sharding_table';
	}

	/**
	 * Get current configuration of nodes and their shards
	 * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
	 */
	public function getShardSchema(): Vector {
		$nodes = new Vector;
		/** @var array<array{data:array<array{node:string,shards:string}>}> $res */
		$res = $this->client
			->sendRequest(
				"
				SELECT *
				FROM {$this->table}
				WHERE
				cluster = '{$this->cluster->name}'
				AND
				table = '{$this->name}'
				"
			)
			->getResult();

		// Initialize an empty array to hold connections
		$connections = [];
		foreach ($res[0]['data'] as $row) {
			/** @var array{node:string,shards:string} $row*/
			$shards = static::parseShards($row['shards']);
			foreach ($shards as $shard) {
				$connections[$shard][] = $row['node'];
			}
		}

		foreach ($res[0]['data'] as $row) {
			$shards = static::parseShards($row['shards']);
			/** @var Set<string> */
			$connectedNodes = new Set;
			foreach ($shards as $shard) {
				$connectedNodes->add(...$connections[$shard]);
			}
			/** @var array{node:string,shards:string} $row */
			$nodes->push(
				new Map(
					[
						'node' => $row['node'],
						'shards' => $shards,
						'connections' => $connectedNodes,
					]
				)
			);
		}
		return $nodes;
	}

	/**
	 * Get all nodes connected for specified shards
	 * @param  Set<int> $shards
	 * @return Set<string>
	 */
	public function getConnectedNodes(Set $shards): Set {
		$query = "
		SELECT node FROM {$this->table}
		WHERE
		cluster = '{$this->cluster->name}'
		AND
		table = '{$this->name}'
		AND
		shards in ({$shards->join(',')})
		";

		$connections = new Set;
		/** @var array{0:array{data:array<array{node:string}>}} */
		$res = $this->client->sendRequest($query)->getResult();
		foreach ($res[0]['data'] as $row) {
			$connections->add($row['node']);
		}

		return $connections;
	}

	/**
	 * Get external shards for the node ID
	 * @param Set<int> $shards
	 * @return Vector<array{node:string,shards:Set<int>}>
	 */
	public function getExternalNodeShards(Set $shards): Vector {
		$query = "
		SELECT node, shards FROM {$this->table}
		WHERE
		cluster = '{$this->cluster->name}'
		AND
		table = '{$this->name}'
		AND
		ANY(shards) not in ({$shards->join(',')})
		ORDER BY id ASC
		";

		$nodes = new Vector;
		/** @var array{0:array{data:array<array{node:string,shards:string}>}} */
		$res = $this->client->sendRequest($query)->getResult();
		foreach ($res[0]['data'] as $row) {
			$nodes->push(
				new Map(
					[
						'node' => $row['node'],
						'shards' => static::parseShards($row['shards']),
					]
				)
			);
		}

		return $nodes;
	}

	/**
	 * Internal helper that creates sharding map
	 * @param int $shardCount
	 * @param int $replicationFactor
	 * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
	 */
	protected function configureNodeShards(
		int $shardCount,
		int $replicationFactor = 2
	): Vector {
		// TODO: here we need to create custom cluster later
		$nodes = $this->cluster->getNodes();

		// First create the sharding scheme for table and system table
		$scheme = Util::createShardingSchema($nodes, $shardCount, $replicationFactor);
		$this->updateScheme($scheme);

		// Return prepared scheme before
		return $scheme;
	}

	/**
	 * Create sharding map for this table
	 * @param Queue $queue
	 * @param int $shardCount
	 * @param int $replicationFactor
	 * @return Map<string,mixed>
	 */
	public function shard(
		Queue $queue,
		int $shardCount,
		int $replicationFactor = 2
	): Map {
		/** @var Map<string,mixed> */
		$result = new Map(
			[
				'status' => 'processing',
				'type' => 'create',
				'result' => null,
				'structure' => $this->structure,
				'extra' => $this->extra,
			]
		);

		/** @var Map<string,Set<int>> */
		$nodeShardsMap = new Map;

		/** @var Set<string> */
		$nodes = new Set;

		$schema = $this->configureNodeShards($shardCount, $replicationFactor);
		$reduceFn = function (Map $clusterMap, array $row) use ($queue, $replicationFactor, &$nodes, &$nodeShardsMap) {
			/** @var Map<string,Cluster> $clusterMap */
			$nodes->add($row['node']);
			$nodeShardsMap[$row['node']] = $row['shards'];

			foreach ($row['shards'] as $shard) {
				$connectedNodes = $this->getConnectedNodes(new Set([$shard]));

				if ($replicationFactor > 1) {
					$clusterMap = $this->handleReplication(
						$row['node'],
						$queue,
						$connectedNodes,
						$clusterMap,
						$shard
					);
				} else {
					// If no replication, add create table shard SQL to queue
					$sql = $this->getCreateTableShardSQL($shard);
					$queue->add($row['node'], $sql);
				}
			}

			return $clusterMap;
		};
		$clusterMap = $schema->reduce($reduceFn, new Map);

		// Now process all postponed pending tables to attach to each cluster
		foreach ($clusterMap as $cluster) {
			$cluster->processPendingTables($queue);
		}

		/** @var Set<int> */
		$queueIds = new Set;
		foreach ($nodeShardsMap as $node => $shards) {
			// Do nothing when no shards present for this node
			if (!$shards->count()) {
				continue;
			}
			$sql = $this->getCreateShardedTableSQL($shards);
			$queueId = $queue->add($node, $sql);
			$queueIds->add($queueId);
		}

		$result['nodes'] = $nodes;
		$result['queue_ids'] = $queueIds;
		return $result;
	}

	/**
	 * Drop the whole sharded table
	 * @param Queue $queue
	 * @return Map<string,mixed>
	 */
	public function drop(Queue $queue): Map {
		/** @var Map<string,mixed> */
		$result = new Map(
			[
				'status' => 'processing',
				'type' => 'drop',
				'result' => null,
				'structure' => $this->structure,
				'extra' => $this->extra,
			]
		);

		/** @var Set<string> */
		$nodes = new Set;

		/** @var Set<int> */
		$queueIds = new Set;

		/** @var Set<string> */
		$processedTables = new Set;

		// Get the current shard schema
		$schema = $this->getShardSchema();

		// Iterate through all nodes and their shards
		foreach ($schema as $row) {
			$nodes->add($row['node']);
			$ids = $this->cleanUpNode($queue, $row['node'], $row['shards'], $processedTables);
			$queueIds->add(...$ids);
		}

		// Remove the sharding configuration from the system table
		$systemTable = $this->cluster->getSystemTableName($this->table);
		$this->client->sendRequest(
			"
			DELETE FROM {$systemTable}
			WHERE
			cluster = '{$this->cluster->name}'
			AND
			table = '{$this->name}'
			"
		);

		$result['nodes'] = $nodes;
		$result['queue_ids'] = $queueIds;
		return $result;
	}
	/**
	 * Handle replication for the table and shard
	 * @param  string $node
	 * @param  Queue  $queue
	 * @param  Set<string>    $connectedNodes
	 * @param  Map<string,Cluster>   $clusterMap
	 * @param  int    $shard
	 * @return Map<string,Cluster> The cluster map that we use
	 *  for session to maintain which nodes are connected
	 *  and which cluster are processed already and who is the owner
	 */
	protected function handleReplication(
		string $node,
		Queue $queue,
		Set $connectedNodes,
		Map $clusterMap,
		int $shard
	): Map {
		$clusterName = static::getClusterName($connectedNodes);
		$hasCluster = isset($clusterMap[$clusterName]);
		if ($hasCluster) {
			$cluster = $clusterMap[$clusterName];
		} else {
			$cluster = new Cluster($this->client, $clusterName, $node);
			$nodesToJoin = $connectedNodes->filter(
				fn ($connectedNode) => $connectedNode !== $node
			);
			$clusterMap[$clusterName] = $cluster;
			$waitForId = $cluster->create($queue);
			$queue->setWaitForId($waitForId);
			$cluster->addNodeIds($queue, ...$nodesToJoin);
			$queue->resetWaitForId();
		}

		/** @var Cluster $cluster */
		$table = $this->getShardName($shard);
		if (!$cluster->hasPendingTable($table, TableOperation::Attach)) {
			$cluster->addPendingTable($table, TableOperation::Attach);
			$sql = $this->getCreateTableShardSQL($shard);
			$queue->add($node, $sql);
		}
		return $clusterMap;
	}

	/**
	 * Rebalances the shards,
	 * identifying affected shards from inactive nodes
	 * and moving only them.
	 *
	 * @param Queue $queue
	 * @return void
	 */
	// @phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh
	public function rebalance(Queue $queue): void {
		try {
			/** @var Map<string,Cluster> */
			$clusterMap = new Map;

			$schema = $this->getShardSchema();
			$allNodes = $this->cluster->getNodes();
			$inactiveNodes = $this->cluster->getInactiveNodes();
			if (!$inactiveNodes->count()) {
				return;
			}
			$activeNodes = $allNodes->diff($inactiveNodes);
			$newSchema = Util::rebalanceShardingScheme($schema, $activeNodes);

			// Detect shard to nodes map with alive schema
			$shardNodesMap = $this->getShardNodesMap(
				$schema->filter(
					fn ($row) => !$inactiveNodes->contains($row['node'])
				)
			);

			// Preload current cluster map with configuration
			foreach ($shardNodesMap as $shard => $connections) {
				$clusterName = static::getClusterName($connections);
				$connections->sort();
				$node = $connections->first();
				$cluster = new Cluster($this->client, $clusterName, $node);
				$clusterMap[$clusterName] = $cluster;
			}

			$affectedSchema = $schema->filter(
				fn ($row) => $inactiveNodes->contains($row['node'])
			);

			/** @var Set<string> */
			$processedTables = new Set;

			foreach ($affectedSchema as $row) {
				// First thing first, remove from inactive node using the queue
				$this->cleanUpNode($queue, $row['node'], $row['shards'], $processedTables);

				// Do real rebaliance now
				foreach ($row['shards'] as $shard) {
					/** @var Set<string> */
					$nodesForShard = new Set;

					foreach ($newSchema as $newRow) {
						// The case when this shards should not be here
						if (!$newRow['shards']->contains($shard)) {
							continue;
						}
						$nodesForShard->add($newRow['node']);
					}

					// This is exception, actually
					if (!$nodesForShard->count()) {
						continue;
					}

					// It's very important here to start replication
					// From the live node first due to
					// cluster should be created there first
					// We use previously generated shard to nodes map
					/** @var Set<string> */
					$shardNodes = $shardNodesMap[$shard];

					// If this happens, we have no alive shard, this is critical, but do nothing for now
					if (!$shardNodes->count()) {
						continue;
					}
					$shardNodes->sort();
					$aliveNode = $shardNodes->first();
					/** @var Set<string> */
					$connectedNodes = $nodesForShard->merge($shardNodes);
					// $connectedNodes->sort(
					// 	fn ($a, $b) => $a === $aliveNode ? -1 : 1,
					// );

					// Reconfigure on alive shard
					// Cuz it's the main shard where we have data already
					$clusterMap = $this->handleReplication(
						$aliveNode,
						$queue,
						$connectedNodes,
						$clusterMap,
						$shard
					);
				}
			}

			/** @var Set<int> */
			$queueIds = new Set;
			foreach ($newSchema as $row) {
				$sql = "DROP TABLE {$this->name} OPTION force=1";
				$queueId = $queue->add($row['node'], $sql);
				$queueIds->add($queueId);
				// Do nothing when no shards present for this node
				if (!$row['shards']->count()) {
					continue;
				}
				$sql = $this->getCreateShardedTableSQL($row['shards']);
				$queueId = $queue->add($row['node'], $sql);
				$queueIds->add($queueId);
			}

			$this->updateScheme($newSchema);
		} catch (\Throwable $t) {
			var_dump($t->getMessage());
		}
	}

	/**
	 * This method cleans up the node with their shards and destroy it
	 * @param Queue $queue
	 * @param  string $nodeId
	 * @param  Set<int>    $shards
	 * @param  Set<string> $processedTables we used processed tables cuz we cannot unable delete cluster
	 *  due to this method also used in rebalancing, so we leave cluster created between nodes cuz it should not
	 *  be a huge deal due to we maintain interconnection between nodes that is probably will be useful
	 *  in cluster environment and anyway will be rercreated for another sharded table or whatever
	 * @return Set<int>
	 */
	public function cleanUpNode(Queue $queue, string $nodeId, Set $shards, Set $processedTables): Set {
		/** @var Set<int> */
		$queueIds = new Set;
		$queueIds[] = $queue->add($nodeId, "DROP TABLE IF EXISTS {$this->name} OPTION force=1");

		foreach ($shards as $shard) {
			$connections = $this->getConnectedNodes(new Set([$shard]));
			$clusterName = static::getClusterName($connections);
			$table = $this->getShardName($shard);

			if (sizeof($connections) > 1) {
				$this->handleClusteredCleanUp(
					$queue,
					$nodeId,
					$connections,
					$clusterName,
					$table,
					$queueIds,
					$processedTables
				);
			} else {
				$this->handleSingleNodeCleanUp($queue, $nodeId, $table, $queueIds);
			}
		}

		return $queueIds;
	}

	/**
	 * @param Queue $queue
	 * @param string $nodeId
	 * @param Set<string> $connections
	 * @param string $clusterName
	 * @param string $table
	 * @param Set<int> $queueIds
	 * @param Set<string> $processedTables
	 * @return void
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 * @throws Exception
	 * @return void
	 */
	private function handleClusteredCleanUp(
		Queue $queue,
		string $nodeId,
		Set $connections,
		string $clusterName,
		string $table,
		Set $queueIds,
		Set $processedTables
	): void {
		if (!$processedTables->contains($table)) {
			foreach ($connections as $connectedNode) {
				if ($connectedNode === $nodeId) {
					continue;
				}
				$cluster = new Cluster(
					$this->client,
					$clusterName,
					$connectedNode
				);
				$queueIds[] = $cluster->makePrimary($queue);
				$queueIds[] = $cluster->removeTables($queue, $table);
				$processedTables->add($table);
			}
		}

		$queueIds[] = $queue->add($nodeId, "DROP TABLE IF EXISTS {$table}");
	}

	/**
	 * @param Queue $queue
	 * @param string $nodeId
	 * @param string $table
	 * @param Set<int> &$queueIds
	 * @return void
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	private function handleSingleNodeCleanUp(Queue $queue, string $nodeId, string $table, Set &$queueIds): void {
		$queueIds[] = $queue->add($nodeId, "DROP TABLE IF EXISTS {$table}");
	}
	/**
	 * Convert schema to map where each shard has nodes
	 * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @return Map<int,Set<string>>
	 */
	protected function getShardNodesMap(Vector $schema): Map {
		return $schema->reduce(
			/** @var Map<int,Set<string>> $map */
			function (Map $map, $row): Map {
				foreach ($row['shards'] as $shard) {
					/** @var Set<string> $set */
					$set = $map->get($shard, new Set);
					$set->add($row['node']);
					$map->put($shard, $set);
				}
				return $map;
			}, new Map
		);
	}

	/**
	 * Get available node for rebalancing
	 * @param  Map<string,Set<int>>    $activeShardsMap
	 * @param  int    $shard
	 * @param int $count How many nodes we need to pull and equals to lost replicas
	 * @return Set<string>
	 */
	protected function getAvailableNodes(
		Map $activeShardsMap,
		int $shard,
		int $count = 1
	): Set {
		$nodes = new Set;
		foreach ($activeShardsMap as $node => $shards) {
			if ($shards->contains($shard)) {
				continue;
			}
			$nodes->add($node);

			if ($nodes->count() === $count) {
				break;
			}
		}

		return $nodes;
	}

	/**
	 * Get the unique key for the cluster based on the connections
	 * @param Set<string> $connections
	 * @return string
	 */
	public static function getClusterName(Set $connections): string {
		$hash = md5($connections->sorted()->join(','));
		if (is_numeric($hash[0])) {
			$hash[0] = chr(97 + ($hash[0] % 6));
		}
		return $hash;
	}

	/**
	 * Helper to get create table SQL for single shard
	 * @param int $shard
	 * @return string
	 */
	protected function getCreateTableShardSQL(int $shard): string {
		$structure = $this->structure ? "({$this->structure})" : '';
		// We can call this method on rebalancing, that means
		// table may exist, so to suppress error we use
		// if not exists to keep logic simpler
		return "CREATE TABLE IF NOT EXISTS {$this->getShardName($shard)} {$structure} {$this->extra}";
	}

	/**
	 * We use it outside in distributed insert logic
	 * @param  int    $shard
	 * @return string
	 */
	public static function getTableShardName(string $table, int $shard): string {
		return "system.{$table}_s{$shard}";
	}

	/**
	 * Little helper to get table name for shard
	 * @param  int    $shard
	 * @return string
	 */
	protected function getShardName(int $shard): string {
		return static::getTableShardName($this->name, $shard);
	}

	/**
	 * Helper to get sql for creating distributed table with all shards
	 * @param Set<int> $shards
	 * @return string
	 */
	protected function getCreateShardedTableSQL(Set $shards): string {
		// Calculate local tables
		$locals = new Set;
		foreach ($shards as $shard) {
			$locals->add("local='{$this->getShardName($shard)}'");
		}

		// Calculate external tables
		$nodes = $this->getExternalNodeShards($shards);
		/** @var Map<int,Set<string>> */
		$map = new Map;
		foreach ($nodes as $row) {
			foreach ($row['shards'] as $shard) {
				$map[$shard] ??= new Set;
				$shardName = $this->getShardName($shard);
				// @phpstan-ignore-next-line
				$map[$shard]->add("{$row['node']}:{$shardName}");
			}
		}

		$agents = new Set;
		foreach ($map as $shard => $nodes) {
			// We skip same shard to exclude local nodes as agents
			if ($shards->contains($shard)) {
				continue;
			}
			$agents->add("agent='{$nodes->join('|')}'");
		}

		// Finally generate create table
		return "CREATE TABLE `{$this->name}`
			type='distributed' {$locals->sorted()->join(' ')} {$agents->sorted()->join(' ')}
			";
	}


	/**
	 * Update the current sharding scheme in database
	 * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $scheme
	 * @return static
	 */
	protected function updateScheme(Vector $scheme): static {
		$table = $this->cluster->getSystemTableName($this->table);
		$query = "
		DELETE FROM {$table}
		WHERE
		cluster = '{$this->cluster->name}'
		AND
		table = '{$this->name}'
		";
		$this->client->sendRequest($query);
		foreach ($scheme as $row) {
			$shardsMva = $row['shards']->join(',');
			$query = "
			INSERT INTO {$table}
			(`cluster`, `node`, `table`, `shards`)
			VALUES
			('{$this->cluster->name}', '{$row['node']}', '{$this->name}', ($shardsMva))
			";
			$this->client->sendRequest($query);
		}

		return $this;
	}

	/**
	 * Setup the initial tables for the system cluster
	 * @return void
	 */
	public function setup(): void {
		$hasTable = $this->client->hasTable($this->table);
		if ($hasTable) {
			throw new RuntimeException(
				'Trying to initialize while already initialized.'
			);
		}
		$query = "CREATE TABLE {$this->table} (
		`cluster` string,
		`node` string,
		`table` string,
		`shards` multi
		)";
		$this->client->sendRequest($query);
		$this->cluster->attachTables($this->table);
	}

	/**
	 * Helper to parse shards from string and convert to ints
	 * @param  string $shards
	 * @return Set<int>
	 */
	protected static function parseShards(string $shards): Set {
		return new Set(array_map('intval', explode(',', $shards)));
	}
}
