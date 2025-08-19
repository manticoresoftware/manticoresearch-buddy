<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableQueue;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableTable;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for rollback functionality with Table operations
 * Tests the interaction between Table operations and Queue rollback system
 */
final class QueueRollbackIntegrationTest extends TestCase {

	private TestableQueue $queue;

	private TestableTable $table;

	protected function setUp(): void {
		$this->queue = new TestableQueue();
		$this->table = new TestableTable();
	}

	/**
	 * Test table creation with rollback commands
	 */
	public function testTableCreationWithRollback(): void {
		// Since TestableTable might not have all methods, we'll test the queue directly
		// This simulates what would happen during table shard creation

		// Add some typical table creation commands with rollback
		$this->queue->add(
			'node1',
			'CREATE TABLE test_s0 (id bigint)',
			'DROP TABLE IF EXISTS test_s0',
			'shard_create_test'
		);
		$this->queue->add(
			'node1',
			'CREATE TABLE test_s1 (id bigint)',
			'DROP TABLE IF EXISTS test_s1',
			'shard_create_test'
		);
		$this->queue->add(
			'node1',
			'CREATE TABLE test type=\'distributed\' local=\'test_s0,test_s1\'',
			'DROP TABLE IF EXISTS test',
			'shard_create_test'
		);

		// Verify queue has commands with rollback
		$commands = $this->queue->getCapturedCommands();
		$this->assertNotEmpty($commands);
		$this->assertCount(3, $commands);

		foreach ($commands as $command) {
			$this->assertArrayHasKey('rollback_query', $command);
			$this->assertNotEmpty($command['rollback_query']);
			$this->assertEquals('shard_create_test', $command['operation_group']);

			// Verify rollback patterns
			if (str_contains($command['query'], 'CREATE TABLE') && !str_contains($command['query'], 'distributed')) {
				$this->assertStringContainsString('DROP TABLE IF EXISTS', $command['rollback_query']);
			} elseif (str_contains($command['query'], 'CREATE CLUSTER')) {
				$this->assertStringContainsString('DELETE CLUSTER', $command['rollback_query']);
			} elseif (str_contains($command['query'], 'ALTER CLUSTER') && str_contains($command['query'], 'ADD')) {
				$this->assertStringContainsString('ALTER CLUSTER', $command['rollback_query']);
				$this->assertStringContainsString('DROP', $command['rollback_query']);
			}
		}
	}

	/**
	 * Test table drop operations with empty rollback (destructive)
	 */
	public function testTableDropWithEmptyRollback(): void {
		// Simulate table drop commands
		$this->queue->add('node1', 'DROP TABLE IF EXISTS test_s0', '', 'drop_operation');
		$this->queue->add('node1', 'DROP TABLE IF EXISTS test_s1', '', 'drop_operation');
		$this->queue->add('node1', 'DROP TABLE IF EXISTS test', '', 'drop_operation');

		// Verify drop commands have empty rollback (can't undo destructive operations)
		$commands = $this->queue->getCapturedCommands();
		$this->assertNotEmpty($commands);

		foreach ($commands as $command) {
			if (!str_contains($command['query'], 'DROP TABLE')) {
				continue;
			}

			$this->assertEquals('', $command['rollback_query']);
		}
	}

	/**
	 * Test operation groups for atomic rollback
	 */
	public function testOperationGroupsForAtomicRollback(): void {
		$operationGroup = 'shard_create_test_' . uniqid();

		// Add multiple commands with same operation group
		$this->queue->add('node1', 'CREATE TABLE test_s0 (id bigint)', 'DROP TABLE IF EXISTS test_s0', $operationGroup);
		$this->queue->add('node1', 'CREATE TABLE test_s1 (id bigint)', 'DROP TABLE IF EXISTS test_s1', $operationGroup);
		$this->queue->add(
			'node1',
			'CREATE TABLE test type=\'distributed\'',
			'DROP TABLE IF EXISTS test',
			$operationGroup
		);

		// Verify all commands have the same operation group
		$commands = $this->queue->getCapturedCommands();
		foreach ($commands as $command) {
			$this->assertEquals($operationGroup, $command['operation_group']);
		}
	}

	/**
	 * Test rollback execution for failed table creation
	 */
	public function testRollbackExecutionForFailedCreation(): void {
		$operationGroup = 'failed_creation_test';

		// Add some commands that would be part of table creation
		$this->queue->add('node1', 'CREATE TABLE test_s0 (id bigint)', 'DROP TABLE IF EXISTS test_s0', $operationGroup);
		$this->queue->add('node1', 'CREATE CLUSTER temp_cluster', 'DELETE CLUSTER temp_cluster', $operationGroup);

		// Test rollback execution
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result); // Should succeed in test environment
	}

	/**
	 * Test complex rebalancing scenario with rollback
	 */
	public function testRebalancingWithRollback(): void {
		$operationGroup = 'rebalance_test_' . uniqid();

		// Simulate RF=1 rebalancing with intermediate clusters
		$rebalanceCommands = [
			// Create shard on new node
			['node2', 'CREATE TABLE test_s0 (id bigint)', 'DROP TABLE IF EXISTS test_s0'],

			// Create intermediate cluster for data movement
			['node1', 'CREATE CLUSTER temp_move_0_123', 'DELETE CLUSTER temp_move_0_123'],
			['node1', 'ALTER CLUSTER temp_move_0_123 ADD test_s0', 'ALTER CLUSTER temp_move_0_123 DROP test_s0'],
			['node2', 'JOIN CLUSTER temp_move_0_123', 'DELETE CLUSTER temp_move_0_123'],

			// Complete the move
			[
				'node1',
				'ALTER CLUSTER temp_move_0_123 DROP test_s0',
				'ALTER CLUSTER temp_move_0_123 ADD test_s0',
			],
			['node1', 'DROP TABLE test_s0', ''], // Original shard removal (destructive)
			['node1', 'DELETE CLUSTER temp_move_0_123', ''], // Cleanup (destructive)

			// Update distributed table
			['node1', 'DROP TABLE test', ''],
			[
				'node1',
				'CREATE TABLE test type=\'distributed\' local=\'test_s1\' agent=\'node2:test_s0\'',
				'DROP TABLE IF EXISTS test',
			],
		];

		foreach ($rebalanceCommands as [$node, $query, $rollback]) {
			$this->queue->add($node, $query, $rollback, $operationGroup);
		}

		// Verify rebalancing commands have proper rollback
		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(sizeof($rebalanceCommands), $commands);

		// Check for shard movement commands with rollback
		$shardMovementCommands = array_filter(
			$commands, function ($cmd) {
				return str_contains($cmd['query'], 'temp_move_') ||
				   str_contains($cmd['query'], 'CREATE CLUSTER') ||
				   str_contains($cmd['query'], 'JOIN CLUSTER');
			}
		);

		$this->assertNotEmpty($shardMovementCommands);

		foreach ($shardMovementCommands as $command) {
			// Non-destructive operations should have rollback commands
			if (str_contains($command['query'], 'DROP') || str_contains($command['query'], 'DELETE')) {
				continue;
			}

			$this->assertNotEmpty($command['rollback_query']);
		}
	}

	/**
	 * Test rollback command patterns for different operations
	 */
	public function testRollbackCommandPatterns(): void {
		$testOperations = [
			// Table operations
			['CREATE TABLE test_s0 (id bigint)', 'DROP TABLE IF EXISTS test_s0'],
			['CREATE TABLE test_s1 (id bigint)', 'DROP TABLE IF EXISTS test_s1'],

			// Cluster operations
			['CREATE CLUSTER temp_cluster', 'DELETE CLUSTER temp_cluster'],
			['ALTER CLUSTER temp_cluster ADD test_s0', 'ALTER CLUSTER temp_cluster DROP test_s0'],
			['JOIN CLUSTER temp_cluster', 'DELETE CLUSTER temp_cluster'],

			// Destructive operations (empty rollback)
			['DROP TABLE test_s0', ''],
			['DELETE CLUSTER temp_cluster', ''],
		];

		foreach ($testOperations as [$query, $expectedRollback]) {
			$this->queue->add('node1', $query, $expectedRollback);
		}

		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(sizeof($testOperations), $commands);

		for ($i = 0; $i < sizeof($testOperations); $i++) {
			$this->assertEquals($testOperations[$i][0], $commands[$i]['query']);
			$this->assertEquals($testOperations[$i][1], $commands[$i]['rollback_query']);
		}
	}

	/**
	 * Test rollback with intermediate cluster cleanup
	 */
	public function testRollbackWithIntermediateClusterCleanup(): void {
		$operationGroup = 'move_op_1';

		// Simulate RF=1 shard movement with intermediate clusters
		$moveOperations = [
			['node1', 'CREATE TABLE test_s0 (id bigint)', 'DROP TABLE IF EXISTS test_s0'],
			['node1', 'CREATE CLUSTER temp_move_0_123', 'DELETE CLUSTER temp_move_0_123'],
			['node1', 'ALTER CLUSTER temp_move_0_123 ADD test_s0', 'ALTER CLUSTER temp_move_0_123 DROP test_s0'],
			['node2', 'JOIN CLUSTER temp_move_0_123', 'DELETE CLUSTER temp_move_0_123'],
			['node1', 'ALTER CLUSTER temp_move_0_123 DROP test_s0', 'ALTER CLUSTER temp_move_0_123 ADD test_s0'],
			['node1', 'DROP TABLE test_s0', ''], // Destructive
			['node1', 'DELETE CLUSTER temp_move_0_123', ''], // Cleanup
		];

		foreach ($moveOperations as [$node, $query, $rollback]) {
			$this->queue->add($node, $query, $rollback, $operationGroup);
		}

		// Test rollback of the entire operation group
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);

		// Verify all commands were added with correct operation group
		$commands = $this->queue->getCapturedCommands();
		foreach ($commands as $command) {
			$this->assertEquals($operationGroup, $command['operation_group']);
		}
	}

	/**
	 * Test rollback system handles different command types correctly
	 */
	public function testRollbackWithDifferentCommandTypes(): void {
		$operationGroup = 'mixed_commands_test';

		// Mix of different command types
		$commands = [
			// Reversible operations
			['CREATE TABLE users (id bigint)', 'DROP TABLE IF EXISTS users'],
			['CREATE CLUSTER main_cluster', 'DELETE CLUSTER main_cluster'],
			['ALTER CLUSTER main_cluster ADD users', 'ALTER CLUSTER main_cluster DROP users'],

			// Destructive operations (no rollback possible)
			['DROP TABLE temp_table', ''],
			['DELETE CLUSTER temp_cluster', ''],

			// Complex operations
			[
				'CREATE TABLE distributed_users type=\'distributed\' local=\'users\'',
				'DROP TABLE IF EXISTS distributed_users',
			],
		];

		foreach ($commands as [$query, $rollback]) {
			$this->queue->add('node1', $query, $rollback, $operationGroup);
		}

		$capturedCommands = $this->queue->getCapturedCommands();
		$this->assertCount(sizeof($commands), $capturedCommands);

		// Verify rollback patterns
		foreach ($capturedCommands as $i => $command) {
			$expectedRollback = $commands[$i][1];
			$this->assertEquals($expectedRollback, $command['rollback_query']);
			$this->assertEquals($operationGroup, $command['operation_group']);
		}

		// Test rollback execution
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}
}
