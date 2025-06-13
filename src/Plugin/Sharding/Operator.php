<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;
use RuntimeException;

final class Operator {
	public ?Cluster $cluster;
	public Node $node;
	public State $state;
	public ?Queue $queue;

	/**
	 * Create operator instance with current node and state
	 * @param Client $client
	 * @param string $clusterName
	 * @erturn void
	 */
	public function __construct(
		public readonly Client $client,
		public readonly string $clusterName
	) {
		$this->state = new State($this->client);
		$this->node = new Node($this->state, Node::findId($this->client));
	}

	/**
	 * Just extra function that is required to run once all setup
	 * This function sets additional cluster and queue property
	 * that we cannot set on other stages and can do only after
	 * we know which cluster we run or not at all
	 * @return static
	 */
	protected function init(): static {
		/** @var string */
		$clusterName = $this->state->get('cluster');
		Buddy::debug("Sharding: initializing under cluster {$clusterName}");
		$this->cluster = new Cluster(
			$this->client,
			$clusterName,
			Node::findId($this->client),
		);
		$this->queue = new Queue($this->cluster, $this->client);
		$this->state->setCluster($this->cluster);
		return $this;
	}

	/**
	 * This method just update last seen_at for node
	 * @return static
	 */
	public function heartbeat(): static {
		$this->node->update(['seen_at' => time()]);
		return $this;
	}

	/**
	 * Check if master has allowed gap and if not just take over it
	 * @return static
	 */
	public function checkMaster(): static {
		/** @var string */
		$master = $this->state->get('master');
		$nodeId = $this->node->id;
		// We do nothing in case we validate master on the current master node
		// because in that case we are 100% alive
		if ($master === $nodeId) {
			return $this;
		}

		// If the master node is inactive, we are taking it over
		$cluster = $this->getCluster();
		$inactiveNodes = $cluster->getInactiveNodes();
		$isMasterInactive = $inactiveNodes->contains($master);

		// If master is inactive validate gap and select next node
		// that will become a new master
		if ($isMasterInactive) {
			Buddy::info("The master is inactive: {$master}");
			$now = time();
			$masterNode = new Node($this->state, $master);
			$gap = $now - $masterNode->seenAt;
			$candidates = $cluster->getNodes()->diff($inactiveNodes);
			if ($nodeId === $candidates->sorted()->first() && $gap >= 5) {
				$this->becomeMaster();
			}
		}

		return $this;
	}

	/**
	 * Validate the balance of replication and rebalance it when required
	 * This method should be called on master node only
	 * @return static
	 */
	public function checkBalance(): static {
		$cluster = $this->getCluster();
		$queue = $this->getQueue();
		$allNodes = $cluster->getNodes();

		// We get inactive nodes to exclude them from the rebalance in case of outages
		// It may be an empty list if we're adding a new node to the cluster, which is fine
		$inactiveNodes = $cluster->getInactiveNodes();
		$activeNodes = $allNodes->diff($inactiveNodes);

		// Check if cluster topology changed (failed nodes OR new nodes)
		$clusterHash = Cluster::getNodesHash($activeNodes);
		$currentHash = $this->state->get('cluster_hash');

		// If no topology change, nothing to do
		if ($clusterHash === $currentHash) {
			return $this;
		}

		// Topology changed - determine what kind of change
		if ($inactiveNodes->count() > 0) {
			Buddy::info("Rebalancing due to inactive nodes: {$inactiveNodes->join(', ')}");
		} else {
			Buddy::info('Rebalancing due to cluster topology change (likely new nodes)');
		}

		// Get all tables from the state we have and rebalance them
		$list = $this->state->listRegex('table:.+');
		foreach ($list as $row) {
			/** @var array{key:string,value:array{name:string,structure:string,extra:string}} $row */
			[, $name]  = explode(':', $row['key']);
			Buddy::info(" table: $name");
			$structure = $row['value']['structure'];
			$extra = $row['value']['extra'];
			$table = new Table(
				$this->client,
				$cluster,
				$name,
				$structure,
				$extra
			);

			$table->rebalance($queue);
		}

		// Update nodes state and remove inactive once to secure logic
		$this->state->set('cluster_hash', $clusterHash);

		return $this;
	}

	/**
	 * Make current node as master node
	 * @return static
	 */
	protected function becomeMaster(): static {
		Buddy::info('becoming master');
		$this->state->set('master', $this->node->id);
		return $this;
	}

	/**
	 * Check if the sharding is active
	 * This method also does a bit trick and initialize queue and cluster
	 * That we could not initialize before
	 * @return bool
	 */
	public function hasSharding(): bool {
		$hasSharding = $this->state->isActive();
		// If sharding is active and still no cluster
		// Detect and set it from the state
		if ($hasSharding && !isset($this->cluster)) {
			$this->init();
		}
		return $hasSharding;
	}

	/**
	 * Process choose event received from external world to thread
	 * @param array{cluster:string,name:string,structure:string,extra:string} $table
	 * @param int $shardCount
	 * @param int $replicationFactor
	 * @return void
	 */
	public function shard(
		array $table,
		int $shardCount,
		int $replicationFactor
	): void {
		$cluster = new Cluster(
			$this->client,
			$table['cluster'],
			Node::findId($this->client),
		);
		unset($table['cluster']);
		$table = new Table($this->client, $cluster, ...$table);
		$shouldSetup = !$this->state->get('master');
		if ($shouldSetup) {
			Buddy::debug("Sharding: setuping cluster {$cluster->name}");
			$this->state->setCluster($cluster);
			$this->state->setup();
			$this->becomeMaster();
			$table->setup();

			// Set initial cluster we used
			$this->state->set('cluster', $cluster->name);
			$this->state->set('cluster_hash', Cluster::getNodesHash($cluster->getNodes()));
			$this->init();
			$this->getQueue()->setup();
		}

		if (!isset($this->cluster)) {
			$this->init(); // Initialize if no cluster set
		}

		// Initialize if we created cluster after local init
		$currentCluster = $this->getCluster();
		if (!$currentCluster->name && $cluster->name !== $currentCluster->name) {
			$this->state->set('cluster', $cluster->name);
			$this->state->set('cluster_hash', Cluster::getNodesHash($cluster->getNodes()));
			$this->init();
			$currentQueue = $this->getQueue();
			$cluster->attachTables($this->state->table, $currentQueue->table, $table->table);
		}

		$result = $table->shard(
			$this->getQueue(),
			$shardCount,
			$replicationFactor,
		);

		// Set state and run detached process to poll
		$this->state->set("table:{$table->name}", $result);
	}

	/**
	 * Drop table the whole sharded table
	 * @param array{name:string,cluster:string} $table
	 * @return void
	 */
	public function drop(array $table): void {
		// Get the current stats first
		/** @var array{result:string,status:string,structure:string,extra:string}|null $currentState */
		$currentState = $this->state->get("table:{$table['name']}");
		if (!$currentState) {
			return;
		}

		// Prepare cluster and Table to operate with
		$cluster = new Cluster(
			$this->client,
			$table['cluster'],
			Node::findId($this->client),
		);
		$table = new Table(
			$this->client,
			$cluster,
			$table['name'],
			$currentState['structure'],
			$currentState['extra']
		);
		$result = $table->drop($this->getQueue());
		$this->state->set("table:{$table->name}", $result);
	}

	/**
	 * Helper to run table status checker on pings
	 * It should return true when we done or false to repeat
	 * @param  string $table
	 * @return bool
	 */
	public function checkTableStatus(string $table): bool {
		$stateKey = "table:{$table}";
		/** @var array{}|array{queue_ids:array<int>,status:string,type:string} */
		$result = $this->state->get($stateKey);
		Buddy::debugvv("Sharding: table status of {$table}: " . json_encode($result));
		if (!$result) {
			return false;
		}

		$queueSize = sizeof($result['queue_ids']);
		$processed = 0;
		foreach ($result['queue_ids'] as $id) {
			$row = $this->getQueue()->getById($id);
			if (!$row || $row['status'] === 'created') {
				continue;
			}

			++$processed;
		}
		Buddy::debugvv("Sharding: table status of {$table}: queue size: {$queueSize}, processed: {$processed}");

		$isProcessed = $processed === $queueSize;
		// Update the state
		if ($isProcessed) {
			$result['status'] = 'done';
			$result['result'] = ConfigManager::getInt('DEBUG') && $result['type'] === 'create'
			? $this->client->sendRequest("SHOW CREATE TABLE {$table} OPTION force=1")->getBody()
			: TaskResult::none()->toString();
			$this->state->set($stateKey, $result);
		}

		return $isProcessed;
	}
	/**
	 * Wrapper around process queue with current node
	 * @return static
	 */
	public function processQueue(): static {
		if (!isset($this->queue)) {
			throw new RuntimeException('Queue is not initialized');
		}

		$this->queue->process($this->node);
		return $this;
	}

	/**
	 * @return Queue
	 */
	public function getQueue(): Queue {
		if (!isset($this->queue)) {
			throw new RuntimeException('Queue not initialized');
		}

		return $this->queue;
	}

	/**
	 * @return Cluster
	 */
	public function getCluster(): Cluster {
		if (!isset($this->cluster)) {
			throw new RuntimeException('Cluster not initialized');
		}

		return $this->cluster;
	}
}
