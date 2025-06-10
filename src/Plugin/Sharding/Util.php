<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Ds\Map;
use Ds\Set;
use Ds\Vector;
use RuntimeException;

/** @package Manticoresearch\Buddy\Base\Plugin\Sharding */
final class Util {
  /**
   * Generate sharding schema by using input nodes, shards and replication
   * @param  Set<string> $nodes
   * @param  int $shardCount
   * @param  int $replicationFactor
   * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
   */
	public static function createShardingSchema(
		Set $nodes,
		int $shardCount,
		int $replicationFactor = 2
	): Vector {
		$nodes->sort();
		$nodeCount = $nodes->count();
		$replicaCount = ($replicationFactor - 1);

		if ($replicaCount >= $nodeCount) {
			throw new RuntimeException(
				"Replica count for factor of {$replicationFactor} is"
					." greater than node count: {$replicaCount} > {$nodeCount}"
			);
		}

		$schema = self::initializeSchema($nodes);
		$nodeMap = self::initializeNodeMap($nodeCount);

		return self::assignNodesToSchema($schema, $nodeMap, $nodes, $shardCount, $replicationFactor);
	}

  /**
   * @param  Set<string> $nodes
   * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
   */
	private static function initializeSchema(Set $nodes): Vector {
	  /** @var Vector<array{node:string,shards:Set<int>,connections:Set<string>}> */
		$schema = new Vector();

		foreach ($nodes as $node) {
			$schema->push(
				[
				'node' => $node,
				'shards' => new Set(),
				'connections' => new Set(),
				]
			);
		}

		return $schema;
	}

  /**
   * @param  int $count Count of nodes
   * @return Map<int,int>
   */
	private static function initializeNodeMap(int $count): Map {
		$map = new Map();

		for ($i = 0; $i < $count; $i++) {
			$map->put($i, 0);
		}

		return $map;
	}

  /**
   * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
   * @param  Map<int,int>    $nodeMap
   * @param  Set<string>    $nodes
   * @param  int    $shardCount
   * @param  int    $replicationFactor
   * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
   */
	private static function assignNodesToSchema(
		Vector $schema,
		Map $nodeMap,
		Set $nodes,
		int $shardCount,
		int $replicationFactor
	): Vector {
		$assignedNodes = new Set();
		$sortedNodes = $nodes->sorted();

		for ($i = 0; $i < $shardCount; $i++) {
			$usedNodesInCurrentReplication = new Set();

			for ($j = 0; $j < $replicationFactor; $j++) {
				$minShards = min($nodeMap->values()->toArray());
				$nodesWithMinShards = $nodeMap->filter(
					fn($node, $shards) =>
					$shards === $minShards
					&& !$usedNodesInCurrentReplication->contains($node)
				)
				  ->keys()->sorted()->toArray();
				$minShardsCount = sizeof($nodesWithMinShards);

				$consistentIndex = ($i + $j) % $minShardsCount;
				$node = $nodesWithMinShards[$consistentIndex];

				$schema->get($node)['shards']->add($i);
				$assignedNodes->add($node);
				$nodeMap->put($node, $nodeMap[$node] + 1);
				$usedNodesInCurrentReplication->add($node);
			}

			foreach ($usedNodesInCurrentReplication as $node) {
				$schema[$node]['connections'] = $usedNodesInCurrentReplication
				->map(fn($i) => $sortedNodes[$i]);
			}
		}

		return $schema;
	}

  /**
   * Make rebalance of the sharding schema and return new one
   *
   * IMPORTANT: This method respects the original replication factor (RF) to ensure data safety:
   * - RF=1: NO data movement - new nodes are added but existing shards stay put to prevent data loss
   * - RF>1: Safe rebalancing - shards can be redistributed because data is replicated
   *
   * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
   * @param  Set<string> $nodes
   * @return Vector<array{
   * node:string,
   * shards:Set<int>,
   * connections:Set<string>
   * }> It's very important here to maintain
   *    original indexes of original schema
   */
	public static function rebalanceShardingScheme(Vector $schema, Set $nodes): Vector {
		$newSchema = self::copyActiveNodeAssignments($schema, $nodes);
		$newSchema = self::addNodesToSchema($newSchema, $nodes);
		$inactiveShards = self::findInactiveShards($schema, $nodes);

		// Check if we have new nodes (nodes with no shards assigned)
		$hasNewNodes = $newSchema->filter(fn($row) => $row['shards']->isEmpty())->count() > 0;

		if ($hasNewNodes) {
			// Calculate original replication factor from existing schema
			$originalRf = self::calculateReplicationFactor($schema);

			// For rf=1, we should NOT move existing data - only link new nodes
			// Data movement with rf=1 would cause data loss
			if ($originalRf === 1) {
				// With rf=1, we can only add new nodes for new shards, not redistribute existing ones
				// Return the schema as-is with new nodes added (they'll get shards via normal shard creation)
				return $newSchema;
			}

			// For rf > 1, we can safely rebalance because data is replicated
			$totalShards = self::getTotalUniqueShards($schema);

			if ($totalShards > 0) {
				// Reuse the balanced assignment logic from createShardingSchema
				// but maintain the original replication factor
				$balancedSchema = self::initializeSchema($nodes);
				$nodeMap = self::initializeNodeMap($nodes->count());
				return self::assignNodesToSchema($balancedSchema, $nodeMap, $nodes, $totalShards, $originalRf);
			}
		}

		// For node failures only (no new nodes), use the original logic
		return self::assignShardsToNodes($newSchema, $inactiveShards);
	}

  /**
   * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}>  $schema
   * @param  Set<string> $nodes
   * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}> It maintains original indexes
   */
	private static function copyActiveNodeAssignments(Vector $schema, Set $nodes): Vector {
		return $schema->filter(
			fn ($row) => $nodes->contains($row['node'])
		);
	}

  /**
   * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
   * @param Set<string>    $nodes
   * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
   */
	private static function addNodesToSchema(Vector $schema, Set $nodes): Vector {
		$schemaNodes = new Set($schema->map(fn ($row) => $row['node']));
		$newNodes = $nodes->diff($schemaNodes);
		foreach ($newNodes as $node) {
			$schema[] = [
				'node' => $node,
				'shards' => new Set,
				'connections' => new Set,
			];
		}

		return $schema;
	}

  /**
   * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
   * @param  Set<string>    $nodes
   * @return Set<int>
   */
	private static function findInactiveShards(Vector $schema, Set $nodes): Set {
		$shards = new Set;

		foreach ($schema as $row) {
			if ($nodes->contains($row['node'])) {
				continue;
			}

			$shards->add(...$row['shards']);
		}
		return $shards;
	}

  /**
   * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
   * @param  Set<int>    $shards
   * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
   */
	private static function assignShardsToNodes(Vector $schema, Set $shards): Vector {
		foreach ($shards as $shard) {
			$node = self::findNodeWithMinimumShards($schema, $shard);
		  // It will never happen, but for phpstan
			if (!isset($schema[$node])) {
				throw new RuntimeException("Inconsistency with schema node #{$node}");
			}
			$schema[$node]['shards']->add($shard);
			$schema[$node]['connections'] = self::findUsedNodesInCurrentReplication($schema, $shard);
		}

		return $schema;
	}

  /**
   * @param Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
	 * @param int $shard
   * @return int
   */
	private static function findNodeWithMinimumShards(Vector $schema, int $shard): int {
		$nodesToChoose = $schema
			->filter(
				fn($row) => !$row['shards']->contains($shard)
			)
			->sorted(
				fn ($a, $b) =>
					$a['shards']->count() < $b['shards']->count() ? -1 : 1
			);
		$index = $schema->find($nodesToChoose->first());
		if (false === $index) {
			throw new RuntimeException('Failed to find node to use');
		}
		return $index;
	}

  /**
   * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
   * @param  int    $shard
   * @return Set<string>
   */
	private static function findUsedNodesInCurrentReplication(Vector $schema, int $shard): Set {
		$set = new Set();

		foreach ($schema as $row) {
			if (!$row['shards']->contains($shard)) {
				continue;
			}

			$set->add($row['node']);
		}

		return $set;
	}

  /**
   * Calculate the original replication factor from the schema
   * by finding how many nodes have the same shard
   * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
   * @return int
   */
	private static function calculateReplicationFactor(Vector $schema): int {
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
		// (in a properly configured system, all shards should have the same RF)
		return max($shardCounts->values()->toArray());
	}

  /**
   * Get the total number of unique shards from the schema
   * @param  Vector<array{node:string,shards:Set<int>,connections:Set<string>}> $schema
   * @return int
   */
	private static function getTotalUniqueShards(Vector $schema): int {
		$allShards = new Set();

		foreach ($schema as $row) {
			$allShards->add(...$row['shards']);
		}

		return $allShards->count();
	}
}
