<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Set;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Util;
use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase {

	/**
	 * Check and make sure that we able to consistently distribute on the same set of data
	 * @return void
	 */
	public function testCreateShardingSchemaConsistent(): void {
		$nodes = new Set(['1', '2', '3', '4', '5']);
		$shardCount = 60;
		$replicationFactor = 3;
		$schema = Util::createShardingSchema(
			$nodes,
			$shardCount,
			$replicationFactor
		);
		// phpcs:ignore Generic.Files.LineLength.MaxExceeded
		$this->assertEquals('[{"node":"1","shards":[0,1,3,5,8,9,10,12,13,15,16,19,20,22,23,25,28,29,30,31,34,35,38,39,40,42,43,45,46,49,50,52,54,55,58,59],"connections":["4","1","3"]},{"node":"2","shards":[1,2,4,6,7,9,10,11,14,15,17,19,21,23,24,25,26,28,31,32,34,35,37,39,40,41,43,46,47,48,51,52,53,55,56,58],"connections":["1","5","2"]},{"node":"3","shards":[0,2,4,5,7,8,11,12,13,16,17,18,20,22,24,26,27,28,31,32,33,36,37,38,40,42,44,46,48,49,50,53,54,55,57,59],"connections":["4","1","3"]},{"node":"4","shards":[1,2,3,5,6,8,11,12,14,16,18,19,20,21,23,25,27,29,30,32,33,35,36,39,41,42,44,45,47,49,51,52,53,56,57,59],"connections":["4","1","3"]},{"node":"5","shards":[0,3,4,6,7,9,10,13,14,15,17,18,21,22,24,26,27,29,30,33,34,36,37,38,41,43,44,45,47,48,50,51,54,56,57,58],"connections":["1","5","2"]}]', json_encode($schema));
	}

	/**
	 * Check the we distribute in balanced way and there is no maximum or minimum in nodes
	 * @return void
	 */
	public function testCreateShardingSchemaBalanced(): void {
		$nodes = new Set(['1', '2', '3', '4', '5']);
		$shardCount = 60;
		$replicationFactor = 3;
		$schema = Util::createShardingSchema(
			$nodes,
			$shardCount,
			$replicationFactor
		);
		$this->assertEquals($nodes->count(), sizeof($schema));
		foreach ($schema as $node) {
			$this->assertEquals(36, sizeof($node['shards']));
			$this->assertEquals(3, sizeof($node['connections']));
		}
	}

	/**
	 * Test rebalancing with RF=1 when adding new nodes - should NOT move shards
	 */
	// phpcs:ignore SlevomatCodingStandard.Complexity.Cognitive.ComplexityTooHigh
	public function testRebalanceWithRf1AddingNodes(): void {
		// Create original schema with RF=1: 6 shards on 2 nodes
		$originalNodes = new Set(['node1', 'node2']);
		$shardCount = 6;
		$replicationFactor = 1;

		$originalSchema = Util::createShardingSchema($originalNodes, $shardCount, $replicationFactor);

		// Verify original schema has RF=1 (each shard appears only once)
		$shardCounts = [];
		foreach ($originalSchema as $row) {
			foreach ($row['shards'] as $shard) {
				$shardCounts[$shard] = ($shardCounts[$shard] ?? 0) + 1;
			}
		}
		foreach ($shardCounts as $count) {
			$this->assertEquals(1, $count, 'Each shard should appear exactly once in RF=1');
		}

		// Store original shard distribution
		$originalDistribution = [];
		foreach ($originalSchema as $row) {
			$originalDistribution[$row['node']] = $row['shards']->toArray();
		}

		// Add a new node
		$newNodes = new Set(['node1', 'node2', 'node3']);
		$rebalanced = Util::rebalanceShardingScheme($originalSchema, $newNodes);

		// Should have 3 nodes now (including the new one)
		$this->assertCount(3, $rebalanced);

		// Find the new node
		$newNodeRow = null;
		foreach ($rebalanced as $row) {
			if ($row['node'] === 'node3') {
				$newNodeRow = $row;
				break;
			}
		}
		$this->assertNotNull($newNodeRow, 'New node should be present in rebalanced schema');

		// CRITICAL: New node should have NO shards (RF=1 prevents data movement)
		$this->assertTrue(
			$newNodeRow['shards']->isEmpty(),
			'New node should have no shards with RF=1 to prevent data loss'
		);

		// CRITICAL: Existing nodes should keep their EXACT original shards (no movement)
		foreach ($originalSchema as $originalRow) {
			$rebalancedRow = null;
			foreach ($rebalanced as $row) {
				if ($row['node'] === $originalRow['node']) {
					$rebalancedRow = $row;
					break;
				}
			}
			$this->assertNotNull($rebalancedRow);
			$this->assertEquals(
				$originalRow['shards']->toArray(),
				$rebalancedRow['shards']->toArray(),
				"Node {$originalRow['node']} should keep its EXACT original"
					. 'shards with RF=1 - moving shards would cause data loss!'
			);
		}

		// Verify all original shards are still present
		$allRebalancedShards = new Set();
		foreach ($rebalanced as $row) {
			$allRebalancedShards->add(...$row['shards']);
		}
		$this->assertEquals($shardCount, $allRebalancedShards->count(), 'All original shards must be preserved');
	}

	/**
	 * Test rebalancing with RF=2 when adding new nodes - should safely rebalance
	 */
	public function testRebalanceWithRf2AddingNodes(): void {
		// Create original schema with RF=2: 2 shards replicated on 2 nodes
		$originalNodes = new Set(['node1', 'node2']);
		$shardCount = 2;
		$replicationFactor = 2;

		$originalSchema = Util::createShardingSchema($originalNodes, $shardCount, $replicationFactor);

		// Verify original schema has RF=2 (each shard appears twice)
		$shardCounts = [];
		foreach ($originalSchema as $row) {
			foreach ($row['shards'] as $shard) {
				$shardCounts[$shard] = ($shardCounts[$shard] ?? 0) + 1;
			}
		}
		foreach ($shardCounts as $count) {
			$this->assertEquals(2, $count, 'Each shard should appear exactly twice in RF=2');
		}

		// Add a new node
		$newNodes = new Set(['node1', 'node2', 'node3']);
		$rebalanced = Util::rebalanceShardingScheme($originalSchema, $newNodes);

		// Should have 3 nodes now
		$this->assertCount(3, $rebalanced);

		// Verify RF=2 is maintained in rebalanced schema
		$newShardCounts = [];
		foreach ($rebalanced as $row) {
			foreach ($row['shards'] as $shard) {
				$newShardCounts[$shard] = ($newShardCounts[$shard] ?? 0) + 1;
			}
		}
		foreach ($newShardCounts as $count) {
			$this->assertEquals(2, $count, 'RF=2 should be maintained after rebalancing');
		}

		// New node should have some shards (rebalancing occurred)
		$newNodeRow = null;
		foreach ($rebalanced as $row) {
			if ($row['node'] === 'node3') {
				$newNodeRow = $row;
				break;
			}
		}
		$this->assertNotNull($newNodeRow);
		$this->assertFalse($newNodeRow['shards']->isEmpty(), 'New node should have shards with RF=2 rebalancing');
	}

	public function testRebalanceShardingSchema(): void {
		//
		// CASE 1
		// 3 nodes, 2 shards, rf=1:
		// – originally one shard on “1”, one on “2”, “3” is unused
		// – simulate killing node “2”
		// – expect that shard 1 moves onto the previously unused node “3”
		//
		$nodes       = new Set(['1', '2', '3']);
		$shardCount  = 2;
		$replicaFactor = 1;

		// build the original schema
		$originalSchema = Util::createShardingSchema($nodes, $shardCount, $replicaFactor);
		// original should be:
		//   node "1" → [0]
		//   node "2" → []
		//   node "3" → [1]
		$this->assertEquals(
			['1','2','3'], $originalSchema
			->map(fn($r)=>$r['node'])
			->toArray()
		);
		$this->assertEquals([0], $originalSchema->get(0)['shards']->toArray());
		$this->assertEquals([], $originalSchema->get(1)['shards']->toArray());
		$this->assertEquals([1], $originalSchema->get(2)['shards']->toArray());

		// now kill node "2"
		$active = new Set(['1','2']);
		$rebalanced = Util::rebalanceShardingScheme($originalSchema, $active);

		// after rebalance we should have exactly 2 rows (only active nodes)
		$this->assertCount(2, $rebalanced);

		// in the filtered vector the order is the same as in the original schema,
		// so get(0) is node "1" and get(1) is node "2"
		$this->assertSame('1', $rebalanced->get(0)['node']);
		$this->assertEquals([0], $rebalanced->get(0)['shards']->toArray());

		$this->assertSame('2', $rebalanced->get(1)['node']);

		// node "2" was unused before
		// it cannot pick up shard 1 because it on dead node while its rf=1
		$this->assertEquals([], $rebalanced->get(1)['shards']->toArray());

		//
		// CASE 2
		// no nodes down → rebalance should leave the schema untouched
		//
		$fullRebalanced = Util::rebalanceShardingScheme($originalSchema, $nodes);

		// should still have 3 entries
		$this->assertCount(3, $fullRebalanced);

		// and all shards should be exactly the same as original
		foreach ($fullRebalanced as $idx => $row) {
			$this->assertSame(
				$originalSchema->get($idx)['node'],
				$row['node'],
				"node at index $idx must remain the same"
			);
			$this->assertEquals(
				$originalSchema->get($idx)['shards']->toArray(),
				$row['shards']->toArray(),
				"shards on node {$row['node']} must remain the same"
			);
		}
	}
}
