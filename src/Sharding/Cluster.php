<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Sharding;

use Ds\Set;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use RuntimeException;

final class Cluster {
	// Name of the cluster that we use to store meta data
	// TODO: not in use yet
	const SYSTEM_NAME = 'system';

	/** @var Set<string> $nodes set of all nodes that belong the the cluster */
	protected Set $nodes;

	/**
	 * Initialize with a given client
	 * @param Client $client
	 * @param string $name
	 * @return void
	 */
	public function __construct(
		protected Client $client,
		public readonly string $name,
		protected string $nodeId
	) {
		$this->nodes = new Set;
	}

	/**
	 * TODO: not in use yet
	 * This method is used to initialize the system cluster
	 * @param  Client $client
	 * @return void
	 */
	public static function init(Client $client): void {
		$cluster = new static(
			$client,
			static::SYSTEM_NAME,
			Node::findId($client)
		);
		$cluster->create();
	}

	/**
	 * Initialize and create the current cluster
	 * This method should be executed on main cluster node
	 * @param ?Queue $queue
	 * @return int Last insert id into the queue or 0
	 */
	public function create(?Queue $queue = null): int {
		$nodes = $this->getNodes();
		// Empty nodes mean that there is no such cluster
		if (!$nodes->isEmpty()) {
			throw new RuntimeException('Trying to create cluster that already exists');
		}

		// TODO: the pass is the subject to remove
		$query = "CREATE CLUSTER {$this->name} '{$this->name}' as path";
		return $this->runQuery($queue, $query);
	}

	/**
	 * When we have rf=2 and/or cluster with 2 nodes
	 * while one is dead we need to remove it
	 * to make it we need to make it safe first
	 * @param  ?Queue $queue
	 * @return int
	 */
	public function makePrimary(?Queue $queue = null): int {
		$query = "SET CLUSTER {$this->name} GLOBAL 'pc.bootstrap' = 1";
		return $this->runQuery($queue, $query);
	}

	/**
	 * Remove the cluster, we should run it on one
	 * Another will catch up
	 * @param  ?Queue $queue
	 * @return int
	 */
	public function remove(?Queue $queue = null): int {
		$query = "DELETE CLUSTER {$this->name}";
		return $this->runQuery($queue, $query);
	}

	/**
	 * Helper function to run query on the node
	 * @param  ?Queue $queue
	 * @param  string     $query
	 * @return int
	 */
	protected function runQuery(?Queue $queue, string $query): int {
		if ($queue) {
			$queueId = $queue->add($this->nodeId, $query);
		} else {
			$this->client->sendRequest($query);
		}

		return $queueId ?? 0;
	}

	/**
	 * Get all nodes that belong to current cluster
	 * @return Set<string>
	 */
	public function getNodes(): Set {
		// If no cluster created, we return single node in set
		if (!$this->name) {
			return new Set([Node::findId($this->client)]);
		}

		$res = $this->client
			->sendRequest("SHOW STATUS LIKE 'cluster_{$this->name}_nodes_set'")
			->getResult();
		/** @var array{0:array{data:array{0?:array{Value:string}}}} $res */
		$replicationSet = $res[0]['data'][0]['Value'] ?? '';
		$set = new Set();
		if ($replicationSet) {
			$set->add(
				...array_map('trim', explode(',', $replicationSet))
			);
		}
		// Merge current nodes and queued to add in runtime to get full list
		return $set->merge($this->nodes);
	}

	/**
	 * Get currently active nodes, so later we can intersect
	 * @return Set<string>
	 */
	public function getActiveNodes(): Set {
		if (!$this->name) {
			return new Set([Node::findId($this->client)]);
		}
		$res = $this->client
			->sendRequest("SHOW STATUS LIKE 'cluster_{$this->name}_nodes_view'")
			->getResult();
		/** @var array{0:array{data:array{0?:array{Value:string}}}} $res */
		$replicationSet = $res[0]['data'][0]['Value'] ?? '';
		$set = new Set();
		if ($replicationSet) {
			// Counter: cluster_c_nodes_view
		// Value: 127.0.0.1:9112,127.0.0.1:9124:replication,127.0.0.1:9212,127.0.0.1:9224:replication
			$set->add(
				...array_filter(
					array_map('trim', explode(',', $replicationSet)),
					fn ($node) => !str_contains($node, ':replication'),
				)
			);
		}

		return $set;
	}

	/**
	 * Helper to get inactive nodes by intersectting all and active ones
	 * Inactive node means node that has outage or just new node
	 * @return Set<string>
	 */
	public function getInactiveNodes(): Set {
		return $this->getNodes()->xor($this->getActiveNodes());
	}

	/**
	 * Validate that the cluster is active and synced
	 * @return bool
	 */
	public function isActive(): bool {
		$res = $this->client
			->sendRequest("SHOW STATUS LIKE 'cluster_{$this->name}_status'")
			->getResult();
		/** @var array{0:array{data:array{0?:array{Value:string}}}} $res */
		$status = $res[0]['data'][0]['Value'] ?? 'primary';
		return $status === 'primary';
	}

	/**
	 * Create a cluster by using distributed queue with list of nodes
	 * This method just add join queries to the queue to all requested nodes
	 * @param  Queue  $queue
	 * @param  string ...$nodeIds
	 * @return static
	 */
	public function addNodeIds(Queue $queue, string ...$nodeIds): static {
		foreach ($nodeIds as $node) {
			$this->nodes->add($node);
			// TODO: the pass is the subject to remove
			$query = "JOIN CLUSTER {$this->name} at '{$this->nodeId}' '{$this->name}' as path";
			$queue->add($node, $query);
		}
		return $this;
	}

	/**
	 * Get the current hash of all cluster nodes
	 * @param Set<string> $nodes
	 * @return string
	 */
	public static function getNodesHash(Set $nodes): string {
		return md5($nodes->sorted()->join('|'));
	}

	/**
	 * Refresh cluster info due to secure inactive nodes
	 * @return static
	 */
	public function refresh(): static {
		$query = "ALTER CLUSTER {$this->name} UPDATE nodes";
		$this->runQuery(null, $query);
		return $this;
	}

	/**
	 * Enqueue the tables attachments to all nodes of current cluster
	 * @param Queue  $queue
	 * @param string ...$tables
	 * @return static
	 */
	public function addTables(Queue $queue, string ...$tables): static {
		foreach ($tables as $table) {
			$query = "ALTER CLUSTER {$this->name} ADD `{$table}`";
			$queue->add($this->nodeId, $query);
		}
		return $this;
	}

	/**
	 * Enqueue the tables detachement to all nodes of current cluster
	 * @param Queue  $queue
	 * @param string ...$tables
	 * @return static
	 */
	public function removeTables(Queue $queue, string ...$tables): static {
		foreach ($tables as $table) {
			$query = "ALTER CLUSTER {$this->name} DROP `{$table}`";
			$queue->add($this->nodeId, $query);
		}
		return $this;
	}

	/**
	 * Attach table to cluster and make it available on all nodes
	 * @param string $table
	 * @return static
	 */
	public function attachTable(string $table): static {
		// We can have situation when no cluster required
		if ($this->name) {
			$query = "ALTER CLUSTER {$this->name} ADD {$table}";
			$this->client->sendRequest($query);
		}
		return $this;
	}

	/**
	 * Detach table from the current cluster
	 * @param string $table
	 * @return static
	 */
	public function detachTable(string $table): static {
		// We can have situation when no cluster required
		if ($this->name) {
			$query = "ALTER CLUSTER {$this->name} DROP {$table}";
			$this->client->sendRequest($query);
		}
		return $this;
	}

	/**
	 * Get prefixed table name with current Cluster
	 * @param string $table
	 * @return string
	 */
	public function getTableName(string $table): string {
		return ($this->name ? "{$this->name}:" : '') . $table;
	}

	/**
	 * Same like getTableName but for system table name, now it's the same
	 * @param string $table
	 * @return string
	 */
	public function getSystemTableName(string $table): string {
		return $this->getTableName($table);
	}
}
