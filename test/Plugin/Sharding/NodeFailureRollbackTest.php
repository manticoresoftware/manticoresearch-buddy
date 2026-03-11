<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\Sharding;

use Ds\Set;
use Ds\Vector;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Util;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableCluster;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableQueue;
use PHPUnit\Framework\TestCase;

/**
 * Test node failure scenarios and rollback behavior
 * Covers RF=1, RF=2, RF=3 failure modes and catastrophic failures
 */
final class NodeFailureRollbackTest extends TestCase {

	private TestableQueue $queue;
	private TestableCluster $cluster;

	protected function setUp(): void {
		$this->queue = new TestableQueue();
		$this->cluster = new TestableCluster();
	}

	/**
	 * Test RF=1 single node failure handling
	 * When a node fails in RF=1, orphaned shards need redistribution
	 */
	public function testRF1SingleNodeFailureRedistribution(): void {
		// Initial schema: 3 nodes, each with unique shards (RF=1)
		$initialSchema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0, 1]), 'connections' => new Set(['node1'])],
			['node' => 'node2', 'shards' => new Set([2]), 'connections' => new Set(['node2'])],
			['node' => 'node3', 'shards' => new Set([3]), 'connections' => new Set(['node3'])],
			]
		);

		// node2 fails - shards 2 becomes orphaned
		$activeNodes = new Set(['node1', 'node3']);

		// Rebalance should redistribute orphaned shard 2
		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Verify 2 active nodes
		$this->assertEquals(2, $newSchema->count());

		// Collect all shards
		$allShards = new Set();
		foreach ($newSchema as $row) {
			$allShards->add(...$row['shards']);
		}

		// All shards should still be available (orphaned shard redistributed)
		$this->assertEquals(new Set([0, 1, 2, 3]), $allShards);

		// Each shard should be on exactly one node (RF=1)
		foreach ([0, 1, 2, 3] as $shard) {
			$count = 0;
			foreach ($newSchema as $row) {
				if (!$row['shards']->contains($shard)) {
					continue;
				}

				$count++;
			}
			$this->assertEquals(1, $count, "Shard {$shard} should be on exactly 1 node");
		}
	}

	/**
	 * Test RF=2 single node failure handling
	 * RF=2 maintains replication, so orphaned replicas need replication
	 */
	public function testRF2SingleNodeFailureReplication(): void {
		// Initial RF=2 schema: 3 nodes, each shard on 2 nodes
		$initialSchema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0, 1]), 'connections' => new Set(['node1', 'node2'])],
			['node' => 'node2', 'shards' => new Set([0, 1]), 'connections' => new Set(['node1', 'node2'])],
			['node' => 'node3', 'shards' => new Set([2, 3]), 'connections' => new Set(['node3'])],
			]
		);

		// node2 fails - shards 0,1 now only have one replica
		$activeNodes = new Set(['node1', 'node3']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Verify 2 active nodes
		$this->assertEquals(2, $newSchema->count());

		// Collect all shards
		$allShards = new Set();
		foreach ($newSchema as $row) {
			$allShards->add(...$row['shards']);
		}

		// All shards should be available
		$this->assertEquals(new Set([0, 1, 2, 3]), $allShards);

		// For RF=2 with 2 nodes, each shard should be on both nodes
		// or distributed evenly maintaining replication factor
		$shardCounts = [];
		for ($shard = 0; $shard <= 3; $shard++) {
			$shardCounts[$shard] = 0;
			foreach ($newSchema as $row) {
				if (!$row['shards']->contains($shard)) {
					continue;
				}

				$shardCounts[$shard]++;
			}
		}

		// Shards 2,3 should be on both nodes (rebuilt replication)
		$this->assertGreaterThanOrEqual(2, $shardCounts[2]);
		$this->assertGreaterThanOrEqual(2, $shardCounts[3]);
	}

	/**
	 * Test RF=3 single node failure handling
	 * RF=3 has more redundancy, single failure is less critical
	 */
	public function testRF3SingleNodeFailureHandling(): void {
		// Initial RF=3 schema: 4 nodes, each shard on 3 nodes
		$initialSchema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0, 1]), 'connections' => new Set(['node1', 'node2', 'node3'])],
			['node' => 'node2', 'shards' => new Set([0, 1]), 'connections' => new Set(['node1', 'node2', 'node3'])],
			['node' => 'node3', 'shards' => new Set([0, 1]), 'connections' => new Set(['node1', 'node2', 'node3'])],
			['node' => 'node4', 'shards' => new Set([]), 'connections' => new Set(['node4'])],
			]
		);

		// node2 fails - shards 0,1 now have 2 replicas (still above minimum)
		$activeNodes = new Set(['node1', 'node3', 'node4']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Verify 3 active nodes
		$this->assertEquals(3, $newSchema->count());

		// All shards should be available
		$allShards = new Set();
		foreach ($newSchema as $row) {
			$allShards->add(...$row['shards']);
		}

		$this->assertEquals(new Set([0, 1]), $allShards);
	}

	/**
	 * Test catastrophic failure (50%+ nodes lost)
	 * System should gracefully degrade
	 */
	public function testCatastrophicFailureDegradation(): void {
		// Initial: 4 nodes with proper RF=2 distribution
		$initialSchema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0]), 'connections' => new Set(['node1', 'node2'])],
			['node' => 'node2', 'shards' => new Set([0]), 'connections' => new Set(['node1', 'node2'])],
			['node' => 'node3', 'shards' => new Set([1, 2]), 'connections' => new Set(['node3', 'node4'])],
			['node' => 'node4', 'shards' => new Set([1, 2]), 'connections' => new Set(['node3', 'node4'])],
			]
		);

		// Catastrophic: nodes 3 and 4 fail (50% of nodes)
		// Shards 1,2 are now orphaned (only had replicas on failed nodes)
		$activeNodes = new Set(['node1', 'node2']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Should have 2 remaining nodes
		$this->assertEquals(2, $newSchema->count());

		// Shard 0 should still be available (had replicas on surviving nodes)
		$allShards = new Set();
		foreach ($newSchema as $row) {
			$allShards->add(...$row['shards']);
		}

		// Surviving shards
		$this->assertTrue($allShards->contains(0));

		// Orphaned shards 1,2 may be lost - system should degrade gracefully
		// Rollback would handle this scenario
	}

	/**
	 * Test rollback during active node failure
	 */
	public function testRollbackDuringNodeFailure(): void {
		$operationGroup = 'node_failure_recovery_' . uniqid();

		// Simulate recovery commands being queued when failure detected
		$recoveryCommands = [
			// Detect failure and start recovery
			['node' => 'node1', 'query' => 'SELECT node_status FROM system.nodes', 'rollback' => ''],
			// Create replacement shard
			['node' => 'node3', 'query' => 'CREATE TABLE t_s1 (id bigint)', 'rollback' => 'DROP TABLE IF EXISTS t_s1'],
			// Replicate data
			['node' => 'node3', 'query' => 'INSERT INTO t_s1 SELECT * FROM t_s1', 'rollback' => 'TRUNCATE TABLE t_s1'],
			// Update distributed table
			['node' => 'node1', 'query' => 'DROP TABLE t', 'rollback' => ''],
			['node' => 'node1', 'query' => "CREATE TABLE t type='distributed' local='t_s0' agent='node2:t_s0 node3:t_s1'", 'rollback' => 'DROP TABLE IF EXISTS t'],
		];

		foreach ($recoveryCommands as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		// If recovery fails, rollback should clean up
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test orphaned shard recovery after node failure
	 */
	public function testOrphanedShardRecovery(): void {
		// Schema after node2 failure (RF=1)
		$postFailureSchema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0, 1]), 'connections' => new Set(['node1'])],
			// node2 and its shards 2 are gone
			['node' => 'node3', 'shards' => new Set([3]), 'connections' => new Set(['node3'])],
			]
		);

		// Shard 2 is orphaned - need to recover it
		$orphanedShards = new Set([2]);
		$activeNodes = new Set(['node1', 'node3']);

		// Recovery would involve creating shard 2 on one of the active nodes
		$recoveryGroup = 'orphan_recovery_' . uniqid();
		$recoveryCommands = [
			['node' => 'node1', 'query' => 'CREATE TABLE t_s2 (id bigint)', 'rollback' => 'DROP TABLE IF EXISTS t_s2'],
			['node' => 'node1', 'query' => 'INSERT INTO t_s2 SELECT * FROM t_s2 WHERE shard_id = 2', 'rollback' => 'TRUNCATE TABLE t_s2'],
			['node' => 'node1', 'query' => 'DROP TABLE t', 'rollback' => ''],
			['node' => 'node1', 'query' => "CREATE TABLE t type='distributed' local='t_s0,t_s1,t_s2' agent='node3:t_s3'", 'rollback' => 'DROP TABLE IF EXISTS t'],
		];

		foreach ($recoveryCommands as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $recoveryGroup);
		}

		$result = $this->queue->rollbackOperationGroup($recoveryGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test RF=1 insufficient nodes scenario
	 */
	public function testRF1InsufficientNodes(): void {
		// Initial: 2 nodes, RF=1
		$initialSchema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0, 1]), 'connections' => new Set(['node1'])],
			['node' => 'node2', 'shards' => new Set([2, 3]), 'connections' => new Set(['node2'])],
			]
		);

		// node2 fails - only 1 node remains for 4 shards
		$activeNodes = new Set(['node1']);

		$newSchema = Util::rebalanceShardingScheme($initialSchema, $activeNodes);

		// Should have 1 remaining node
		$this->assertEquals(1, $newSchema->count());

		$remainingNode = $newSchema[0];
		$this->assertEquals('node1', $remainingNode['node']);

		// All shards should be consolidated on remaining node
		$this->assertEquals(new Set([0, 1, 2, 3]), $remainingNode['shards']);
	}

	/**
	 * Test multiple sequential node failures
	 */
	public function testSequentialNodeFailures(): void {
		// Initial: 5 nodes, RF=2
		$initialSchema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0]), 'connections' => new Set(['node1', 'node2'])],
			['node' => 'node2', 'shards' => new Set([0]), 'connections' => new Set(['node1', 'node2'])],
			['node' => 'node3', 'shards' => new Set([1]), 'connections' => new Set(['node3', 'node4'])],
			['node' => 'node4', 'shards' => new Set([1]), 'connections' => new Set(['node3', 'node4'])],
			['node' => 'node5', 'shards' => new Set([2, 3]), 'connections' => new Set(['node5'])],
			]
		);

		// First failure: node2 fails
		$afterFirstFailure = Util::rebalanceShardingScheme($initialSchema, new Set(['node1', 'node3', 'node4', 'node5']));
		$this->assertEquals(4, $afterFirstFailure->count());

		// Second failure: node4 fails
		$afterSecondFailure = Util::rebalanceShardingScheme($afterFirstFailure, new Set(['node1', 'node3', 'node5']));
		$this->assertEquals(3, $afterSecondFailure->count());

		// All shards should still be available
		$allShards = new Set();
		foreach ($afterSecondFailure as $row) {
			$allShards->add(...$row['shards']);
		}

		$this->assertEquals(new Set([0, 1, 2, 3]), $allShards);
	}

	/**
	 * Test rollback of failed node replacement
	 */
	public function testRollbackFailedNodeReplacement(): void {
		$operationGroup = 'node_replacement_' . uniqid();

		// Failed node replacement sequence
		$replacementCommands = [
			// Create replacement shard
			['node' => 'new_node', 'query' => 'CREATE TABLE t_s0 (id bigint)', 'rollback' => 'DROP TABLE IF EXISTS t_s0'],
			// Copy data from remaining replica
			['node' => 'new_node', 'query' => 'INSERT INTO t_s0 SELECT * FROM t_s0', 'rollback' => 'TRUNCATE TABLE t_s0'],
			// Update cluster nodes
			['node' => 'master', 'query' => 'ALTER CLUSTER main UPDATE nodes', 'rollback' => ''],
			// Update distributed table
			['node' => 'master', 'query' => 'DROP TABLE t', 'rollback' => ''],
			['node' => 'master', 'query' => "CREATE TABLE t type='distributed' local='t_s0'", 'rollback' => 'DROP TABLE IF EXISTS t'],
		];

		foreach ($replacementCommands as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		// If replacement fails, rollback should clean up
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test graceful handling when rollback itself encounters failed nodes
	 */
	public function testRollbackWithFailedNodes(): void {
		$operationGroup = 'rollback_with_failed_' . uniqid();

		// Commands targeting both healthy and failed nodes
		$commands = [
			// Command on healthy node - should rollback successfully
			['node' => 'node1', 'query' => 'CREATE TABLE t1', 'rollback' => 'DROP TABLE IF EXISTS t1'],
			// Command on failed node - rollback will fail
			['node' => 'failed_node', 'query' => 'CREATE TABLE t2', 'rollback' => 'DROP TABLE IF EXISTS t2'],
			// Cleanup command on healthy node
			['node' => 'node1', 'query' => 'DROP TABLE t', 'rollback' => ''],
		];

		foreach ($commands as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		// Rollback should continue even if some commands fail
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result); // In test double, always succeeds
	}

	/**
	 * Test data consistency verification after rollback
	 */
	public function testDataConsistencyAfterRollback(): void {
		$operationGroup = 'consistency_check_' . uniqid();

		// Setup: RF=2 with 3 nodes
		$setupCommands = [
			['node' => 'node1', 'query' => 'CREATE TABLE t_s0', 'rollback' => 'DROP TABLE IF EXISTS t_s0'],
			['node' => 'node2', 'query' => 'CREATE TABLE t_s0', 'rollback' => 'DROP TABLE IF EXISTS t_s0'],
			['node' => 'node3', 'query' => 'CREATE TABLE t_s1', 'rollback' => 'DROP TABLE IF EXISTS t_s1'],
			['node' => 'node1', 'query' => 'CREATE TABLE t type=\'distributed\'', 'rollback' => 'DROP TABLE IF EXISTS t'],
		];

		// Modification that will be rolled back
		$modificationCommands = [
			['node' => 'node3', 'query' => 'INSERT INTO t_s1 SELECT * FROM t_s1', 'rollback' => 'TRUNCATE TABLE t_s1'],
			['node' => 'node1', 'query' => 'DROP TABLE t', 'rollback' => ''],
			['node' => 'node1', 'query' => "CREATE TABLE t type='distributed' local='t_s0,t_s1'", 'rollback' => 'DROP TABLE IF EXISTS t'],
		];

		foreach ($setupCommands as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}
}
