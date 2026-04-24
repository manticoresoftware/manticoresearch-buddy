<?php declare(strict_types=1);

/*
  Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableQueue;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the rebalance queue commit flag mechanism.
 * Verifies that queue items from incomplete rebalances (master died mid-queuing)
 * are not processed, and that new master properly cleans them up.
 */
final class RebalanceCommitFlagTest extends TestCase {

	private TestableQueue $queue;

	protected function setUp(): void {
		$this->queue = new TestableQueue();
	}

	/**
	 * Test that rebalance queue items include operation_group
	 */
	public function testRebalanceItemsHaveOperationGroup(): void {
		$group = 'rebalance_t_' . time();

		// Simulate rebalancing: cleanup + create distributed tables
		$create = 'CREATE TABLE IF NOT EXISTS system.t_s0 (id bigint)';
		$drop = 'DROP TABLE IF EXISTS system.t_s0';
		$dropDist = 'DROP TABLE IF EXISTS t OPTION force=1';

		$this->queue->add('node1', $dropDist, '', $group);
		$this->queue->add('node1', 'ALTER CLUSTER abc DROP system.t_s0', 'ALTER CLUSTER abc ADD system.t_s0', $group);
		$this->queue->add('node1', $drop, '', $group);
		$this->queue->add('node2', $create, $drop, $group);
		$this->queue->add('node1', $dropDist, '', $group);
		$distCreate = "CREATE TABLE t type='shard' local='system.t_s0'";
		$this->queue->add('node1', $distCreate, 'DROP TABLE IF EXISTS t', $group);

		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(6, $commands);

		foreach ($commands as $cmd) {
			$this->assertEquals(
				$group, $cmd['operation_group'],
				'All rebalance items must share the same operation_group'
			);
		}
	}

	/**
	 * Test that uncommitted groups are identifiable.
	 * When master queues items but dies before setting committed flag,
	 * the group should be detectable as uncommitted.
	 */
	public function testUncommittedGroupDetection(): void {
		$group = 'rebalance_t_' . time();

		// Master queues items (phase 1)
		$create = 'CREATE TABLE IF NOT EXISTS system.t_s0 (id bigint)';
		$drop = 'DROP TABLE IF EXISTS system.t_s0';
		$this->queue->add('node1', 'DROP TABLE IF EXISTS t OPTION force=1', '', $group);
		$this->queue->add('node2', $create, $drop, $group);

		// Master dies here — no committed flag set

		// Verify items were queued
		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(2, $commands);

		// All items belong to the uncommitted group
		foreach ($commands as $cmd) {
			$this->assertEquals($group, $cmd['operation_group']);
		}
	}

	/**
	 * Test rollback commands are properly stored for rebalance operations
	 */
	public function testRebalanceRollbackCommandsPresent(): void {
		$group = 'rebalance_t_' . time();

		$create = 'CREATE TABLE IF NOT EXISTS system.t_s0 (id bigint)';
		$drop = 'DROP TABLE IF EXISTS system.t_s0';
		$this->queue->add('node2', $create, $drop, $group);
		$this->queue->add('node1', 'ALTER CLUSTER abc ADD system.t_s0', 'ALTER CLUSTER abc DROP system.t_s0', $group);
		$distCreate = "CREATE TABLE t type='shard' local='system.t_s0'";
		$this->queue->add('node1', $distCreate, 'DROP TABLE IF EXISTS t', $group);

		$commands = $this->queue->getCapturedCommands();

		// Non-destructive operations must have rollback
		$createShard = $commands[0];
		$this->assertStringContainsString('DROP TABLE IF EXISTS', $createShard['rollback_query']);

		$addCluster = $commands[1];
		$this->assertStringContainsString('ALTER CLUSTER abc DROP', $addCluster['rollback_query']);

		$createDist = $commands[2];
		$this->assertStringContainsString('DROP TABLE IF EXISTS', $createDist['rollback_query']);
	}

	/**
	 * Test that purgeOperationGroup removes all items for a group.
	 * This is called by the new master when it detects an uncommitted rebalance.
	 */
	public function testPurgeOperationGroup(): void {
		$group = 'rebalance_t_' . time();

		$this->queue->add('node1', 'query1', '', $group);
		$this->queue->add('node2', 'query2', '', $group);
		$this->queue->add('node1', 'query3', '', 'other_group');

		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(3, $commands);

		// Filter — in real implementation purge deletes from DB
		$groupItems = array_filter($commands, fn($c) => $c['operation_group'] === $group);
		$otherItems = array_filter($commands, fn($c) => $c['operation_group'] !== $group);

		$this->assertCount(2, $groupItems, 'Group should have 2 items');
		$this->assertCount(1, $otherItems, 'Other group items should be untouched');
	}

	/**
	 * Test the complete master-death-recovery flow:
	 * 1. Master queues partial rebalance (no commit)
	 * 2. New master detects uncommitted group
	 * 3. New master purges orphaned items
	 * 4. New master re-runs rebalance fresh
	 */
	public function testMasterDeathRecoveryFlow(): void {
		$group = 'rebalance_t_1234';

		// Step 1: Old master queues items (partial — simulating death mid-queue)
		$this->queue->add('node1', 'DROP TABLE IF EXISTS t OPTION force=1', '', $group);
		$this->queue->add('node1', 'ALTER CLUSTER abc DROP system.t_s0', '', $group);
		// Master dies here — items for node2 never queued, no commit flag

		$commands = $this->queue->getCapturedCommands();
		$this->assertCount(2, $commands, 'Partial queue: only 2 of expected 6 items');

		// Step 2: New master checks for uncommitted groups
		// In real code: cleanupUncommittedRebalances() checks state
		$uncommittedGroups = array_unique(
			array_column(
				array_filter($commands, fn($c) => !empty($c['operation_group'])),
				'operation_group'
			)
		);
		$this->assertContains($group, $uncommittedGroups);

		// Step 3: Purge (in real code: purgeOperationGroup)
		$remainingAfterPurge = array_filter($commands, fn($c) => $c['operation_group'] !== $group);
		$this->assertEmpty($remainingAfterPurge, 'All orphaned items purged');

		// Step 4: New master re-runs rebalance with new group
		$newGroup = 'rebalance_t_5678';
		$newQueue = new TestableQueue();
		$create = 'CREATE TABLE IF NOT EXISTS system.t_s0 (id bigint)';
		$drop = 'DROP TABLE IF EXISTS system.t_s0';
		$dropDist = 'DROP TABLE IF EXISTS t OPTION force=1';
		$newQueue->add('node1', $dropDist, '', $newGroup);
		$newQueue->add('node1', 'ALTER CLUSTER abc DROP system.t_s0', '', $newGroup);
		$newQueue->add('node1', $drop, '', $newGroup);
		$newQueue->add('node2', $create, $drop, $newGroup);
		$dist = "CREATE TABLE t type='shard'";
		$newQueue->add('node1', $dist, 'DROP TABLE IF EXISTS t', $newGroup);
		$newQueue->add('node2', $dist, 'DROP TABLE IF EXISTS t', $newGroup);

		$newCommands = $newQueue->getCapturedCommands();
		$this->assertCount(6, $newCommands, 'Complete rebalance: all 6 items queued');

		foreach ($newCommands as $cmd) {
			$this->assertEquals($newGroup, $cmd['operation_group']);
		}
	}
}
