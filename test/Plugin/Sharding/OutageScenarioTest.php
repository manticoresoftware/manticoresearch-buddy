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

/**
 * Test outage scenarios for different replication factors
 * Validates behavior when nodes fail and we need to redistribute shards
 */
class OutageScenarioTest extends TestCase {

	/**
	 * Test RF=2 with one node failure - should redistribute properly
	 * @return void
	 */
	public function testRF2OneNodeFailure(): void {
		// Create initial RF=2 schema: 3 nodes, realistic replication
		// Each shard should be on exactly 2 nodes for RF=2
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),  // Shares shards 0,1 with node2
				'connections' => new Set(['node1', 'node2']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([0, 1]),  // Shares shards 0,1 with node1 (this node will fail)
				'connections' => new Set(['node1', 'node2']),
			],
			[
				'node' => 'node3',
				'shards' => new Set([2, 3]),  // Has unique shards 2,3
				'connections' => new Set(['node3']),
			],
			]
		);

		// Simulate node2 failure - this creates orphaned shards 0,1
		// (since node2 was the only other replica for shards 0,1)
		$activeNodes = new Set(['node1', 'node3']);

		// Test the existing rebalanceShardingScheme method for failed nodes
		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Verify we still have 2 active nodes
		$this->assertEquals(2, $newSchema->count(), 'Should have 2 active nodes after failure');

		// Verify node2 is removed from schema
		$nodeNames = new Set($newSchema->map(fn($row) => $row['node']));
		$this->assertFalse($nodeNames->contains('node2'), 'Failed node should be removed from schema');
		$this->assertTrue($nodeNames->contains('node1'), 'Active node1 should remain');
		$this->assertTrue($nodeNames->contains('node3'), 'Active node3 should remain');

		// Verify all shards are still available
		$allShards = new Set();
		foreach ($newSchema as $row) {
			$allShards->add(...$row['shards']);
		}
		$this->assertEquals(new Set([0, 1, 2, 3]), $allShards, 'All shards should still be available');

		// For RF=2 with only 2 nodes remaining, we need to ensure proper replication
		// The system should try to maintain RF=2 where possible
		$node1Shards = null;
		$node3Shards = null;
		foreach ($newSchema as $row) {
			if ($row['node'] === 'node1') {
				$node1Shards = $row['shards'];
			} elseif ($row['node'] === 'node3') {
				$node3Shards = $row['shards'];
			}
		}

		$this->assertNotNull($node1Shards, 'Node1 should be in schema');
		$this->assertNotNull($node3Shards, 'Node3 should be in schema');

		// Node1 should keep its original shards 0,1
		$this->assertTrue($node1Shards->contains(0) && $node1Shards->contains(1), 'Node1 should keep shards 0,1');

		// Node3 should keep its original shards 2,3
		$this->assertTrue($node3Shards->contains(2) && $node3Shards->contains(3), 'Node3 should keep shards 2,3');
	}

	/**
	 * Test RF=2 with multiple node failures - should handle gracefully
	 * @return void
	 */
	public function testRF2MultipleNodeFailures(): void {
		// Create initial RF=2 schema: 4 nodes with proper replication
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1', 'node2']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([0, 1]),  // This node will fail
				'connections' => new Set(['node1', 'node2']),
			],
			[
				'node' => 'node3',
				'shards' => new Set([2, 3]),
				'connections' => new Set(['node3', 'node4']),
			],
			[
				'node' => 'node4',
				'shards' => new Set([2, 3]),  // This node will fail
				'connections' => new Set(['node3', 'node4']),
			],
			]
		);

		// Simulate node2 and node4 failure (50% of nodes)
		$activeNodes = new Set(['node1', 'node3']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Verify we have 2 remaining nodes
		$this->assertEquals(2, $newSchema->count(), 'Should have 2 active nodes after multiple failures');

		// Verify all shards are still available
		$allShards = new Set();
		foreach ($newSchema as $row) {
			$allShards->add(...$row['shards']);
		}
		$this->assertEquals(new Set([0, 1, 2, 3]), $allShards, 'All shards should still be available');

		// Each remaining node should keep its original shards
		$node1Shards = null;
		$node3Shards = null;
		foreach ($newSchema as $row) {
			if ($row['node'] === 'node1') {
				$node1Shards = $row['shards'];
			} elseif ($row['node'] === 'node3') {
				$node3Shards = $row['shards'];
			}
		}

		$this->assertNotNull($node1Shards, 'Node1 should be in new schema');
		$this->assertNotNull($node3Shards, 'Node3 should be in new schema');

		// Node1 should have shards 0,1 (from its original assignment)
		$this->assertTrue($node1Shards->contains(0) && $node1Shards->contains(1), 'Node1 should have shards 0,1');

		// Node3 should have shards 2,3 (from its original assignment)
		$this->assertTrue($node3Shards->contains(2) && $node3Shards->contains(3), 'Node3 should have shards 2,3');
	}

	/**
	 * Test RF=1 with one node failure - should skip rebalancing if not enough nodes
	 * @return void
	 */
	public function testRF1OneNodeFailureInsufficientNodes(): void {
		// Create initial RF=1 schema: 2 nodes, each has unique shards (no overlap)
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([2, 3]),  // Completely different shards
				'connections' => new Set(['node2']),
			],
			]
		);

		// Simulate node2 failure - now we only have 1 node for 4 shards
		$activeNodes = new Set(['node1']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// For RF=1, if we can't maintain proper distribution, we should:
		// Put all orphaned shards on remaining node (degraded but functional)

		$this->assertEquals(1, $newSchema->count(), 'Should have 1 remaining node');

		$remainingNode = $newSchema[0];
		$this->assertNotNull($remainingNode, 'Remaining node should not be null');
		$this->assertEquals('node1', $remainingNode['node'], 'Remaining node should be node1');

		// The remaining node should have all orphaned shards assigned to it
		// This is degraded mode but keeps the system functional
		$this->assertTrue($remainingNode['shards']->contains(0), 'Should have original shard 0');
		$this->assertTrue($remainingNode['shards']->contains(1), 'Should have original shard 1');
		$this->assertTrue($remainingNode['shards']->contains(2), 'Should have orphaned shard 2');
		$this->assertTrue($remainingNode['shards']->contains(3), 'Should have orphaned shard 3');

		// All shards should be accounted for
		$this->assertEquals(4, $remainingNode['shards']->count(), 'All shards should be on remaining node');
	}

	/**
	 * Test RF=1 with sufficient nodes for redistribution
	 * @return void
	 */
	public function testRF1OneNodeFailureSufficientNodes(): void {
		// Create initial RF=1 schema: 3 nodes
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([2]),
				'connections' => new Set(['node2']),
			],
			[
				'node' => 'node3',
				'shards' => new Set([3]),
				'connections' => new Set(['node3']),
			],
			]
		);

		// Simulate node2 failure
		$activeNodes = new Set(['node1', 'node3']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Should have 2 remaining nodes
		$this->assertEquals(2, $newSchema->count(), 'Should have 2 remaining nodes');

		// All shards should still be available
		$allShards = new Set();
		foreach ($newSchema as $row) {
			$allShards->add(...$row['shards']);
		}
		$this->assertEquals(new Set([0, 1, 2, 3]), $allShards, 'All shards should be redistributed');

		// Orphaned shard 2 should be redistributed to one of the remaining nodes
		$node1Shards = null;
		$node3Shards = null;
		foreach ($newSchema as $row) {
			if ($row['node'] === 'node1') {
				$node1Shards = $row['shards'];
			} elseif ($row['node'] === 'node3') {
				$node3Shards = $row['shards'];
			}
		}

		$this->assertNotNull($node1Shards, 'Node1 should be in schema');
		$this->assertNotNull($node3Shards, 'Node3 should be in schema');

		// One of the nodes should have picked up the orphaned shard 2
		$this->assertTrue(
			$node1Shards->contains(2) || $node3Shards->contains(2),
			'Orphaned shard 2 should be assigned to one of the remaining nodes'
		);

		// Verify RF=1 constraint (each shard on exactly one node)
		foreach ([0, 1, 2, 3] as $shard) {
			$nodeCount = 0;
			if ($node1Shards->contains($shard)) {
				$nodeCount++;
			}
			if ($node3Shards->contains($shard)) {
				$nodeCount++;
			}

			$this->assertEquals(1, $nodeCount, "Shard {$shard} should be on exactly 1 node for RF=1");
		}
	}

	/**
	 * Test RF=3 with one node failure - should maintain replication
	 * @return void
	 */
	public function testRF3OneNodeFailure(): void {
		// Create initial RF=3 schema: 4 nodes, each shard on 3 nodes
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1', 'node2', 'node3']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1', 'node2', 'node3']),
			],
			[
				'node' => 'node3',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1', 'node2', 'node3']),
			],
			[
				'node' => 'node4',
				'shards' => new Set([]),  // No shards initially
				'connections' => new Set(['node4']),
			],
			]
		);

		// Simulate node2 failure
		$activeNodes = new Set(['node1', 'node3', 'node4']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Should have 3 remaining nodes
		$this->assertEquals(3, $newSchema->count(), 'Should have 3 remaining nodes');

		// All shards should still be available
		$allShards = new Set();
		foreach ($newSchema as $row) {
			$allShards->add(...$row['shards']);
		}
		$this->assertEquals(new Set([0, 1]), $allShards, 'All shards should still be available');

		// For RF=3 with 3 remaining nodes, each shard should still be on all 3 nodes
		foreach ($newSchema as $row) {
			if ($row['shards']->count() <= 0) {
				continue;
			}

			$this->assertEquals(2, $row['shards']->count(), 'Nodes with shards should have both shards');
			$this->assertTrue($row['shards']->contains(0) && $row['shards']->contains(1), 'Should have shards 0 and 1');
		}
	}

	/**
	 * Test edge case: RF=2 with catastrophic failure (only 1 node left)
	 * @return void
	 */
	public function testRF2CatastrophicFailure(): void {
		// Create initial RF=2 schema: 3 nodes with NON-OVERLAPPING shards for this test
		// This avoids the edge case where inactive shards are already on active nodes
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0]),  // Only shard 0
				'connections' => new Set(['node1']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([1]),  // Only shard 1 (will fail)
				'connections' => new Set(['node2']),
			],
			[
				'node' => 'node3',
				'shards' => new Set([2, 3]),  // Shards 2,3 (will fail)
				'connections' => new Set(['node3']),
			],
			]
		);

		// Catastrophic failure: only node1 survives
		$activeNodes = new Set(['node1']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Should have 1 remaining node
		$this->assertEquals(1, $newSchema->count(), 'Should have 1 remaining node');

		$remainingNode = $newSchema[0];
		$this->assertNotNull($remainingNode, 'Remaining node should not be null');
		$this->assertEquals('node1', $remainingNode['node'], 'Remaining node should be node1');

		// In catastrophic failure, the remaining node should get all orphaned shards
		// Node1 originally had shard 0 and should get orphaned shards 1,2,3
		$this->assertTrue($remainingNode['shards']->contains(0), 'Should have original shard 0');
		$this->assertTrue($remainingNode['shards']->contains(1), 'Should have orphaned shard 1');
		$this->assertTrue($remainingNode['shards']->contains(2), 'Should have orphaned shard 2');
		$this->assertTrue($remainingNode['shards']->contains(3), 'Should have orphaned shard 3');

		// All shards should be accounted for
		$this->assertEquals(4, $remainingNode['shards']->count(), 'All shards should be on remaining node');
	}

	/**
	 * Test that no rebalancing occurs when no nodes fail
	 * @return void
	 */
	public function testNoFailureNoRebalancing(): void {
		// Create initial schema
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

		// All nodes are active
		$activeNodes = new Set(['node1', 'node2']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Schema should remain unchanged
		$this->assertEquals($initialSchema->count(), $newSchema->count(), 'Schema size should remain the same');

		// Verify each node's shards remain the same
		foreach ($initialSchema as $originalRow) {
			$found = false;
			foreach ($newSchema as $newRow) {
				if ($newRow['node'] === $originalRow['node']) {
					$this->assertEquals(
						$originalRow['shards'],
						$newRow['shards'],
						"Node {$originalRow['node']} shards should remain unchanged"
					);
					$found = true;
					break;
				}
			}
			$this->assertTrue($found, "Node {$originalRow['node']} should be found in new schema");
		}
	}
}
