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
use Manticoresearch\Buddy\Base\Plugin\Sharding\Cluster;

/**
 * Test double for Cluster class - enables mocking of final class
 * This wrapper delegates all calls to the actual Cluster instance
 * Used only in tests to bypass final class mocking limitations
 */
class TestableCluster {

	public function __construct(private ?Cluster $cluster = null) {
		// Allow null for pure mocking scenarios
	}

	/**
	 * Get all nodes that belong to current cluster
	 * @return Set<string>
	 */
	public function getNodes(): Set {
		return $this->cluster?->getNodes() ?? new Set();
	}

	/**
	 * Get inactive nodes by intersecting all and active ones
	 * @return Set<string>
	 */
	public function getInactiveNodes(): Set {
		return $this->cluster?->getInactiveNodes() ?? new Set();
	}

	/**
	 * Get currently active nodes
	 * @return Set<string>
	 */
	public function getActiveNodes(): Set {
		return $this->cluster?->getActiveNodes() ?? new Set();
	}

	/**
	 * Validate that the cluster is active and synced
	 * @return bool
	 */
	public function isActive(): bool {
		return $this->cluster?->isActive() ?? false;
	}

	/**
	 * Get prefixed table name with current Cluster
	 * @param string $table
	 * @return string
	 */
	public function getTableName(string $table): string {
		return $this->cluster?->getTableName($table) ?? $table;
	}

	/**
	 * Get system table name
	 * @param string $table
	 * @return string
	 */
	public function getSystemTableName(string $table): string {
		return $this->cluster?->getSystemTableName($table) ?? $table;
	}

	/**
	 * Get the current hash of all cluster nodes
	 * @param Set<string> $nodes
	 * @return string
	 */
	public static function getNodesHash(Set $nodes): string {
		return Cluster::getNodesHash($nodes);
	}

	/**
	 * Refresh cluster info
	 * @return static
	 */
	public function refresh(): static {
		$this->cluster?->refresh();
		return $this;
	}

	/**
	 * Initialize and create the current cluster
	 * @param ?\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue
	 * @return int
	 */
	public function create(?\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue = null): int {
		return $this->cluster?->create($queue) ?? 0;
	}

	/**
	 * Make cluster primary when needed
	 * @param ?\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue
	 * @return int
	 */
	public function makePrimary(?\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue = null): int {
		return $this->cluster?->makePrimary($queue) ?? 0;
	}

	/**
	 * Remove the cluster
	 * @param ?\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue
	 * @return int
	 */
	public function remove(?\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue = null): int {
		return $this->cluster?->remove($queue) ?? 0;
	}

	/**
	 * Add node IDs to cluster
	 * @param \Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue
	 * @param string ...$nodeIds
	 * @return static
	 */
	public function addNodeIds(\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue, string ...$nodeIds): static {
		$this->cluster?->addNodeIds($queue, ...$nodeIds);
		return $this;
	}

	/**
	 * Add tables to cluster
	 * @param \Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue
	 * @param string ...$tables
	 * @return int
	 */
	public function addTables(\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue, string ...$tables): int {
		return $this->cluster?->addTables($queue, ...$tables) ?? 0;
	}

	/**
	 * Remove tables from cluster
	 * @param \Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue
	 * @param string ...$tables
	 * @return int
	 */
	public function removeTables(\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue, string ...$tables): int {
		return $this->cluster?->removeTables($queue, ...$tables) ?? 0;
	}

	/**
	 * Attach tables to cluster
	 * @param string ...$tables
	 * @return static
	 */
	public function attachTables(string ...$tables): static {
		$this->cluster?->attachTables(...$tables);
		return $this;
	}

	/**
	 * Detach tables from cluster
	 * @param string ...$tables
	 * @return static
	 */
	public function detachTables(string ...$tables): static {
		$this->cluster?->detachTables(...$tables);
		return $this;
	}

	/**
	 * Add pending table operation
	 * @param string $table
	 * @param \Manticoresearch\Buddy\Base\Plugin\Sharding\TableOperation $operation
	 * @return static
	 */
	public function addPendingTable(
		string $table,
		\Manticoresearch\Buddy\Base\Plugin\Sharding\TableOperation $operation
	): static {
		$this->cluster?->addPendingTable($table, $operation);
		return $this;
	}

	/**
	 * Check if table has pending operation
	 * @param string $table
	 * @param \Manticoresearch\Buddy\Base\Plugin\Sharding\TableOperation $operation
	 * @return bool
	 */
	public function hasPendingTable(
		string $table,
		\Manticoresearch\Buddy\Base\Plugin\Sharding\TableOperation $operation
	): bool {
		return $this->cluster?->hasPendingTable($table, $operation) ?? false;
	}

	/**
	 * Process pending tables
	 * @param \Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue
	 * @return static
	 */
	public function processPendingTables(\Manticoresearch\Buddy\Base\Plugin\Sharding\Queue $queue): static {
		$this->cluster?->processPendingTables($queue);
		return $this;
	}

	/**
	 * Get the cluster name (public readonly property)
	 * @return string
	 */
	public function getName(): string {
		return $this->cluster?->name ?? 'test_cluster';
	}
}
