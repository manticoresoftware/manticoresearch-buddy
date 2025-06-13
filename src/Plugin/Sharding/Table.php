<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Ds\Map;
use Ds\Set;
use Ds\Vector;
use Exception;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;
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
	 * Get ALL nodes and their shards (for RF=1 distributed table creation)
	 * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
	 */
	public function getAllNodeShards(): Vector {
		$query = "
		SELECT node, shards FROM {$this->table}
		WHERE
		cluster = '{$this->cluster->name}'
		AND
		table = '{$this->name}'
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
						'connections' => new Set([$row['node']]), // Self-connection for RF=1
					]
				)
			);
		}

		return $nodes;
	}

	/**
	 * Get external shards for the node ID
	 * @param Set<int> $shards
	 * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
	 */
	public function getExternalNodeShards(Set $shards): Vector {
		// Determine replication factor from current schema to use correct query logic
		$currentSchema = $this->getShardSchema();
		$replicationFactor = $this->getReplicationFactor($currentSchema);

		if ($replicationFactor === 1) {
			// RF=1: Find nodes with DIFFERENT shards (original logic)
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
		} else {
			// RF>=2: Find ALL nodes (they share shards)
			$query = "
			SELECT node, shards FROM {$this->table}
			WHERE
			cluster = '{$this->cluster->name}'
			AND
			table = '{$this->name}'
			ORDER BY id ASC
			";
		}

		$nodes = new Vector;
		/** @var array{0:array{data:array<array{node:string,shards:string}>}} */
		$res = $this->client->sendRequest($query)->getResult();

		foreach ($res[0]['data'] as $row) {
			$nodes->push(
				new Map(
					[
						'node' => $row['node'],
						'shards' => static::parseShards($row['shards']),
						'connections' => new Set([$row['node']]), // Self-connection, will be updated for RF>=2
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
		$reduceFn = function (Map $clusterMap, array $row) use ($queue, &$nodes, &$nodeShardsMap) {
			/** @var Map<string,Cluster> $clusterMap */
			$nodes->add($row['node']);
			$nodeShardsMap[$row['node']] = $row['shards'];

			foreach ($row['shards'] as $shard) {
				$connectedNodes = $this->getConnectedNodes(new Set([$shard]));

				$clusterMap = $this->handleReplication(
					$row['node'],
					$queue,
					$connectedNodes,
					$clusterMap,
					$shard
				);
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
			// Even when no shards, we still create distributed table
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
		// If no replication, add create table shard SQL to queue
		if ($connectedNodes->count() === 1) {
			$sql = $this->getCreateTableShardSQL($shard);
			$queue->add($node, $sql);
			return $clusterMap;
		}

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
			// Create state instance for tracking rebalancing operations
			$state = new State($this->client);

			// Prevent concurrent rebalancing operations
			$rebalanceKey = "rebalance:{$this->name}";
			$currentRebalance = $state->get($rebalanceKey);

			if ($currentRebalance === 'running') {
				Buddy::debugvv("Sharding rebalance: operation already running for table {$this->name}, skipping");
				return;
			}

			// Mark rebalancing as running
			$state->set($rebalanceKey, 'running');

			$schema = $this->getShardSchema();
			$allNodes = $this->cluster->getNodes();
			$inactiveNodes = $this->cluster->getInactiveNodes();
			$activeNodes = $allNodes->diff($inactiveNodes);

			// Detect new nodes (not in current schema)
			$schemaNodes = new Set($schema->map(fn($row) => $row['node']));
			$newNodes = $activeNodes->diff($schemaNodes);

			// Handle different rebalancing scenarios
			if ($inactiveNodes->count() > 0) {
				// Existing logic: handle failed nodes
				$newSchema = Util::rebalanceShardingScheme($schema, $activeNodes);
				$this->handleFailedNodesRebalance($queue, $schema, $newSchema, $inactiveNodes);
			} elseif ($newNodes->count() > 0) {
				// New logic: handle new nodes
				$replicationFactor = $this->getReplicationFactor($schema);

				$newSchema = Util::rebalanceWithNewNodes($schema, $newNodes, $replicationFactor);

				// Log the schema changes
				// Schema changes will be handled by rebalance operations

				$this->handleNewNodesRebalance($queue, $schema, $newSchema, $newNodes);
			} else {
				// No changes needed
				$state->set($rebalanceKey, 'idle');
				return;
			}

			// Mark rebalancing as completed
			$state->set($rebalanceKey, 'completed');
		} catch (\Throwable $t) {
			// Mark rebalancing as failed and reset state
			$state = new State($this->client);
			$rebalanceKey = "rebalance:{$this->name}";
			$state->set($rebalanceKey, 'failed');
			var_dump($t->getMessage());
		}
	}

	/**
	 * Initialize cluster map for rebalancing
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @param Set<string> $inactiveNodes
	 * @return array{shardNodesMap: Map<int,Set<string>>, clusterMap: Map<string,Cluster>}
	 */
	private function initializeClusterMap(Vector $schema, Set $inactiveNodes): array {
		/** @var Map<string,Cluster> */
		$clusterMap = new Map;

		// Detect shard to nodes map with alive schema
		$shardNodesMap = $this->getShardNodesMap(
			$schema->filter(
				fn ($row) => !$inactiveNodes->contains($row['node'])
			)
		);

		// Preload current cluster map with configuration
		foreach ($shardNodesMap as $connections) {
			$clusterName = static::getClusterName($connections);
			$connections->sort();
			$node = $connections->first();
			$cluster = new Cluster($this->client, $clusterName, $node);
			$clusterMap[$clusterName] = $cluster;
		}

		return ['shardNodesMap' => $shardNodesMap, 'clusterMap' => $clusterMap];
	}

	/**
	 * Process affected shards for rebalancing
	 * @param Queue $queue
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $affectedSchema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @param Map<int,Set<string>> $shardNodesMap
	 * @param Map<string,Cluster> $clusterMap
	 * @return Map<string,Cluster>
	 */
	private function processAffectedShards(
		Queue $queue,
		Vector $affectedSchema,
		Vector $newSchema,
		Map $shardNodesMap,
		Map $clusterMap
	): Map {
		/** @var Set<string> */
		$processedTables = new Set;

		foreach ($affectedSchema as $row) {
			// First thing first, remove from inactive node using the queue
			$this->cleanUpNode($queue, $row['node'], $row['shards'], $processedTables);

			// Do real rebalance now
			$clusterMap = $this->rebalanceShards($queue, $row, $newSchema, $shardNodesMap, $clusterMap);
		}

		return $clusterMap;
	}

	/**
	 * Rebalance shards for a single node
	 * @param Queue $queue
	 * @param array{node:string,shards:Set<int>,connections:Set<string>} $row
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @param Map<int,Set<string>> $shardNodesMap
	 * @param Map<string,Cluster> $clusterMap
	 * @return Map<string,Cluster>
	 */
	private function rebalanceShards(
		Queue $queue,
		array $row,
		Vector $newSchema,
		Map $shardNodesMap,
		Map $clusterMap
	): Map {
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

			$clusterMap = $this->handleShardReplication($queue, $shard, $nodesForShard, $shardNodesMap, $clusterMap);
		}

		return $clusterMap;
	}

	/**
	 * Handle replication for a single shard
	 * @param Queue $queue
	 * @param int $shard
	 * @param Set<string> $nodesForShard
	 * @param Map<int,Set<string>> $shardNodesMap
	 * @param Map<string,Cluster> $clusterMap
	 * @return Map<string,Cluster>
	 */
	private function handleShardReplication(
		Queue $queue,
		int $shard,
		Set $nodesForShard,
		Map $shardNodesMap,
		Map $clusterMap
	): Map {
		// It's very important here to start replication
		// From the live node first due to
		// cluster should be created there first
		// We use previously generated shard to nodes map
		/** @var Set<string> */
		$shardNodes = $shardNodesMap[$shard];

		// If this happens, we have no alive shard, this is critical, but do nothing for now
		if (!$shardNodes->count()) {
			return $clusterMap;
		}
		$shardNodes->sort();
		$aliveNode = $shardNodes->first();
		/** @var Set<string> */
		$connectedNodes = $nodesForShard->merge($shardNodes);

		// Reconfigure on alive shard
		// Cuz it's the main shard where we have data already
		return $this->handleReplication(
			$aliveNode,
			$queue,
			$connectedNodes,
			$clusterMap,
			$shard
		);
	}

	/**
	 * Handle rebalancing for failed nodes (existing logic)
	 * @param Queue $queue
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @param Set<string> $inactiveNodes
	 * @return void
	 */
	protected function handleFailedNodesRebalance(
		Queue $queue,
		Vector $schema,
		Vector $newSchema,
		Set $inactiveNodes
	): void {
		// Initialize cluster map and shard nodes mapping
		$initData = $this->initializeClusterMap($schema, $inactiveNodes);
		$shardNodesMap = $initData['shardNodesMap'];
		$clusterMap = $initData['clusterMap'];

		$affectedSchema = $schema->filter(
			fn ($row) => $inactiveNodes->contains($row['node'])
		);

		// Process affected shards for rebalancing
		$this->processAffectedShards($queue, $affectedSchema, $newSchema, $shardNodesMap, $clusterMap);

		// Update schema in database FIRST, then create distributed tables
		$this->updateScheme($newSchema);
		$this->createDistributedTablesFromSchema($queue, $newSchema);
	}

	/**
	 * Handle rebalancing for new nodes
	 * @param Queue $queue
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @param Set<string> $newNodes
	 * @return void
	 */
	protected function handleNewNodesRebalance(Queue $queue, Vector $schema, Vector $newSchema, Set $newNodes): void {
		$replicationFactor = $this->getReplicationFactor($schema);

		if ($replicationFactor === 1) {
			$this->handleRF1NewNodes($queue, $schema, $newSchema, $newNodes);
		} else {
			$this->handleRFNNewNodes($queue, $schema, $newSchema, $newNodes);
		}
	}

	/**
	 * Handle RF=1 new nodes - move shards using intermediate clusters
	 * @param Queue $queue
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @param Set<string> $newNodes
	 * @return void
	 */
	protected function handleRF1NewNodes(Queue $queue, Vector $schema, Vector $newSchema, Set $newNodes): void {
		// For RF=1, we need to move shards from existing nodes to new nodes
		// This requires careful orchestration with temporary clusters

		$shardsToMove = $this->calculateShardsToMove($schema, $newSchema, $newNodes);

		// Track the last queue ID from shard movements to ensure they complete first
		$lastMoveQueueId = 0;
		foreach ($shardsToMove as $shardId => $moveInfo) {
			// Get the last queue ID from the shard movement
			$lastMoveQueueId = $this->moveShardWithIntermediateCluster(
				$queue,
				$shardId,
				$moveInfo['from'],
				$moveInfo['to']
			);
		}

		// Update schema in database ONLY AFTER all shard movements complete
		// CRITICAL: Wait for all shard movements to complete before updating schema
		if ($lastMoveQueueId > 0) {
			$queue->setWaitForId($lastMoveQueueId);
		}

		$this->updateScheme($newSchema);

		$this->createDistributedTablesFromSchema($queue, $newSchema);

		// Reset wait for id
		$queue->resetWaitForId();
	}

	/**
	 * Handle RF>=2 new nodes - add replicas
	 * @param Queue $queue
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @param Set<string> $newNodes
	 * @return void
	 */
	protected function handleRFNNewNodes(Queue $queue, Vector $schema, Vector $newSchema, Set $newNodes): void {
		/** @var Map<string,Cluster> */
		$clusterMap = new Map;

		// For RF>=2, we add new nodes as replicas to existing shards
		foreach ($newNodes as $newNode) {
			// Find shards that should be replicated to this new node
			$shardsForNewNode = $this->getShardsForNewNode($newSchema, $newNode);

			foreach ($shardsForNewNode as $shard) {
				// Create shard table on new node
				$queue->add($newNode, $this->getCreateTableShardSQL($shard));

				// Set up replication from existing nodes
				$existingNodes = $this->getExistingNodesForShard($schema, $shard);
				if ($existingNodes->count() <= 0) {
					continue;
				}

				$connectedNodes = $existingNodes->merge(new Set([$newNode]));
				$existingNodes->sort();
				$primaryNode = $existingNodes->first();

				$clusterMap = $this->handleReplication(
					$primaryNode,
					$queue,
					$connectedNodes,
					$clusterMap,
					$shard
				);
			}
		}

		// Update schema in database FIRST, then create distributed tables
		$this->updateScheme($newSchema);
		$this->createDistributedTablesFromSchema($queue, $newSchema);
	}

	/**
	 * Create distributed tables from schema
	 * @param Queue $queue
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @return void
	 */
	protected function createDistributedTablesFromSchema(Queue $queue, Vector $newSchema): void {
		/** @var Set<int> */
		$dropQueueIds = new Set;

		// First, drop all distributed tables with proper force option
		foreach ($newSchema as $row) {
			$sql = "DROP TABLE IF EXISTS {$this->name} OPTION force=1";
			$queueId = $queue->add($row['node'], $sql);
			$dropQueueIds->add($queueId);
		}

		// Then create new distributed tables, waiting for all drops to complete
		$lastDropId = $dropQueueIds->count() > 0 ? max($dropQueueIds->toArray()) : 0;

		foreach ($newSchema as $row) {
			// Do nothing when no shards present for this node
			if (!$row['shards']->count()) {
				continue;
			}

			// Wait for all DROP operations to complete before creating new tables
			if ($lastDropId > 0) {
				$queue->setWaitForId($lastDropId);
			}

			// Pass the calculated schema to avoid database timing issues
			$sql = $this->getCreateShardedTableSQLWithSchema($row['shards'], $newSchema);
			$queue->add($row['node'], $sql);
		}

		// Reset wait for id
		$queue->resetWaitForId();
	}

	/**
	 * Get replication factor from current schema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @return int
	 */
	protected function getReplicationFactor(Vector $schema): int {
		if ($schema->count() === 0) {
			return 1;
		}

		// Find the maximum number of connections for any shard
		$maxConnections = 1;
		foreach ($schema as $row) {
			$maxConnections = max($maxConnections, $row['connections']->count());
		}

		return $maxConnections;
	}

	/**
	 * Calculate which shards should move for RF=1 rebalancing
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $oldSchema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @param Set<string> $newNodes
	 * @return array<int,array{from:string,to:string}>
	 */
	protected function calculateShardsToMove(Vector $oldSchema, Vector $newSchema, Set $newNodes): array {
		$moves = [];


		foreach ($newSchema as $row) {
			if (!$newNodes->contains($row['node'])) {
				continue;
			}


			// This is a new node, find shards assigned to it
			foreach ($row['shards'] as $shard) {
				// Find where this shard was in the old schema
				$oldOwner = $this->findShardOwner($oldSchema, $shard);

				if (!$oldOwner) {
					continue;
				}

				$moves[$shard] = ['from' => $oldOwner, 'to' => $row['node']];
			}
		}

		return $moves;
	}

	/**
	 * Find the owner of a shard in the schema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @param int $shard
	 * @return string|null
	 */
	protected function findShardOwner(Vector $schema, int $shard): ?string {
		foreach ($schema as $row) {
			if ($row['shards']->contains($shard)) {
				return $row['node'];
			}
		}
		return null;
	}

	/**
	 * Move shard using intermediate cluster for RF=1
	 * @param Queue $queue
	 * @param int $shardId
	 * @param string $sourceNode
	 * @param string $targetNode
	 * @return int Last queue ID for synchronization
	 */
	protected function moveShardWithIntermediateCluster(
		Queue $queue,
		int $shardId,
		string $sourceNode,
		string $targetNode
	): int {
		$shardName = $this->getShardName($shardId);
		$tempClusterName = "temp_move_{$shardId}_" . uniqid();

		// Step 1: Create shard table on target node
		$createQueueId = $queue->add($targetNode, $this->getCreateTableShardSQL($shardId));

		// Step 2: Create temporary cluster on SOURCE node (where the data IS)
		// CRITICAL: Use cluster name as path to ensure uniqueness for intermediate clusters
		$clusterQueueId = $queue->add(
			$sourceNode,
			"CREATE CLUSTER {$tempClusterName} '{$tempClusterName}' as path"
		);

		// Step 3: Add shard to cluster on SOURCE node FIRST (before JOIN)
		$queue->setWaitForId($clusterQueueId);
		$queue->add($sourceNode, "ALTER CLUSTER {$tempClusterName} ADD {$shardName}");

		// Step 4: NEW node joins the cluster that SOURCE created
		// Wait for table creation on target node to complete first
		// CRITICAL: Use same path as in CREATE CLUSTER with 'as path'
		$queue->setWaitForId($createQueueId);
		$joinQueueId = $queue->add(
			$targetNode,
			"JOIN CLUSTER {$tempClusterName} AT '{$sourceNode}' '{$tempClusterName}' as path "
		);

		// Step 5: CRITICAL - Wait for JOIN to complete (data is now synced)
		// JOIN CLUSTER is synchronous, so once it's processed, data is fully copied
		$queue->setWaitForId($joinQueueId);
		$dropQueueId = $queue->add($sourceNode, "ALTER CLUSTER {$tempClusterName} DROP {$shardName}");

		// Step 6: Only after DROP from cluster, remove the table from source
		$queue->setWaitForId($dropQueueId);
		$deleteQueueId = $queue->add($sourceNode, "DROP TABLE {$shardName}");

		// Step 7: Clean up temporary cluster ONLY on SOURCE node after all operations complete
		$queue->setWaitForId($deleteQueueId);
		return $queue->add($sourceNode, "DELETE CLUSTER {$tempClusterName}");
	}

	/**
	 * Get shards assigned to a new node
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @param string $newNode
	 * @return Set<int>
	 */
	protected function getShardsForNewNode(Vector $newSchema, string $newNode): Set {
		foreach ($newSchema as $row) {
			if ($row['node'] === $newNode) {
				return $row['shards'];
			}
		}
		return new Set();
	}

	/**
	 * Get existing nodes that have a specific shard
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @param int $shard
	 * @return Set<string>
	 */
	protected function getExistingNodesForShard(Vector $schema, int $shard): Set {
		$nodes = new Set();
		foreach ($schema as $row) {
			if (!$row['shards']->contains($shard)) {
				continue;
			}

			$nodes->add($row['node']);
		}
		return $nodes;
	}

	/**
	 * Check if rebalancing can be started for this table
	 * @return bool
	 */
	public function canStartRebalancing(): bool {
		$state = new State($this->client);
		$rebalanceKey = "rebalance:{$this->name}";
		$currentRebalance = $state->get($rebalanceKey);

		return $currentRebalance !== 'running';
	}

	/**
	 * Reset rebalancing state (useful for recovery)
	 * @return void
	 */
	public function resetRebalancingState(): void {
		$state = new State($this->client);
		$rebalanceKey = "rebalance:{$this->name}";
		$state->set($rebalanceKey, 'idle');
	}

	/**
	 * Get current rebalancing status
	 * @return string
	 */
	public function getRebalancingStatus(): string {
		$state = new State($this->client);
		$rebalanceKey = "rebalance:{$this->name}";
		$status = $state->get($rebalanceKey);
		return is_string($status) ? $status : 'idle';
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
	 * Build local table definitions from shards
	 * @param Set<int> $shards
	 * @return Set<string>
	 */
	private function buildLocalTableDefinitions(Set $shards): Set {
		$locals = new Set;
		foreach ($shards as $shard) {
			$locals->add("local='{$this->getShardName($shard)}'");
		}
		return $locals;
	}

	/**
	 * Build shard to nodes mapping from schema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @return Map<int,Set<string>>
	 */
	private function buildShardNodesMapping(Vector $schema): Map {
		/** @var Map<int,Set<string>> */
		$map = new Map;
		foreach ($schema as $row) {
			foreach ($row['shards'] as $shard) {
				$map[$shard] ??= new Set;
				$shardName = $this->getShardName($shard);
				// @phpstan-ignore-next-line
				$map[$shard]->add("{$row['node']}:{$shardName}");
			}
		}
		return $map;
	}

	/**
	 * Build agent definitions based on replication factor and shard mapping
	 * @param Map<int,Set<string>> $map
	 * @param Set<int> $shards
	 * @param int $replicationFactor
	 * @return Set<string>
	 */
	private function buildAgentDefinitions(Map $map, Set $shards, int $replicationFactor): Set {
		$agents = new Set;
		$currentNodeId = Node::findId($this->client);

		foreach ($map as $shard => $nodeConnections) {
			if ($replicationFactor === 1) {
				// RF=1: Create agents for shards that DON'T exist locally (remote shards)
				if ($shards->contains($shard)) {
					continue;
				}
			} else {
				// RF>=2: Create agents for shards that DO exist locally (replicated shards)
				if (!$shards->contains($shard)) {
					continue;
				}
			}

			// Filter out the current node from agents (don't point to yourself)
			$remoteConnections = $nodeConnections->filter(
				fn($connection) => !str_starts_with($connection, $currentNodeId . ':')
			);

			if ($remoteConnections->count() <= 0) {
				continue;
			}

			$agents->add("agent='{$remoteConnections->join('|')}'");
		}

		return $agents;
	}

	/**
	 * Helper to get sql for creating distributed table with all shards using provided schema
	 * @param Set<int> $shards
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @return string
	 */
	protected function getCreateShardedTableSQLWithSchema(Set $shards, Vector $schema): string {
		// Calculate local tables
		$locals = $this->buildLocalTableDefinitions($shards);

		// Use the provided schema instead of querying database
		$replicationFactor = $this->getReplicationFactor($schema);

		// Build map of all shards and their nodes from the provided schema
		$map = $this->buildShardNodesMapping($schema);

		$agents = $this->buildAgentDefinitions($map, $shards, $replicationFactor);

		// Finally generate create table
		return "CREATE TABLE `{$this->name}`
			type='distributed' {$locals->sorted()->join(' ')} {$agents->sorted()->join(' ')}
			";
	}

	/**
	 * Get node shards based on replication factor
	 * @param Set<int> $shards
	 * @param int $replicationFactor
	 * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
	 */
	private function getNodeShardsByReplicationFactor(Set $shards, int $replicationFactor): Vector {
		if ($replicationFactor === 1) {
			// RF=1: Get ALL nodes and ALL their shards
			return $this->getAllNodeShards();
		}

		// RF>=2: Use the existing external logic
		return $this->getExternalNodeShards($shards);
	}

	/**
	 * Helper to get sql for creating distributed table with all shards
	 * @param Set<int> $shards
	 * @return string
	 */
	protected function getCreateShardedTableSQL(Set $shards): string {
		// Calculate local tables
		$locals = $this->buildLocalTableDefinitions($shards);

		// For RF=1, we need ALL nodes and ALL their shards for the distributed table
		// Not just the "external" ones based on current node's shards
		$currentSchema = $this->getShardSchema();
		$replicationFactor = $this->getReplicationFactor($currentSchema);

		$nodes = $this->getNodeShardsByReplicationFactor($shards, $replicationFactor);

		/** @var Map<int,Set<string>> */
		$map = new Map;
		foreach ($nodes as $row) {
			foreach ($row['shards'] as $shard) {
				if (!$map->hasKey($shard)) {
					$map[$shard] = new Set();
				}
				$shardName = $this->getShardName($shard);

				$map[$shard]?->add("{$row['node']}:{$shardName}");
			}
		}

		$agents = $this->buildAgentDefinitions($map, $shards, $replicationFactor);

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
		return trim($shards) !== ''
			? new Set(array_map('intval', explode(',', $shards)))
			: new Set
		;
	}

	/**
	 * Handle shard creation for rebalancing (all nodes that need new shards)
	 *
	 * SAFETY: Respects original replication factor - with RF=1, only creates shard tables
	 * but does NOT set up replication to prevent data movement and potential data loss.
	 *
	 * @param Queue $queue
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $oldSchema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $newSchema
	 * @param Map<string,Cluster> $clusterMap
	 * @return void
	 */
	protected function handleShardCreationForRebalancing(
		Queue $queue,
		Vector $oldSchema,
		Vector $newSchema,
		Map $clusterMap
	): void {
		// Calculate original replication factor to ensure safe operations
		$originalRf = $this->calculateReplicationFactor($oldSchema);

		// Create map of old schema for comparison
		/** @var Map<string,Set<int>> */
		$oldShardMap = new Map();
		foreach ($oldSchema as $row) {
			$oldShardMap[$row['node']] = $row['shards'];
		}

		foreach ($newSchema as $row) {
			$oldShards = $oldShardMap->get($row['node'], new Set());
			$newShards = $row['shards'];
			$shardsToCreate = $newShards->diff($oldShards);

			if ($shardsToCreate->isEmpty()) {
				continue;
			}

			// Create missing shard tables on this node
			foreach ($shardsToCreate as $shard) {
				$sql = $this->getCreateTableShardSQL($shard);
				$queue->add($row['node'], $sql);

				// Find nodes that already have this shard in old schema for replication
				$existingNodesWithShard = $oldSchema->filter(
					fn($existingRow) => $existingRow['shards']->contains($shard)
				);

				// If there are existing nodes with this shard, set up replication
				// BUT only if original RF > 1 (safe to replicate)
				if ($existingNodesWithShard->count() <= 0 || $originalRf === 1) {
					continue;
				}

				$sourceNode = $existingNodesWithShard->first()['node'];
				$connectedNodes = new Set([$row['node'], $sourceNode]);

				// Set up cluster replication for this shard
				$this->handleReplication(
					$sourceNode,
					$queue,
					$connectedNodes,
					$clusterMap,
					$shard
				);
			}
		}
	}

	/**
	 * Calculate the original replication factor from the schema
	 * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @return int
	 */
	private function calculateReplicationFactor(Vector $schema): int {
		if ($schema->isEmpty()) {
			return 1;
		}

		// Count how many nodes have each shard
		$shardCounts = new Map();

		foreach ($schema as $row) {
			foreach ($row['shards'] as $shard) {
				$currentCount = $shardCounts->get($shard, 0);
				$shardCounts->put($shard, $currentCount + 1);
			}
		}

		if ($shardCounts->isEmpty()) {
			return 1;
		}

		// The replication factor is the maximum count of any shard
		return max($shardCounts->values()->toArray());
	}
}
