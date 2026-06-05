<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Ds\Map;
use Ds\Set;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RuntimeException;

final class Cluster {
	// Name of the cluster that we use to store meta data
	// TODO: not in use yet
	const SYSTEM_NAME = 'system';

	/** @var Set<string> $nodes set of all nodes that belong the the cluster */
	protected Set $nodes;

	/**
	 * @var Map<string,string> $tablesToAttach map of table => node that holds the table's data.
	 * The ALTER CLUSTER ADD for a table MUST run on a node that holds its data, otherwise the
	 * executing node's (possibly empty) copy is replicated over the populated replicas — wiping
	 * the data. So we remember the owner node per table and add per-owner, not on one cluster node.
	 */
	protected Map $tablesToAttach;

	/** @var Set<string> $tablesToDetach set of all tables that we need to detach from the cluster */
	protected Set $tablesToDetach;

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
		$this->tablesToAttach = new Map;
		$this->tablesToDetach = new Set;
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
	 * @param string|null $operationGroup Optional operation group for rollback
	 * @return int Last insert id into the queue or 0
	 */
	public function create(?Queue $queue = null, ?string $operationGroup = null): int {
		// TODO: the pass is the subject to remove
		$query = "CREATE CLUSTER IF NOT EXISTS {$this->name} '{$this->name}' as path";
		$rollback = "DELETE CLUSTER {$this->name}";
		return $this->runQuery($queue, $query, $rollback, $operationGroup);
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
	 * @param  string $query
	 * @param  string|null $rollbackQuery Optional rollback command
	 * @param  string|null $operationGroup Optional operation group
	 * @return int
	 */
	protected function runQuery(
		?Queue $queue,
		string $query,
		?string $rollbackQuery = null,
		?string $operationGroup = null
	): int {
		if ($queue) {
			$queueId = $queue->add($this->nodeId, $query, $rollbackQuery ?? '', $operationGroup);
		} else {
			$this->client->sendRequest($query, disableAgentHeader: true);
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
		return $this->getNodes()->diff($this->getActiveNodes());
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
	 * Check that ALL sharding-generated clusters on this node are primary and synced.
	 * Sharding clusters have 32-char lowercase hex names (md5 hash of node set).
	 * Must be true before processing any queue items, otherwise ALTER CLUSTER
	 * commands will fail with "cluster is not ready, current state is joining".
	 * @param Client $client
	 * @return bool
	 */
	public static function areAllShardingClustersPrimary(Client $client): bool {
		$res = $client
			->sendRequest("SHOW STATUS LIKE 'cluster_%'")
			->getResult();
		/** @var array{0?:array{data?:array<array{Counter:string,Value:string}>}} $res */
		$rows = $res[0]['data'] ?? [];

		// Track status and node_state per cluster
		/** @var array<string,array{status?:string,node_state?:string}> $clusterStates */
		$clusterStates = [];
		foreach ($rows as $row) {
			// Match both cluster_{name}_status and cluster_{name}_node_state
			if (!preg_match('/^cluster_([a-f0-9]{32})_(status|node_state)$/', (string)$row['Counter'], $m)) {
				continue;
			}
			$clusterName = $m[1];
			$field = $m[2];
			$clusterStates[$clusterName][$field] = (string)$row['Value'];
		}

		foreach ($clusterStates as $name => $state) {
			$status = $state['status'] ?? 'unknown';
			$nodeState = $state['node_state'] ?? 'unknown';

			if ($status !== 'primary') {
				Buddy::info("Sharding cluster {$name} is not ready (status: {$status})");
				return false;
			}
			if ($nodeState !== 'synced') {
				Buddy::info("Sharding cluster {$name} is not ready (node_state: {$nodeState})");
				return false;
			}
		}
		return true;
	}

	/**
	 * Create a cluster by using distributed queue with list of nodes
	 * This method just add join queries to the queue to all requested nodes
	 * @param  Queue  $queue
	 * @param  array<string> $nodeIds
	 * @param  string|null $operationGroup Optional operation group for rollback
	 * @return static
	 */
	public function addNodeIds(Queue $queue, array $nodeIds, ?string $operationGroup = null): static {
		$baseWaitForId = $queue->getWaitForId();
		foreach ($nodeIds as $node) {
			$this->nodes->add($node);
			// A node that previously held this cluster still has a stale copy persisted on disk
			// (cluster state survives a restart). A plain JOIN is then rejected with "cluster
			// already exists" and the node never state-transfers the writes it missed while down,
			// so it diverges (keeps fewer rows). Drop any local copy first — a no-op on a brand
			// new node, tolerated in Queue — then JOIN so the node gets a fresh SST from the
			// donor ({$this->nodeId}, the surviving data holder).
			$queue->setWaitForId($baseWaitForId);
			$deleteId = $queue->add($node, "DELETE CLUSTER {$this->name}", '', $operationGroup);
			$queue->setWaitForId($deleteId);
			$query = "JOIN CLUSTER {$this->name} at '{$this->nodeId}' '{$this->name}' as " .
				'path';
			$rollback = "DELETE CLUSTER {$this->name}";
			$queue->add($node, $query, $rollback, $operationGroup);
		}
		$queue->setWaitForId($baseWaitForId);
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
	 * @param array<string> $tables
	 * @param string|null $operationGroup Optional operation group for rollback
	 * @return int
	 */
	public function addTables(Queue $queue, array $tables, ?string $operationGroup = null): int {
		return $this->addTablesOnNode($queue, $this->nodeId, $tables, $operationGroup);
	}

	/**
	 * Enqueue ALTER CLUSTER ADD on a specific node. The node MUST hold the data of the
	 * tables being added — the executing node's copy is what replicates to the rest of the
	 * cluster, so adding from an empty node overwrites and destroys populated replicas.
	 * @param Queue $queue
	 * @param string $node node that holds the tables' data and runs the ALTER
	 * @param array<string> $tables
	 * @param string|null $operationGroup Optional operation group for rollback
	 * @return int
	 */
	public function addTablesOnNode(Queue $queue, string $node, array $tables, ?string $operationGroup = null): int {
		if (empty($tables)) {
			throw new \Exception('Tables must be passed to add');
		}
		$tablesStr = implode(',', $tables);
		$query = "ALTER CLUSTER {$this->name} ADD {$tablesStr}";
		$rollback = "ALTER CLUSTER {$this->name} DROP {$tablesStr}";
		return $queue->add($node, $query, $rollback, $operationGroup);
	}

	/**
	 * Enqueue the tables detachement to all nodes of current cluster
	 * @param Queue  $queue
	 * @param array<string> $tables
	 * @param string|null $operationGroup Optional operation group for rollback
	 * @return int
	 */
	public function removeTables(Queue $queue, array $tables, ?string $operationGroup = null): int {
		if (empty($tables)) {
			throw new \Exception('Tables must be passed to remove');
		}
		$tablesStr = implode(',', $tables);
		$query = "ALTER CLUSTER {$this->name} DROP {$tablesStr}";
		$rollback = "ALTER CLUSTER {$this->name} ADD {$tablesStr}";
		return $queue->add($this->nodeId, $query, $rollback, $operationGroup);
	}

	/**
	 * Attach table to cluster and make it available on all nodes
	 * @param string ...$tables
	 * @return static
	 */
	public function attachTables(string ...$tables): static {
		if (!$tables) {
			throw new \Exception('Tables must be passed to attach');
		}
		// We can have situation when no cluster required
		if ($this->name) {
			$tables = implode(',', $tables);
			$query = "ALTER CLUSTER {$this->name} ADD {$tables}";
			$this->client->sendRequest($query);
		}
		return $this;
	}

	/**
	 * Detach table from the current cluster
	 * @param string ...$tables
	 * @return static
	 */
	public function detachTables(string ...$tables): static {
		if (!$tables) {
			throw new \Exception('Tables must be passed to detach');
		}
		// We can have situation when no cluster required
		if ($this->name) {
			$tables = implode(',', $tables);
			$query = "ALTER CLUSTER {$this->name} DROP {$tables}";
			$this->client->sendRequest($query);
		}
		return $this;
	}

	/**
	 * Add pending table operation that we will process later in single shot
	 * @param string $table
	 * @param TableOperation $operation
	 * @param string|null $ownerNode node that holds the table's data; the ALTER CLUSTER ADD
	 *  must run there. Defaults to this cluster's node when not given (e.g. empty/new tables).
	 * @return static
	 */
	public function addPendingTable(string $table, TableOperation $operation, ?string $ownerNode = null): static {
		if ($operation === TableOperation::Attach) {
			$this->tablesToAttach->put($table, $ownerNode ?? $this->nodeId);
		} else {
			$this->tablesToDetach->add($table);
		}
		return $this;
	}

	/**
	 * Check if the table is pending to add or drop
	 * @param string $table
	 * @param TableOperation $operation
	 * @return bool
	 */
	public function hasPendingTable(string $table, TableOperation $operation): bool {
		if ($operation === TableOperation::Attach) {
			return $this->tablesToAttach->hasKey($table);
		}

		return $this->tablesToDetach->contains($table);
	}

	/**
	 * Process pending tables to add and drop in current cluster
	 * @param Queue $queue
	 * @param string|null $operationGroup Optional operation group for rollback
	 * @return int Last queued id added while flushing pending tables
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	public function processPendingTables(Queue $queue, ?string $operationGroup = null): int {
		$lastQueueId = 0;
		if ($this->tablesToDetach->count()) {
			$lastQueueId = $this->removeTables($queue, $this->tablesToDetach->toArray(), $operationGroup);
			$this->tablesToDetach = new Set;
		}

		if ($this->tablesToAttach->count()) {
			// Group tables by the node that holds their data and run one ALTER CLUSTER ADD per
			// owner node, ON that node. Running it elsewhere replicates an empty copy over the
			// populated replicas and destroys the data (proven: ADD on a non-holder wipes it).
			/** @var Map<string,array<string>> $byNode */
			$byNode = new Map;
			foreach ($this->tablesToAttach as $table => $node) {
				$tables = $byNode->get($node, []);
				$tables[] = $table;
				$byNode->put($node, $tables);
			}
			foreach ($byNode as $node => $tables) {
				$lastQueueId = $this->addTablesOnNode($queue, $node, $tables, $operationGroup);
			}
			$this->tablesToAttach = new Map;
		}

		return $lastQueueId;
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

	/**
	/**
	 * Check if a cluster exists
	 * @param string $clusterName
	 * @return bool
	 */
	public function exists(string $clusterName): bool {
		try {
			$clusterResult = $this->client->sendRequest('SHOW CLUSTERS');
			/** @var array{0?:array{data?:array<array{cluster:string}>}} */
			$data = $clusterResult->getResult();

			if (!isset($data[0]['data'])) {
				return false;
			}

			foreach ($data[0]['data'] as $cluster) {
				if ($cluster['cluster'] === $clusterName) {
					return true;
				}
			}
		} catch (\Throwable $e) {
			Buddy::debugvv('Error checking cluster existence: ' . $e->getMessage());
		}

		return false;
	}

	/**
	 * Verify that specified tables are present in the cluster
	 * @param string $clusterName
	 * @param array<string> $tableNames
	 * @return bool
	 */
	public function verifyTablesInCluster(string $clusterName, array $tableNames): bool {
		try {
			$clusterTables = $this->getClusterTables($clusterName);
			if ($clusterTables === null) {
				return false;
			}

			foreach ($tableNames as $tableName) {
				if (!in_array($tableName, $clusterTables)) {
					return false;
				}
			}
			return true;
		} catch (\Throwable $e) {
			Buddy::debugvv('Error verifying tables in cluster: ' . $e->getMessage());
		}

		return false;
	}

	/**
	 * Get the list of tables in a cluster by name, or null if cluster not found
	 * @param string $clusterName
	 * @return array<string>|null
	 */
	protected function getClusterTables(string $clusterName): ?array {
		$clusterResult = $this->client->sendRequest('SHOW CLUSTERS');
		/** @var array{0?:array{data?:array<array{cluster:string,tables?:string}>}} */
		$data = $clusterResult->getResult();

		if (!isset($data[0]['data'])) {
			return null;
		}

		foreach ($data[0]['data'] as $cluster) {
			if ($cluster['cluster'] !== $clusterName) {
				continue;
			}
			return isset($cluster['tables'])
				? array_map('trim', explode(',', $cluster['tables']))
				: [];
		}
		return null;
	}

}
