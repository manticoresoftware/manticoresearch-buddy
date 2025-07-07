<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Test\Plugin\Sharding\TestDoubles;

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

	public function __construct(private ?Table $table = null) {
		// Allow null for pure mocking scenarios
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
				// For pure mocking scenarios, don't call the real table
				return;
			}
			$this->table?->rebalance($realQueue);
		} else {
			$this->table?->rebalance($queue);
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
