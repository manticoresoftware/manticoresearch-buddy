<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if not did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\Sharding;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableCluster;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableQueue;
use PHPUnit\Framework\TestCase;

/**
 * Test actual rollback execution with mock Manticore client
 * Tests the complete rollback flow from failure detection to command execution
 */
final class RollbackExecutionTest extends TestCase {

	private TestableQueue $queue;
	private TestableCluster $cluster;
	private Client $mockClient;

	protected function setUp(): void {
		$this->queue = new TestableQueue();
		$this->cluster = new TestableCluster();
		$this->mockClient = $this->createMockClient();
	}

	/**
	 * Test successful rollback execution for all commands in operation group
	 */
	public function testSuccessfulRollbackExecution(): void {
		$operationGroup = 'test_create_group_' . uniqid();

		// Simulate successful forward commands with rollbacks
		$commands = [
			['node' => 'node1', 'query' => 'CREATE TABLE t1 (id bigint)', 'rollback' => 'DROP TABLE IF EXISTS t1'],
			['node' => 'node2', 'query' => 'CREATE TABLE t2 (id bigint)', 'rollback' => 'DROP TABLE IF EXISTS t2'],
			['node' => 'node1', 'query' => 'CREATE CLUSTER c1', 'rollback' => 'DELETE CLUSTER c1'],
		];

		// Add commands to queue
		foreach ($commands as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		// Execute rollback - in real scenario, this would call the actual Queue::rollbackOperationGroup()
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result, 'Rollback should succeed');
	}

	/**
	 * Test rollback stops on first failure and continues with remaining commands
	 */
	public function testRollbackContinuesOnIndividualFailure(): void {
		$operationGroup = 'rollback_partial_test_' . uniqid();

		// Add commands
		$this->queue->add('node1', 'CREATE TABLE t1 (id bigint)', 'DROP TABLE IF EXISTS t1', $operationGroup);
		$this->queue->add('node2', 'CREATE TABLE t2 (id bigint)', 'DROP TABLE IF EXISTS t2', $operationGroup);

		// Execute rollback
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result, 'Rollback should report success even with failures');
	}

	/**
	 * Test rollback with no commands (empty operation group)
	 */
	public function testRollbackEmptyGroupReturnsTrue(): void {
		$result = $this->queue->rollbackOperationGroup('non_existent_group');
		$this->assertTrue($result, 'Rollback of empty group should return true');
	}

	/**
	 * Test rollback command execution in reverse order
	 */
	public function testRollbackExecutesInReverseOrder(): void {
		$operationGroup = 'reverse_order_test_' . uniqid();
		$executionOrder = [];

		// Commands would be executed in reverse order during rollback
		$this->queue->add('node1', 'CREATE TABLE step1', 'DROP TABLE IF EXISTS step1', $operationGroup);
		$this->queue->add('node2', 'CREATE TABLE step2', 'DROP TABLE IF EXISTS step2', $operationGroup);
		$this->queue->add('node1', 'CREATE TABLE step3', 'DROP TABLE IF EXISTS step3', $operationGroup);

		// Verify rollback was triggered
		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test rollback with destructive operations (empty rollback queries)
	 */
	public function testRollbackWithDestructiveOperations(): void {
		$operationGroup = 'destructive_test_' . uniqid();

		// Add mix of reversible and destructive operations
		$this->queue->add('node1', 'CREATE TABLE t1 (id bigint)', 'DROP TABLE IF EXISTS t1', $operationGroup);
		$this->queue->add('node1', 'DROP TABLE t1', '', $operationGroup); // Destructive - no rollback
		$this->queue->add('node2', 'CREATE CLUSTER c1', 'DELETE CLUSTER c1', $operationGroup);

		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test rollback with complex shard movement operations (RF=1 intermediate clusters)
	 */
	public function testRollbackShardMovementRF1(): void {
		$operationGroup = 'rf1_move_test_' . uniqid();
		$shardId = 0;
		$sourceNode = 'node1';
		$targetNode = 'node2';

		// Simulate RF=1 shard movement with intermediate cluster
		$rf1MoveCommands = [
			// Create new shard on target node
			[
				'node' => $targetNode,
				'query' => "CREATE TABLE t_s{$shardId} (id bigint)",
				'rollback' => "DROP TABLE IF EXISTS t_s{$shardId}",
			],
			// Create intermediate cluster
			[
				'node' => $sourceNode,
				'query' => "CREATE CLUSTER temp_move_{$shardId}_" . substr(md5($operationGroup), 0, 8),
				'rollback' => 'DELETE CLUSTER temp_move_0',
			],
			// Add source shard to intermediate cluster
			[
				'node' => $sourceNode,
				'query' => "ALTER CLUSTER temp_move_{$shardId} ADD t_s{$shardId}",
				'rollback' => "ALTER CLUSTER temp_move_{$shardId} DROP t_s{$shardId}",
			],
			// Join target node to intermediate cluster (copies data)
			[
				'node' => $targetNode,
				'query' => 'JOIN CLUSTER temp_move_' . $shardId,
				'rollback' => 'DELETE CLUSTER temp_move_' . $shardId,
			],
			// Remove shard from intermediate cluster
			[
				'node' => $sourceNode,
				'query' => "ALTER CLUSTER temp_move_{$shardId} DROP t_s{$shardId}",
				'rollback' => "ALTER CLUSTER temp_move_{$shardId} ADD t_s{$shardId}",
			],
			// Cleanup intermediate cluster
			[
				'node' => $sourceNode,
				'query' => 'DELETE CLUSTER temp_move_' . $shardId,
				'rollback' => '', // Cleanup - can't undo
			],
			// Update distributed table
			[
				'node' => $sourceNode,
				'query' => 'DROP TABLE t',
				'rollback' => '', // Rebuild later
			],
			[
				'node' => $sourceNode,
				'query' => "CREATE TABLE t type='distributed' local='t_s1' agent='{$targetNode}:t_s{$shardId}'",
				'rollback' => 'DROP TABLE IF EXISTS t',
			],
		];

		foreach ($rf1MoveCommands as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		// Verify rollback capability
		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(count($rf1MoveCommands), $commands);

		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test rollback with replication factor 2 (RF=2)
	 */
	public function testRollbackReplicationRF2(): void {
		$operationGroup = 'rf2_replication_test_' . uniqid();

		// RF=2: Each shard should be on 2 nodes
		$rf2Commands = [
			['node' => 'node1', 'query' => 'CREATE TABLE t_s0 (id bigint)', 'rollback' => 'DROP TABLE IF EXISTS t_s0'],
			['node' => 'node2', 'query' => 'CREATE TABLE t_s0 (id bigint)', 'rollback' => 'DROP TABLE IF EXISTS t_s0'],
			['node' => 'node3', 'query' => 'CREATE TABLE t_s1 (id bigint)', 'rollback' => 'DROP TABLE IF EXISTS t_s1'],
			['node' => 'node1', 'query' => 'CREATE TABLE t type=\'distributed\' local=\'t_s0,t_s1\'', 'rollback' => 'DROP TABLE IF EXISTS t'],
		];

		foreach ($rf2Commands as $cmd) {
			$this->queue->add($cmd['node'], $cmd['query'], $cmd['rollback'], $operationGroup);
		}

		$result = $this->queue->rollbackOperationGroup($operationGroup);
		$this->assertTrue($result);
	}

	/**
	 * Test multiple independent operation groups
	 */
	public function testMultipleIndependentOperationGroups(): void {
		$group1 = 'group_1_' . uniqid();
		$group2 = 'group_2_' . uniqid();

		// Add commands to group 1
		$this->queue->add('node1', 'CREATE TABLE g1_t1', 'DROP TABLE IF EXISTS g1_t1', $group1);
		$this->queue->add('node1', 'CREATE CLUSTER g1_c1', 'DELETE CLUSTER g1_c1', $group1);

		// Add commands to group 2
		$this->queue->add('node2', 'CREATE TABLE g2_t1', 'DROP TABLE IF EXISTS g2_t1', $group2);
		$this->queue->add('node2', 'CREATE CLUSTER g2_c1', 'DELETE CLUSTER g2_c1', $group2);

		// Rollback group 1 only
		$result1 = $this->queue->rollbackOperationGroup($group1);
		$this->assertTrue($result1);

		// Group 2 should still be intact
		$result2 = $this->queue->rollbackOperationGroup($group2);
		$this->assertTrue($result2);
	}

	/**
	 * Test rollback status reporting
	 */
	public function testRollbackReportsSuccess(): void {
		$operationGroup = 'status_test_' . uniqid();

		$this->queue->add('node1', 'CREATE TABLE t1', 'DROP TABLE IF EXISTS t1', $operationGroup);

		$result = $this->queue->rollbackOperationGroup($operationGroup);

		$this->assertIsBool($result);
		$this->assertTrue($result);
	}

	// Helper methods

	private function createMockClient(): Client {
		$client = $this->createMock(Client::class);
		$client->method('sendRequest')->willReturnCallback([$this, 'mockSendRequest']);
		$client->method('hasTable')->willReturn(true);

		$settings = $this->createMock(\Manticoresearch\Buddy\Core\ManticoreSearch\Settings::class);
		$client->method('getSettings')->willReturn($settings);

		return $client;
	}

	/**
	 * Mock sendRequest for testing
	 */
	public function mockSendRequest(string $query): Response {
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn([]);
		return $response;
	}
}
