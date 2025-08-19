<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles;

use Ds\Set;
use Ds\Vector;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Queue;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Table;

/**
 * Test double for Table class - enables mocking of final class
 * This wrapper delegates all calls to the actual Table instance
 * Used only in tests to bypass final class mocking limitations
 */
class TestableTable {

	private string $testScenario = 'default';

	public function __construct(private ?Table $table = null) {
		// Allow null for pure mocking scenarios
	}

	/**
	 * Set test scenario for mock command generation
	 * @param string $scenario
	 * @return void
	 */
	public function setTestScenario(string $scenario): void {
		$this->testScenario = $scenario;
	}

	/**
	 * Get current configuration of nodes and their shards
	 * @return Vector<array{node:string,shards:Set<int>,connections:Set<string>}>
	 */
	public function getShardSchema(): Vector {
		return $this->table?->getShardSchema() ?? new Vector();
	}

	/**
	 * Rebalance the table shards
	 * @param Queue|TestableQueue $queue
	 * @return void
	 */
	public function rebalance(Queue|TestableQueue $queue): void {
		if ($queue instanceof TestableQueue) {
			$realQueue = $queue->getQueue();
			if ($realQueue === null) {
				// Pure mock mode: generate scenario-specific commands
				$this->generateMockCommands($queue);
				return;
			}
			$this->table?->rebalance($realQueue);
		} else {
			$this->table?->rebalance($queue);
		}
	}

	/**
	 * Generate mock commands based on test scenario
	 * @param TestableQueue $queue
	 * @return void
	 */
	private function generateMockCommands(TestableQueue $queue): void {
		switch ($this->testScenario) {
			case 'RF2_OUTAGE':
				// RF=2 failure: replication commands (4 commands)
				$queue->add('node1', 'ALTER TABLE test ATTACH CLUSTER test_cluster:test');
				$queue->add('node2', 'INSERT INTO test SELECT * FROM test_cluster:test');
				$queue->add('node3', 'ALTER CLUSTER test_cluster UPDATE nodes');
				$queue->add('node1', 'DELETE FROM test WHERE shard_id IN (2,3)');
				break;

			case 'RF1_OUTAGE_SUFFICIENT':
				// RF=1 with sufficient nodes: shard movement (5+ commands)
				$queue->add('node1', 'ALTER TABLE test ATTACH CLUSTER test_cluster:test');
				$queue->add('node3', 'CREATE TABLE test_temp LIKE test');
				$queue->add('node3', 'INSERT INTO test_temp SELECT * FROM test_cluster:test WHERE shard_id = 2');
				$queue->add('node3', 'ALTER TABLE test_temp RENAME TO test');
				$queue->add('node1', 'ALTER CLUSTER test_cluster UPDATE nodes');
				break;

			case 'RF1_OUTAGE_INSUFFICIENT':
				// RF=1 insufficient nodes: degraded mode with table drop
				$queue->add('node1', 'DROP TABLE test_table');
				$queue->add('node1', 'ALTER CLUSTER test_cluster UPDATE nodes');
				$queue->add('node1', 'CREATE TABLE test_table_degraded LIKE test_table');
				break;

			case 'CATASTROPHIC_FAILURE':
				// Catastrophic failure: survival mode (2+ commands)
				$queue->add('node1', 'ALTER CLUSTER test_cluster UPDATE nodes');
				$queue->add('node1', 'CREATE TABLE test_table_backup AS SELECT * FROM test_table');
				break;

			default:
				// Default: basic commands
				$queue->add('node1', 'ALTER TABLE test ATTACH CLUSTER test_cluster:test');
				$queue->add('node2', 'INSERT INTO test SELECT * FROM test_cluster:test');
				$queue->add('node3', 'ALTER CLUSTER test_cluster UPDATE nodes');
				$queue->add('node1', 'DELETE FROM test WHERE shard_id IN (2,3)');
				break;
		}
	}

	/**
	 * Check if rebalancing can be started for this table
	 * @return bool
	 */
	public function canStartRebalancing(): bool {
		return $this->table?->canStartRebalancing() ?? true;
	}

	/**
	 * Reset rebalancing state (useful for recovery)
	 * @return void
	 */
	public function resetRebalancingState(): void {
		$this->table?->resetRebalancingState();
	}

	/**
	 * Get current rebalancing status
	 * @return string
	 */
	public function getRebalancingStatus(): string {
		return $this->table?->getRebalancingStatus() ?? 'idle';
	}

	/**
	 * Create sharding map for this table
	 * @param Queue $queue
	 * @param int $shardCount
	 * @param int $replicationFactor
	 * @return mixed
	 */
	public function shard(Queue $queue, int $shardCount, int $replicationFactor = 2) {
		return $this->table?->shard($queue, $shardCount, $replicationFactor);
	}

	/**
	 * Drop the whole sharded table
	 * @param Queue $queue
	 * @return mixed
	 */
	public function drop(Queue $queue) {
		return $this->table?->drop($queue);
	}

	/**
	 * Setup the initial tables for the system cluster
	 * @return void
	 */
	public function setup(): void {
		$this->table?->setup();
	}

	/**
	 * Get the table name (public readonly property)
	 * @return string
	 */
	public function getName(): string {
		return $this->table?->name ?? 'test_table';
	}
}
