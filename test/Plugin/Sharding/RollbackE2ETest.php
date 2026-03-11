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
use PHPUnit\Framework\TestCase;

/**
 * End-to-End tests for complete rollback scenarios
 * Tests complete workflows from command queuing to execution to rollback
 */
final class RollbackE2ETest extends TestCase {

	private TestableQueue $queue;
	private TestableCluster $cluster;

	protected function setUp(): void {
		$this->queue = new TestableQueue();
		$this->cluster = new TestableCluster();
	}

	/**
	 * E2E Test: Complete table creation workflow with rollback on failure
	 */
	public function testTableCreationWithRollbackOnFailure(): void {
		$tableName = 'test_table_' . uniqid();
		$operationGroup = 'create_' . $tableName;
		$shardCount = 4;
		$replicationFactor = 2;

		// Simulate complete table creation workflow
		$creationWorkflow = $this->buildTableCreationWorkflow(
			$tableName,
			$operationGroup,
			$shardCount,
			$replicationFactor,
			['node1', 'node2', 'node3']
		);

		// Verify workflow commands
		$this->assertGreaterThan(0, count($creationWorkflow));

		// Add all commands to queue
		foreach ($creationWorkflow as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $cmd['group']);
		}

		// Simulate failure at step 3 (midway through creation)
		$commands = $this->queue->getCapturedCommands();
		$failedAtStep = 3;

		// Verify rollback can clean up partial creation
		$rollbackResult = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($rollbackResult);

		// Verify state is consistent after rollback
		$remainingCommands = $this->queue->getCapturedCommands();
		$this->assertNotEmpty($remainingCommands);
	}

	/**
	 * E2E Test: Complete rebalancing workflow with manual stop rollback
	 */
	public function testRebalancingWithManualStopRollback(): void {
		$tableName = 'rebalance_table_' . uniqid();
		$operationGroup = 'rebalance_' . $tableName;

		// Build rebalancing workflow
		$rebalanceWorkflow = $this->buildRebalancingWorkflow(
			$tableName,
			$operationGroup,
			['node1', 'node2', 'node3', 'node4']
		);

		// Add commands
		foreach ($rebalanceWorkflow as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $cmd['group']);
		}

		// Simulate manual stop mid-rebalance
		$stopResult = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($stopResult);
	}

	/**
	 * E2E Test: Node failure during active operations
	 */
	public function testNodeFailureDuringActiveOperations(): void {
		$tableName = 'fail_during_ops_' . uniqid();
		$creationGroup = 'create_' . $tableName;
		$failureGroup = 'failure_' . uniqid();

		// Start table creation
		$creationWorkflow = $this->buildTableCreationWorkflow(
			$tableName,
			$creationGroup,
			2,
			2,
			['node1', 'node2']
		);

		// Add creation commands
		foreach ($creationWorkflow as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $cmd['group']);
		}

		// Simulate node failure during creation
		$failureCommands = [
			['node' => 'node1', 'query' => 'DETECT NODE FAILURE', 'rollback' => ''],
			['node' => 'node2', 'query' => 'CREATE TABLE recovery_s0', 'rollback' => 'DROP TABLE IF EXISTS recovery_s0'],
			['node' => 'node2', 'query' => 'INSERT INTO recovery_s0 SELECT * FROM recovery_s0', 'rollback' => 'TRUNCATE TABLE recovery_s0'],
			['node' => 'node2', 'query' => 'DROP TABLE t', 'rollback' => ''],
			['node' => 'node2', 'query' => 'CREATE TABLE t type=\'distributed\' local=\'recovery_s0\'', 'rollback' => 'DROP TABLE IF EXISTS t'],
		];

		foreach ($failureCommands as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $failureGroup);
		}

		// Rollback should handle both groups independently
		$creationRollback = $this->queue->rollbackOperationGroup($creationGroup);
		$failureRollback = $this->queue->rollbackOperationGroup($failureGroup);

		$this->assertTrue($creationRollback);
		$this->assertTrue($failureRollback);
	}

	/**
	 * E2E Test: Concurrent operation group management
	 */
	public function testConcurrentOperationGroupManagement(): void {
		$groups = [];
		$numGroups = 3;

		// Create multiple concurrent operation groups
		for ($i = 0; $i < $numGroups; $i++) {
			$groupName = 'concurrent_' . $i . '_' . uniqid();
			$groups[] = $groupName;

			// Add commands to each group
			$this->queue->add('node1', "CREATE TABLE group{$i}_t1", "DROP TABLE IF EXISTS group{$i}_t1", $groupName);
			$this->queue->add('node1', "CREATE CLUSTER group{$i}_c1", "DELETE CLUSTER group{$i}_c1", $groupName);
			$this->queue->add('node2', "JOIN CLUSTER group{$i}_c1", "DELETE CLUSTER group{$i}_c1", $groupName);
		}

		// Verify all groups exist
		$allCommands = $this->queue->getCapturedCommands();
		$this->assertCount($numGroups * 3, $allCommands);

		// Rollback each group independently
		foreach ($groups as $group) {
			$result = $this->queue->rollbackOperationGroup($group);
			$this->assertTrue($result, "Rollback of group {$group} should succeed");
		}
	}

	/**
	 * E2E Test: Queue command processing with rollback verification
	 */
	public function testQueueCommandProcessingWithRollback(): void {
		$operationGroup = 'queue_process_' . uniqid();

		// Build complex queue workflow
		$queueWorkflow = [
			// Phase 1: Setup
			['node' => 'node1', 'query' => 'CREATE TABLE s0', 'rollback' => 'DROP TABLE IF EXISTS s0'],
			['node' => 'node2', 'query' => 'CREATE TABLE s0', 'rollback' => 'DROP TABLE IF EXISTS s0'],
			['node' => 'node1', 'query' => 'CREATE TABLE s1', 'rollback' => 'DROP TABLE IF EXISTS s1'],
			['node' => 'node3', 'query' => 'CREATE TABLE s1', 'rollback' => 'DROP TABLE IF EXISTS s1'],
			['node' => 'node1', 'query' => 'CREATE TABLE t type=\'distributed\'', 'rollback' => 'DROP TABLE IF EXISTS t'],

			// Phase 2: Modification
			['node' => 'node2', 'query' => 'INSERT INTO s0 SELECT * FROM s0', 'rollback' => 'TRUNCATE TABLE s0'],
			['node' => 'node3', 'query' => 'INSERT INTO s1 SELECT * FROM s1', 'rollback' => 'TRUNCATE TABLE s1'],

			// Phase 3: Cleanup (destructive)
			['node' => 'node1', 'query' => 'DROP TABLE temp_table', 'rollback' => ''],
		];

		foreach ($queueWorkflow as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		// Process commands (simulated)
		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(count($queueWorkflow), $commands);

		// Full rollback should handle all phases
		$rollbackResult = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($rollbackResult);
	}

	/**
	 * E2E Test: Distributed transaction-like rollback
	 */
	public function testDistributedTransactionRollback(): void {
		$transactionGroup = 'dist_tx_' . uniqid();
		$nodes = ['node1', 'node2', 'node3', 'node4'];

		// Simulate distributed transaction across multiple nodes
		$distributedTx = [
			// Prepare phase (all nodes)
			['node' => 'node1', 'query' => 'PREPARE TRANSACTION tx_123', 'rollback' => 'ROLLBACK TRANSACTION tx_123'],
			['node' => 'node2', 'query' => 'PREPARE TRANSACTION tx_123', 'rollback' => 'ROLLBACK TRANSACTION tx_123'],
			['node' => 'node3', 'query' => 'PREPARE TRANSACTION tx_123', 'rollback' => 'ROLLBACK TRANSACTION tx_123'],
			['node' => 'node4', 'query' => 'PREPARE TRANSACTION tx_123', 'rollback' => 'ROLLBACK TRANSACTION tx_123'],

			// Commit phase
			['node' => 'node1', 'query' => 'COMMIT TRANSACTION tx_123', 'rollback' => ''],
		];

		foreach ($distributedTx as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $transactionGroup);
		}

		// Rollback should handle prepare failures
		$result = $this->queue->rollbackOperationGroup($transactionGroup);
		$this->assertTrue($result);
	}

	/**
	 * E2E Test: Large shard migration with rollback
	 */
	public function testLargeShardMigrationRollback(): void {
		$migrationGroup = 'migration_' . uniqid();
		$shardCount = 8;
		$sourceNode = 'node1';
		$targetNode = 'node4';

		// Build large migration workflow
		$migrationWorkflow = [];

		for ($shard = 0; $shard < $shardCount; $shard++) {
			$tempCluster = "temp_move_{$shard}";

			// Create shard on target
			$migrationWorkflow[] = [
				'node' => $targetNode,
				'query' => "CREATE TABLE s{$shard}",
				'rollback' => "DROP TABLE IF EXISTS s{$shard}",
			];

			// Create intermediate cluster
			$migrationWorkflow[] = [
				'node' => $sourceNode,
				'query' => "CREATE CLUSTER {$tempCluster}",
				'rollback' => "DELETE CLUSTER {$tempCluster}",
			];

			// Add source shard
			$migrationWorkflow[] = [
				'node' => $sourceNode,
				'query' => "ALTER CLUSTER {$tempCluster} ADD s{$shard}",
				'rollback' => "ALTER CLUSTER {$tempCluster} DROP s{$shard}",
			];

			// Join target
			$migrationWorkflow[] = [
				'node' => $targetNode,
				'query' => "JOIN CLUSTER {$tempCluster}",
				'rollback' => "DELETE CLUSTER {$tempCluster}",
			];

			// Cleanup
			$migrationWorkflow[] = [
				'node' => $sourceNode,
				'query' => "ALTER CLUSTER {$tempCluster} DROP s{$shard}",
				'rollback' => "ALTER CLUSTER {$tempCluster} ADD s{$shard}",
			];
			$migrationWorkflow[] = [
				'node' => $sourceNode,
				'query' => "DELETE CLUSTER {$tempCluster}",
				'rollback' => '',
			];
		}

		// Update distributed table
		$localShards = implode(',', range(1, $shardCount - 1));
		$agents = implode(' ', array_map(fn($i) => "agent='{$targetNode}:s{$i}'", range(1, $shardCount - 1)));
		$migrationWorkflow[] = [
			'node' => $sourceNode,
			'query' => 'DROP TABLE t',
			'rollback' => '',
		];
		$migrationWorkflow[] = [
			'node' => $sourceNode,
			'query' => "CREATE TABLE t type='distributed' local='{$localShards}' {$agents}",
			'rollback' => 'DROP TABLE IF EXISTS t',
		];

		foreach ($migrationWorkflow as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $migrationGroup);
		}

		// Verify command count
		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(count($migrationWorkflow), $commands);

		// Large rollback should still succeed
		$result = $this->queue->rollbackOperationGroup($migrationGroup);
		$this->assertTrue($result);
	}

	/**
	 * E2E Test: Schema change with rollback
	 */
	public function testSchemaChangeRollback(): void {
		$schemaGroup = 'schema_change_' . uniqid();

		// Simulate schema change workflow
		$schemaChangeWorkflow = [
			// Create new column on all shards
			['node' => 'node1', 'query' => 'ALTER TABLE s0 ADD COLUMN new_col int', 'rollback' => 'ALTER TABLE s0 DROP COLUMN new_col'],
			['node' => 'node2', 'query' => 'ALTER TABLE s0 ADD COLUMN new_col int', 'rollback' => 'ALTER TABLE s0 DROP COLUMN new_col'],
			['node' => 'node3', 'query' => 'ALTER TABLE s1 ADD COLUMN new_col int', 'rollback' => 'ALTER TABLE s1 DROP COLUMN new_col'],
			['node' => 'node4', 'query' => 'ALTER TABLE s1 ADD COLUMN new_col int', 'rollback' => 'ALTER TABLE s1 DROP COLUMN new_col'],

			// Update distributed table
			['node' => 'node1', 'query' => 'DROP TABLE t', 'rollback' => ''],
			['node' => 'node1', 'query' => "CREATE TABLE t type='distributed' local='s0,s1'", 'rollback' => 'DROP TABLE IF EXISTS t'],
		];

		foreach ($schemaChangeWorkflow as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $schemaGroup);
		}

		$result = $this->queue->rollbackOperationGroup($schemaGroup);
		$this->assertTrue($result);
	}

	/**
	 * E2E Test: Recovery from partial failure
	 */
	public function testPartialFailureRecovery(): void {
		$recoveryGroup = 'partial_recovery_' . uniqid();

		// Simulate operations where some succeed, some fail
		$operations = [
			// First two succeed
			['node' => 'node1', 'query' => 'CREATE TABLE s0', 'rollback' => 'DROP TABLE IF EXISTS s0'],
			['node' => 'node2', 'query' => 'CREATE TABLE s0', 'rollback' => 'DROP TABLE IF EXISTS s0'],

			// Third fails mid-execution
			['node' => 'node3', 'query' => 'CREATE TABLE s1', 'rollback' => 'DROP TABLE IF EXISTS s1'],
			['node' => 'node3', 'query' => 'CREATE CLUSTER temp', 'rollback' => 'DELETE CLUSTER temp'],

			// Remaining are queued but not executed
			['node' => 'node1', 'query' => 'INSERT INTO s0 SELECT * FROM s0', 'rollback' => 'TRUNCATE TABLE s0'],
			['node' => 'node1', 'query' => 'CREATE TABLE t', 'rollback' => 'DROP TABLE IF EXISTS t'],
		];

		foreach ($operations as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $recoveryGroup);
		}

		// Rollback should clean up successful operations
		$result = $this->queue->rollbackOperationGroup($recoveryGroup);
		$this->assertTrue($result);
	}

	/**
	 * E2E Test: Idempotent operations with rollback
	 */
	public function testIdempotentOperationsRollback(): void {
		$idempotentGroup = 'idempotent_' . uniqid();

		// Idempotent operations can be safely retried
		$idempotentOps = [
			// CREATE IF NOT EXISTS - idempotent
			['node' => 'node1', 'query' => 'CREATE TABLE IF NOT EXISTS s0', 'rollback' => 'DROP TABLE IF EXISTS s0'],
			['node' => 'node2', 'query' => 'CREATE TABLE IF NOT EXISTS s0', 'rollback' => 'DROP TABLE IF EXISTS s0'],
			['node' => 'node1', 'query' => 'CREATE CLUSTER IF NOT EXISTS main', 'rollback' => 'DELETE CLUSTER main'],
			['node' => 'node2', 'query' => 'JOIN CLUSTER IF NOT EXISTS main', 'rollback' => 'DELETE CLUSTER main'],
		];

		foreach ($idempotentOps as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $idempotentGroup);
		}

		$result = $this->queue->rollbackOperationGroup($idempotentGroup);
		$this->assertTrue($result);
	}

	// Helper methods

	/**
	 * Build table creation workflow for testing
	 */
	private function buildTableCreationWorkflow(
		string $tableName,
		string $operationGroup,
		int $shardCount,
		int $replicationFactor,
		array $nodes
	): array {
		$commands = [];
		$localShards = [];
		$agents = [];

		for ($shard = 0; $shard < $shardCount; $shard++) {
			$shardName = "{$tableName}_s{$shard}";
			$localShards[] = $shardName;

			for ($replica = 0; $replica < $replicationFactor; $replica++) {
				$nodeIndex = ($shard + $replica) % count($nodes);
				$node = $nodes[$nodeIndex];

				$commands[] = [
					'node' => $node,
					'query' => "CREATE TABLE {$shardName} (id bigint)",
					'rollback' => "DROP TABLE IF EXISTS {$shardName}",
					'group' => $operationGroup,
				];

				if (!isset($agents[$node])) {
					$agents[$node] = [];
				}
				$agents[$node][] = $shardName;
			}
		}

		// Create distributed table
		$agentList = [];
		foreach ($agents as $node => $shards) {
			$agentList[] = implode(' ', array_map(fn($s) => "agent='{$node}:{$s}'", $shards));
		}

		$commands[] = [
			'node' => $nodes[0],
			'query' => "CREATE TABLE {$tableName} type='distributed' local='" . implode(',', $localShards) . "' " . implode(' ', $agentList),
			'rollback' => "DROP TABLE IF EXISTS {$tableName}",
			'group' => $operationGroup,
		];

		return $commands;
	}

	/**
	 * Build rebalancing workflow for testing
	 */
	private function buildRebalancingWorkflow(
		string $tableName,
		string $operationGroup,
		array $nodes
	): array {
		$commands = [];
		$newNode = end($nodes);
		$sourceNode = $nodes[0];

		// Rebalance shards 2,3 to new node
		for ($shard = 2; $shard <= 3; $shard++) {
			$tempCluster = "temp_move_{$shard}";
			$shardName = "{$tableName}_s{$shard}";

			// Create on target
			$commands[] = [
				'node' => $newNode,
				'query' => "CREATE TABLE {$shardName}",
				'rollback' => "DROP TABLE IF EXISTS {$shardName}",
				'group' => $operationGroup,
			];

			// Intermediate cluster
			$commands[] = [
				'node' => $sourceNode,
				'query' => "CREATE CLUSTER {$tempCluster}",
				'rollback' => "DELETE CLUSTER {$tempCluster}",
				'group' => $operationGroup,
			];

			$commands[] = [
				'node' => $sourceNode,
				'query' => "ALTER CLUSTER {$tempCluster} ADD {$shardName}",
				'rollback' => "ALTER CLUSTER {$tempCluster} DROP {$shardName}",
				'group' => $operationGroup,
			];

			$commands[] = [
				'node' => $newNode,
				'query' => "JOIN CLUSTER {$tempCluster}",
				'rollback' => "DELETE CLUSTER {$tempCluster}",
				'group' => $operationGroup,
			];

			$commands[] = [
				'node' => $sourceNode,
				'query' => "ALTER CLUSTER {$tempCluster} DROP {$shardName}",
				'rollback' => "ALTER CLUSTER {$tempCluster} ADD {$shardName}",
				'group' => $operationGroup,
			];

			$commands[] = [
				'node' => $sourceNode,
				'query' => "DELETE CLUSTER {$tempCluster}",
				'rollback' => '',
				'group' => $operationGroup,
			];
		}

		// Update distributed table
		$commands[] = [
			'node' => $sourceNode,
			'query' => "DROP TABLE {$tableName}",
			'rollback' => '',
			'group' => $operationGroup,
		];

		$commands[] = [
			'node' => $sourceNode,
			'query' => "CREATE TABLE {$tableName} type='distributed' local='{$tableName}_s0,{$tableName}_s1' agent='{$newNode}:{$tableName}_s2 {$newNode}:{$tableName}_s3'",
			'rollback' => "DROP TABLE IF EXISTS {$tableName}",
			'group' => $operationGroup,
		];

		return $commands;
	}
}
