<?php declare(strict_types=1);

/*
	Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License version 3 or any later
	version. You should have received a copy of the GPL license along with this
	program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Closure;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Permissions;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;
use Swoole\Coroutine;

final class DropHandler extends BaseHandlerWithClient {
	/** Client that runs internal sharding meta queries as system.buddy */
	private Client $systemClient;

	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$this->systemClient = $this->manticoreClient->getSystemClient();
		$task = $this->validate();
		if ($task) {
			return $task;
		}
		$taskFn = $this->getShardingFn();
		$task = Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		);
		$args = $this->payload->toHookArgs();
		$task->on('run', fn() => static::runInBackground($args));
		return $task->run();
	}

	/**
	 * @param array{table:array{name:string,cluster:string}} $args
	 * @return void
	 */
	public static function runInBackground(array $args): void {
		$processor = Payload::getProcessors()[0];
		$processor->execute('drop', $args);

		$table = $args['table']['name'];
		$processor->addTicker(fn() => $processor->status($table), 1);
	}

	/**
	 * Validate the request and return Task with error or null if ok
	 * @return ?Task
	 */
	protected function validate(): ?Task {
		// The actual drop is async and runs as system.buddy, so the daemon
		// cannot enforce the user's permissions later: gate here, before any
		// state is touched or anything is enqueued.
		$isAllowed = Permissions::isActionAllowed(
			$this->systemClient, $this->payload->user, Permissions::ACTION_SCHEMA, $this->payload->table
		);
		if (!$isAllowed) {
			return $this->getErrorTask(
				"Permission denied for user '{$this->payload->user}': "
				. "requires schema permission on table '{$this->payload->table}'"
			);
		}

		// Try to validate that we do not create the same table we have
		$q = "SHOW CREATE TABLE {$this->payload->table} OPTION force=1";
		$resp = $this->systemClient->sendRequest($q);
		/**
		 * @var array{
		 *  0: array{
		 *   data?: array{
		 *    0: array{
		 *     Table: string,
		 *     "Create Table": string
		 *    }
		 *   }
		 *  }
		 * } $result
		 */
		$result = $resp->getResult();
		if (!isset($result[0]['data'][0])) {
			// In case quiet mode we have IF EXISTS so do nothing
			if ($this->payload->quiet) {
				return $this->getNoneTask();
			}
			return $this->getErrorTask(
				"table '{$this->payload->table}' is missing: "
					. 'DROP TABLE failed: '
					."table '{$this->payload->table}' must exist"
			);
		}

		if (false === stripos($result[0]['data'][0]['Create Table'], "type='shard'")) {
			// A cluster prefix on a non-sharded table is usually a regular
			// replicated RT table that was added via ALTER CLUSTER ... ADD.
			// Point the user at the correct command instead of leaking our
			// internal "must be sharded" error.
			if ($this->payload->cluster !== '') {
				return $this->getErrorTask(
					"table '{$this->payload->table}' is a regular table in cluster "
					. "'{$this->payload->cluster}': "
					. "use ALTER CLUSTER {$this->payload->cluster} DROP {$this->payload->table} "
					. "to remove it from the cluster, then DROP TABLE {$this->payload->table}"
				);
			}
			return $this->getErrorTask(
				"table '{$this->payload->table}' is not sharded: "
					. 'DROP SHARDED TABLE failed: '
					."table '{$this->payload->table}' must be sharded"
			);
		}

		// In case we have no state, means table is not sharded
		$state = $this->getTableState($this->payload->table);
		if (!$state) {
			return $this->getErrorTask(
				"table '{$this->payload->table}' is not sharded: "
					. 'DROP SHARDED TABLE failed: '
					."table '{$this->payload->table}' must have been created with sharding enabled"
			);
		}

		// If the sharded table belongs to a cluster, require the user to
		// reference it with the cluster prefix so the drop is unambiguous.
		$actualCluster = $this->getTableCluster($this->payload->table);
		if ($actualCluster !== '' && $actualCluster !== $this->payload->cluster) {
			return $this->getErrorTask(
				"table '{$this->payload->table}' belongs to cluster "
				. "'{$actualCluster}': use DROP TABLE {$actualCluster}:{$this->payload->table}"
			);
		}

		// Refuse to drop a clustered sharded table while any of its cluster
		// members are unreachable. Otherwise the synchronous metadata DELETE
		// commits, queue items stall on the dead node, and the alive nodes
		// are left with a public wrapper + physical shards + no metadata to
		// re-issue the DROP against (FAIL_020). Better to ask the operator
		// to bring the missing node up first.
		if ($actualCluster !== '') {
			$err = $this->checkClusterFullyReachable($actualCluster);
			if ($err !== null) {
				return $this->getErrorTask($err);
			}
		}

		return null;
	}

	/**
	 * Refuse the DROP unless the cluster is fully healthy for writes:
	 * - status='primary' (cluster has quorum and a primary view),
	 * - local node_state='synced' (we're not mid-SST / desynced),
	 * - size == count(nodes_set) (no member is currently unreachable).
	 *
	 * Returning null means "go ahead". Any non-null string is the
	 * user-facing error explaining which condition failed.
	 *
	 * @param string $cluster
	 * @return ?string
	 */
	protected function checkClusterFullyReachable(string $cluster): ?string {
		/** @var array{0:array{data?:array<array{Counter:string,Value:string}>}} $res */
		$res = $this->systemClient
			->sendRequest("SHOW STATUS LIKE 'cluster_{$cluster}_status'")
			->getResult();
		$status = $res[0]['data'][0]['Value'] ?? '';
		if ($status !== '' && $status !== 'primary') {
			return "cluster '{$cluster}' is not in primary view (status='{$status}'): "
				. 'refusing to DROP — retry when the cluster is fully synced';
		}

		/** @var array{0:array{data?:array<array{Counter:string,Value:string}>}} $res */
		$res = $this->systemClient
			->sendRequest("SHOW STATUS LIKE 'cluster_{$cluster}_node_state'")
			->getResult();
		$nodeState = $res[0]['data'][0]['Value'] ?? '';
		if ($nodeState !== '' && $nodeState !== 'synced') {
			return "cluster '{$cluster}' local node is not synced "
				. "(node_state='{$nodeState}'): refusing to DROP — "
				. 'retry when the cluster is fully synced';
		}

		/** @var array{0:array{data?:array<array{Counter:string,Value:string}>}} $res */
		$res = $this->systemClient
			->sendRequest("SHOW STATUS LIKE 'cluster_{$cluster}_nodes_set'")
			->getResult();
		$nodesSet = $res[0]['data'][0]['Value'] ?? '';
		if ($nodesSet === '') {
			return null;
		}
		$expected = sizeof(array_filter(array_map('trim', explode(',', $nodesSet))));

		/** @var array{0:array{data?:array<array{Counter:string,Value:string}>}} $res */
		$res = $this->systemClient
			->sendRequest("SHOW STATUS LIKE 'cluster_{$cluster}_size'")
			->getResult();
		$size = (int)($res[0]['data'][0]['Value'] ?? 0);

		if ($size < $expected) {
			return "cluster '{$cluster}' has unreachable members "
				. "({$size} of {$expected} alive): refusing to DROP — "
				. 'retry when the cluster is fully synced';
		}
		return null;
	}

	/**
	 * Look up which replication cluster a sharded table belongs to via
	 * system.sharding_table. Returns '' when not clustered.
	 *
	 * @param string $table
	 * @return string
	 */
	protected function getTableCluster(string $table): string {
		$q = "SELECT cluster FROM system.sharding_table WHERE `table` = '{$table}' LIMIT 1";
		$resp = $this->systemClient->sendRequest($q);
		/** @var array{0:array{data?:array{0:array{cluster:string}}}} $result */
		$result = $resp->getResult();
		return $result[0]['data'][0]['cluster'] ?? '';
	}

	/**
	 * @param string $table
	 * @return array{result:string,status?:string,type?:string}|array{}
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	protected function getTableState(string $table): array {
		// TODO: think about the way to refactor it and remove duplication
		$q = "select value[0] as value from system.sharding_state where `key` = 'table:{$table}'";
		$resp = $this->systemClient->sendRequest($q);

		/** @var array{0:array{data?:array{0:array{value:string}}}} $result */
		$result = $resp->getResult();

		if (isset($result[0]['data'][0]['value'])) {
			/** @var array{result:string,status?:string,type?:string} $value */
			$value = simdjson_decode($result[0]['data'][0]['value'], true);
		}
		return $value ?? [];
	}

	/**
	 * Get and run task that we should run on error
	 * @param string $message
	 * @return Task
	 */
	protected function getErrorTask(string $message): Task {
		$taskFn = static function (string $message): TaskResult {
			return TaskResult::withError($message);
		};
		return Task::create(
			$taskFn, [$message]
		)->run();
	}

	/**
	 * Get none task that does nothing for if exists
	 * @return Task
	 */
	protected function getNoneTask(): Task {
		$taskFn = static function (): TaskResult {
			return TaskResult::none();
		};
		return Task::create(
			$taskFn, []
		)->run();
	}

	/**
	 * Get task function for handling sharding case
	 * @return Closure
	 */
	protected function getShardingFn(): Closure {
		return function (): TaskResult {
			$ts = time();
			$timeout = $this->payload->getShardingTimeout();
			while (true) {
				$state = $this->getTableState($this->payload->table);
				if ($state) {
					$type = $state['type'] ?? 'unknown';
					$status = $state['status'] ?? 'processing';
					if ($type === 'drop' && $status !== 'processing') {
						return TaskResult::fromResponse(Response::fromBody($state['result']));
					}
				}
				if ((time() - $ts) > $timeout) {
					break;
				}
				Coroutine::sleep(0.6);
			}
			return TaskResult::withError('Waiting timeout exceeded.');
		};
	}
}
