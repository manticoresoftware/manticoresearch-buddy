<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Set;
use Ds\Vector;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Util;
use PHPUnit\Framework\TestCase;

class UtilNewNodeTest extends TestCase {

	/**
	 * Test rebalancing with new nodes for RF=1
	 * @return void
	 */
	public function testRebalanceWithNewNodesRF1(): void {
		// Create initial schema with 2 nodes, 4 shards, RF=1
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([2, 3]),
				'connections' => new Set(['node2']),
			],
			]
		);

		$newNodes = new Set(['node3']);
		$replicationFactor = 1;

		$newSchema = Util::rebalanceWithNewNodes($initialSchema, $newNodes, $replicationFactor);

		// Verify new node was added
		$this->assertEquals(3, $newSchema->count());

		// Verify all nodes have shards (balanced distribution)
		$nodeShardCounts = [];
		foreach ($newSchema as $row) {
			$nodeShardCounts[$row['node']] = $row['shards']->count();
		}

		// For 4 shards across 3 nodes, distribution should be roughly [1,1,2] or [1,2,1] etc.
		$totalShards = array_sum($nodeShardCounts);
		$this->assertEquals(4, $totalShards, 'Total shards should remain 4');

		// Each node should have at least 1 shard, none should have more than 2
		foreach ($nodeShardCounts as $node => $count) {
			$this->assertGreaterThanOrEqual(1, $count, "Node {$node} should have at least 1 shard");
			$this->assertLessThanOrEqual(2, $count, "Node {$node} should have at most 2 shards");
		}

		// Verify RF=1 (each node has only itself in connections)
		foreach ($newSchema as $row) {
			$this->assertEquals(1, $row['connections']->count(), 'RF=1 should have single connection');
			$this->assertTrue($row['connections']->contains($row['node']), 'Node should connect to itself');
		}
	}

	/**
	 * Test rebalancing with new nodes for RF=2
	 * @return void
	 */
	public function testRebalanceWithNewNodesRF2(): void {
		// Create initial schema with 2 nodes, 4 shards, RF=2
		// More realistic scenario: each shard has exactly 2 replicas
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1, 2, 3]), // node1 has all shards
				'connections' => new Set(['node1', 'node2']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([0, 1, 2, 3]), // node2 has all shards (RF=2)
				'connections' => new Set(['node1', 'node2']),
			],
			]
		);

		$newNodes = new Set(['node3']);
		$replicationFactor = 2;

		$newSchema = Util::rebalanceWithNewNodes($initialSchema, $newNodes, $replicationFactor);

		// Verify new node was added
		$this->assertEquals(3, $newSchema->count());

		// For RF=2 with load balancing, new node should get some replicas
		// even though RF is already satisfied, for better distribution
		$newNodeShards = null;
		foreach ($newSchema as $row) {
			if ($row['node'] === 'node3') {
				$newNodeShards = $row['shards'];
				break;
			}
		}

		$this->assertNotNull($newNodeShards, 'New node should be found in schema');
		$this->assertGreaterThan(0, $newNodeShards->count(), 'New node should have some shards for load balancing');

		// Verify that new node's shards are properly replicated
		// Each shard the new node has should exist on at least one other node
		foreach ($newNodeShards as $shard) {
			$replicaCount = 0;
			foreach ($newSchema as $row) {
				if (!$row['shards']->contains($shard)) {
					continue;
				}

				$replicaCount++;
			}
			$this->assertGreaterThanOrEqual(2, $replicaCount, "Shard {$shard} should have at least 2 replicas");
		}
	}

	/**
	 * Test empty new nodes set
	 * @return void
	 */
	public function testRebalanceWithNoNewNodes(): void {
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1']),
			],
			]
		);

		$newNodes = new Set([]);
		$replicationFactor = 1;

		$newSchema = Util::rebalanceWithNewNodes($initialSchema, $newNodes, $replicationFactor);

		// Should return unchanged schema
		$this->assertEquals($initialSchema, $newSchema);
	}

	/**
	 * Test multiple new nodes
	 * @return void
	 */
	public function testRebalanceWithMultipleNewNodes(): void {
		// Start with 1 node, 6 shards
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1, 2, 3, 4, 5]),
				'connections' => new Set(['node1']),
			],
			]
		);

		$newNodes = new Set(['node2', 'node3']);
		$replicationFactor = 1;

		$newSchema = Util::rebalanceWithNewNodes($initialSchema, $newNodes, $replicationFactor);

		// Should have 3 nodes total
		$this->assertEquals(3, $newSchema->count());

		// Verify balanced distribution (6 shards across 3 nodes = 2 each)
		foreach ($newSchema as $row) {
			$this->assertEquals(2, $row['shards']->count(), 'Each node should have 2 shards');
		}

		// Verify all shards are accounted for
		$allShards = new Set();
		foreach ($newSchema as $row) {
			$allShards->add(...$row['shards']);
		}
		$this->assertEquals(6, $allShards->count(), 'All 6 shards should be present');
		$this->assertEquals(new Set([0, 1, 2, 3, 4, 5]), $allShards, 'All original shards should be preserved');
	}

	/**
	 * Test RF=3 with new nodes
	 * @return void
	 */
	public function testRebalanceWithNewNodesRF3(): void {
		// Create initial schema with 2 nodes, 2 shards, RF=2 (under-replicated)
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1', 'node2']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1', 'node2']),
			],
			]
		);

		$newNodes = new Set(['node3']);
		$replicationFactor = 3; // Want to increase RF to 3

		$newSchema = Util::rebalanceWithNewNodes($initialSchema, $newNodes, $replicationFactor);

		// Verify new node was added
		$this->assertEquals(3, $newSchema->count());

		// New node should get replicas to increase RF to 3
		$newNodeShards = null;
		foreach ($newSchema as $row) {
			if ($row['node'] === 'node3') {
				$newNodeShards = $row['shards'];
				break;
			}
		}

		$this->assertNotNull($newNodeShards, 'New node should be found in schema');
		$this->assertGreaterThan(0, $newNodeShards->count(), 'New node should have some shards');

		// Check that shards now have 3 replicas
		foreach ($newNodeShards as $shard) {
			$replicaCount = 0;
			foreach ($newSchema as $row) {
				if (!$row['shards']->contains($shard)) {
					continue;
				}

				$replicaCount++;
			}
			$this->assertEquals(3, $replicaCount, "Shard {$shard} should have exactly 3 replicas");
		}
	}

	/**
	 * Test edge case: more new nodes than shards
	 * @return void
	 */
	public function testMoreNewNodesThanShards(): void {
		// 1 node with 2 shards
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1']),
			],
			]
		);

		// Adding 3 new nodes (more than shards)
		$newNodes = new Set(['node2', 'node3', 'node4']);
		$replicationFactor = 1;

		$newSchema = Util::rebalanceWithNewNodes($initialSchema, $newNodes, $replicationFactor);

		// Should have 4 nodes total
		$this->assertEquals(4, $newSchema->count());

		// Some nodes will have shards, others won't (since we only have 2 shards)
		$nodesWithShards = 0;
		$nodesWithoutShards = 0;

		foreach ($newSchema as $row) {
			if ($row['shards']->count() > 0) {
				$nodesWithShards++;
				$this->assertLessThanOrEqual(1, $row['shards']->count(), 'No node should have more than 1 shard');
			} else {
				$nodesWithoutShards++;
			}
		}

		$this->assertEquals(2, $nodesWithShards, 'Exactly 2 nodes should have shards');
		$this->assertEquals(2, $nodesWithoutShards, 'Exactly 2 nodes should have no shards');
	}
}
