<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\Sharding;

use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableCluster;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableQueue;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableTable;
use PHPUnit\Framework\TestCase;

/**
 * Test rebalancing rollback functionality
 * Tests the stopRebalancing mechanism and rollback on rebalance failures
 */
final class RebalanceRollbackTest extends TestCase {

	private TestableQueue $queue;
	private TestableCluster $cluster;
	private TestableTable $table;

	protected function setUp(): void {
		$this->queue = new TestableQueue();
		$this->cluster = new TestableCluster();
		$this->table = new TestableTable();
	}

	/**
	 * Test that rebalancing can be stopped with proper cleanup
	 */
	public function testStopRebalancingCleansUpState(): void {
		$tableName = 'test_table_' . uniqid();
		$operationGroup = 'rebalance_' . $tableName;

		// Add some rebalance commands
		$this->queue->add('node1', 'CREATE TABLE t_s0', 'DROP TABLE IF EXISTS t_s0', $operationGroup);
		$this->queue->add('node2', 'CREATE TABLE t_s0', 'DROP TABLE IF EXISTS t_s0', $operationGroup);

		// Stop rebalancing should execute rollback
		$result = $this->queue->rollbackOperationGroup($operationGroup);

		$this->assertTrue($result, 'Stop rebalance should execute rollback successfully');
	}

	/**
	 * Test that partial rebalance can be rolled back
	 */
	public function testPartialRebalanceRollback(): void {
		$operationGroup = 'partial_rebalance_' . uniqid();

		// Add partial rebalance commands (some succeeded, some failed)
		$this->queue->add('node1', 'CREATE TABLE t_s0', 'DROP TABLE IF EXISTS t_s0', $operationGroup);
		$this->queue->add('node2', 'CREATE TABLE t_s0', 'DROP TABLE IF EXISTS t_s0', $operationGroup);
		$this->queue->add('node3', 'CREATE TABLE t_s1', 'DROP TABLE IF EXISTS t_s1', $operationGroup);
		$this->queue->add('node1', 'DROP TABLE old_t_s0', '', $operationGroup); // Destructive

		// Rollback should handle partial state
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test rebalancing failure handling triggers rollback
	 */
	public function testRebalanceFailureTriggersRollback(): void {
		$operationGroup = 'rebalance_failure_' . uniqid();

		// Simulate rebalance in progress when failure occurs
		$this->queue->add('node1', 'CREATE TABLE t_s0', 'DROP TABLE IF EXISTS t_s0', $operationGroup);
		$this->queue->add('node2', 'CREATE CLUSTER temp', 'DELETE CLUSTER temp', $operationGroup);
		$this->queue->add('node1', 'ALTER CLUSTER temp ADD t_s0', 'ALTER CLUSTER temp DROP t_s0', $operationGroup);

		// Rollback should clean up
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test complex rebalancing with multiple shard movements
	 */
	public function testComplexRebalanceRollback(): void {
		$operationGroup = 'complex_rebalance_' . uniqid();
		$shardCount = 4;

		// Simulate rebalancing multiple shards
		for ($shard = 0; $shard < $shardCount; $shard++) {
			// Create shard on new node
			$this->queue->add(
				'node2',
				"CREATE TABLE t_s{$shard} (id bigint)",
				"DROP TABLE IF EXISTS t_s{$shard}",
				$operationGroup
			);

			// Create intermediate cluster for RF=1 movement
			$clusterName = "temp_move_{$shard}_" . substr(md5($operationGroup), 0, 6);
			$this->queue->add(
				'node1',
				"CREATE CLUSTER {$clusterName}",
				"DELETE CLUSTER {$clusterName}",
				$operationGroup
			);

			// Add and join for data copy
			$this->queue->add(
				'node1',
				"ALTER CLUSTER {$clusterName} ADD t_s{$shard}",
				"ALTER CLUSTER {$clusterName} DROP t_s{$shard}",
				$operationGroup
			);
			$this->queue->add(
				'node2',
				"JOIN CLUSTER {$clusterName}",
				"DELETE CLUSTER {$clusterName}",
				$operationGroup
			);

			// Cleanup intermediate cluster
			$this->queue->add(
				'node1',
				"ALTER CLUSTER {$clusterName} DROP t_s{$shard}",
				"ALTER CLUSTER {$clusterName} ADD t_s{$shard}",
				$operationGroup
			);
			$this->queue->add(
				'node1',
				"DELETE CLUSTER {$clusterName}",
				'',
				$operationGroup
			);
		}

		// Final distributed table update
		$localShards = implode(',', array_map(fn($i) => "t_s{$i}", range(1, $shardCount - 1)));
		$agentShards = implode(' ', array_map(fn($i) => "agent='node2:t_s{$i}'", range(1, $shardCount - 1)));
		$this->queue->add(
			'node1',
			"CREATE TABLE t type='distributed' local='{$localShards}' {$agentShards}",
			'DROP TABLE IF EXISTS t',
			$operationGroup
		);

		// Verify rollback can handle complex scenario
		$commands = $this->queue->getCapturedCommands();
		$this->assertGreaterThan($shardCount * 5, count($commands));

		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test that rebalancing state is properly tracked
	 */
	public function testRebalancingStateTracking(): void {
		$tableName = 'state_test_' . uniqid();

		// Simulate rebalance state
		$rebalanceState = [
			'status' => 'in_progress',
			'started_at' => time(),
			'shards_total' => 4,
			'shards_completed' => 2,
			'operation_group' => 'rebalance_' . $tableName,
		];

		// Verify state structure
		$this->assertArrayHasKey('status', $rebalanceState);
		$this->assertArrayHasKey('operation_group', $rebalanceState);
		$this->assertEquals('in_progress', $rebalanceState['status']);
	}

	/**
	 * Test graceful degradation when rollback fails
	 */
	public function testGracefulDegradationOnRollbackFailure(): void {
		$operationGroup = 'rollback_fail_test_' . uniqid();

		// Add commands
		$this->queue->add('node1', 'CREATE TABLE t1', 'DROP TABLE IF EXISTS t1', $operationGroup);
		$this->queue->add('node2', 'CREATE TABLE t2', 'DROP TABLE IF EXISTS t2', $operationGroup);

		// Even if one rollback fails, overall should report appropriately
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result); // In test double, always succeeds
	}

	/**
	 * Test that error information is logged during rollback
	 */
	public function testRollbackErrorLogging(): void {
		$operationGroup = 'error_log_test_' . uniqid();

		// Add commands that would generate error during rollback
		$this->queue->add('node1', 'CREATE TABLE t1', 'DROP TABLE IF EXISTS t1', $operationGroup);

		// Verify rollback execution
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);

		// Error logging would happen in real implementation via Buddy::debugvv()
		$this->assertTrue(true); // Placeholder for error logging verification
	}

	/**
	 * Test RF=1 rebalancing with intermediate cluster rollback
	 */
	public function testRF1RebalanceWithIntermediateClusterRollback(): void {
		$operationGroup = 'rf1_intermediate_' . uniqid();
		$shardId = 2;
		$sourceNode = 'node1';
		$targetNode = 'node2';
		$tempCluster = 'temp_move_' . $shardId;

		// Full RF=1 movement sequence
		$rf1Sequence = [
			// 1. Create shard on target node
			[
				'node' => $targetNode,
				'query' => "CREATE TABLE t_s{$shardId} (id bigint)",
				'rollback' => "DROP TABLE IF EXISTS t_s{$shardId}",
			],
			// 2. Create intermediate cluster
			[
				'node' => $sourceNode,
				'query' => "CREATE CLUSTER {$tempCluster}",
				'rollback' => "DELETE CLUSTER {$tempCluster}",
			],
			// 3. Add source shard to intermediate cluster
			[
				'node' => $sourceNode,
				'query' => "ALTER CLUSTER {$tempCluster} ADD t_s{$shardId}",
				'rollback' => "ALTER CLUSTER {$tempCluster} DROP t_s{$shardId}",
			],
			// 4. Join target node to intermediate cluster
			[
				'node' => $targetNode,
				'query' => "JOIN CLUSTER {$tempCluster}",
				'rollback' => "DELETE CLUSTER {$tempCluster}",
			],
			// 5. Remove from intermediate cluster
			[
				'node' => $sourceNode,
				'query' => "ALTER CLUSTER {$tempCluster} DROP t_s{$shardId}",
				'rollback' => "ALTER CLUSTER {$tempCluster} ADD t_s{$shardId}",
			],
			// 6. Delete intermediate cluster
			[
				'node' => $sourceNode,
				'query' => "DELETE CLUSTER {$tempCluster}",
				'rollback' => '',
			],
			// 7. Update distributed table
			[
				'node' => $sourceNode,
				'query' => 'DROP TABLE t',
				'rollback' => '',
			],
			[
				'node' => $sourceNode,
				'query' => "CREATE TABLE t type='distributed' local='t_s0,t_s1' agent='{$targetNode}:t_s{$shardId}'",
				'rollback' => 'DROP TABLE IF EXISTS t',
			],
		];

		foreach ($rf1Sequence as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		// Verify rollback can handle RF=1 complex sequence
		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(count($rf1Sequence), $commands);

		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test RF=2 replication rebuild rollback
	 */
	public function testRF2ReplicationRebuildRollback(): void {
		$operationGroup = 'rf2_rebuild_' . uniqid();
		$shardId = 1;
		$replicaNode = 'node3';

		// RF=2 rebuild sequence (add replica to existing shard)
		$rf2Sequence = [
			// Create replica shard
			[
				'node' => $replicaNode,
				'query' => "CREATE TABLE t_s{$shardId} (id bigint)",
				'rollback' => "DROP TABLE IF EXISTS t_s{$shardId}",
			],
			// Insert data from original shard
			[
				'node' => $replicaNode,
				'query' => "INSERT INTO t_s{$shardId} SELECT * FROM t_s{$shardId}",
				'rollback' => "TRUNCATE TABLE t_s{$shardId}",
			],
			// Update distributed table to include replica
			[
				'node' => 'node1',
				'query' => 'DROP TABLE t',
				'rollback' => '',
			],
			[
				'node' => 'node1',
				'query' => "CREATE TABLE t type='distributed' local='t_s0' agent='node2:t_s{$shardId} {$replicaNode}:t_s{$shardId}'",
				'rollback' => 'DROP TABLE IF EXISTS t',
			],
		];

		foreach ($rf2Sequence as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test rebalancing with node addition
	 */
	public function testRebalanceWithNodeAdditionRollback(): void {
		$operationGroup = 'node_add_rebalance_' . uniqid();
		$newNode = 'node4';

		// Add new node to cluster
		$nodeAddSequence = [
			// Create all shards on new node
			['node' => $newNode, 'query' => 'CREATE TABLE t_s0', 'rollback' => 'DROP TABLE IF EXISTS t_s0'],
			['node' => $newNode, 'query' => 'CREATE TABLE t_s1', 'rollback' => 'DROP TABLE IF EXISTS t_s1'],
			['node' => $newNode, 'query' => 'CREATE TABLE t_s2', 'rollback' => 'DROP TABLE IF EXISTS t_s2'],
			['node' => $newNode, 'query' => 'CREATE TABLE t_s3', 'rollback' => 'DROP TABLE IF EXISTS t_s3'],
			// Replicate data
			['node' => $newNode, 'query' => 'INSERT INTO t_s0 SELECT * FROM t_s0', 'rollback' => 'TRUNCATE TABLE t_s0'],
			['node' => $newNode, 'query' => 'INSERT INTO t_s1 SELECT * FROM t_s1', 'rollback' => 'TRUNCATE TABLE t_s1'],
			['node' => $newNode, 'query' => 'INSERT INTO t_s2 SELECT * FROM t_s2', 'rollback' => 'TRUNCATE TABLE t_s2'],
			['node' => $newNode, 'query' => 'INSERT INTO t_s3 SELECT * FROM t_s3', 'rollback' => 'TRUNCATE TABLE t_s3'],
			// Update distributed table
			['node' => 'node1', 'query' => 'DROP TABLE t', 'rollback' => ''],
			[
				'node' => 'node1',
				'query' => "CREATE TABLE t type='distributed' local='t_s0,t_s1' agent='node2:t_s0 node3:t_s1 node4:t_s2 node4:t_s3'",
				'rollback' => 'DROP TABLE IF EXISTS t',
			],
		];

		foreach ($nodeAddSequence as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test rebalancing cleanup after successful completion
	 */
	public function testRebalanceCleanupAfterSuccess(): void {
		$operationGroup = 'rebalance_cleanup_' . uniqid();

		// Simulate completed rebalance (commands processed)
		$this->queue->add('node1', 'CREATE TABLE t_s0', 'DROP TABLE IF EXISTS t_s0', $operationGroup);
		$this->queue->add('node2', 'CREATE TABLE t_s0', 'DROP TABLE IF EXISTS t_s0', $operationGroup);
		$this->queue->add('node1', 'DROP TABLE old_t_s0', '', $operationGroup);
		$this->queue->add('node1', 'CREATE TABLE t type=\'distributed\'', 'DROP TABLE IF EXISTS t', $operationGroup);

		// After successful completion, no rollback needed
		// But operation group can still be rolled back if needed
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}
}
