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
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Permissions;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use RuntimeException;
use Swoole\Coroutine;

final class CreateHandler extends BaseHandlerWithClient {
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
		$task = $this->validate();
		if ($task) {
			return $task;
		}
		$taskFn = $this->getShardingFn();
		$task = Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient->getSystemClient()]
		);
		/** @var array{
		 * table:array{cluster:string,name:string,structure:string,extra:string},
		 * replicationFactor:int,
		 * shardCount:int
		 * } $args
		 */
		$args = $this->payload->toHookArgs();
		$task->on('run', fn() => static::runInBackground($args));
		return $task->run();
	}

	/**
	 * @param array{
	 * table:array{cluster:string,name:string,structure:string,extra:string},
	 * replicationFactor:int,
	 * shardCount:int
	 * } $args
	 * @return void
	 */
	public static function runInBackground(array $args): void {
		$processor = Payload::getProcessors()[0];
		$processor->execute('shard', $args);

		$table = $args['table']['name'];
		$processor->addTicker(fn() => $processor->status($table), 1);
	}

	/**
	 * Validate the request and return Task with error or null if ok
	 * @return ?Task
	 */
	protected function validate(): ?Task {
		if ($this->payload->options['rf'] < 1 || $this->payload->options['shards'] < 1) {
			return static::getErrorTask(
				'Invalid shards or rf options are set'
			);
		}

		// All sharding work is async and runs as system.buddy, so the daemon
		// cannot enforce the user's permissions later: gate here, before any
		// state is touched or anything is enqueued.
		$systemClient = $this->manticoreClient->getSystemClient();
		$isAllowed = Permissions::isActionAllowed(
			$systemClient, $this->payload->user, Permissions::ACTION_SCHEMA, $this->payload->table
		);
		if (!$isAllowed) {
			return static::getErrorTask(
				"Permission denied for user '{$this->payload->user}': "
				. "requires schema permission on table '{$this->payload->table}'"
			);
		}

		// Try to validate that we do not create the same table we have
		$q = "SHOW CREATE TABLE {$this->payload->table} OPTION force=1";
		$resp = $systemClient->sendRequest($q);
		/** @var array{0:array{data?:array{0:array{value:string}}}} $result */
		$result = $resp->getResult();
		if (isset($result[0]['data'][0])) {
			if ($this->payload->quiet) {
				return Task::create(static fn() => TaskResult::none())->run();
			}
			return static::getErrorTask(
				"table '{$this->payload->table}': CREATE TABLE failed: table '{$this->payload->table}' already exists"
			);
		}

		// Reject local sharded creation when the node is already part of a
		// replication cluster: the sharding metadata tables (sharding_table /
		// sharding_state / sharding_queue) belong to that cluster, so a local
		// CREATE goes through but its sharding_table row is silently dropped
		// (writes to a clustered table without the cluster: prefix don't land).
		// Observability ends up broken (FAIL_017), so we surface the
		// limitation up front.
		if (!$this->payload->cluster) {
			$clusterName = $this->getJoinedClusterName($systemClient);
			if ($clusterName !== '') {
				return static::getErrorTask(
					'Local sharded tables cannot be created on a node that is '
					. "part of a replication cluster ('{$clusterName}'). "
					. "Use CREATE TABLE {$clusterName}:{$this->payload->table} instead."
				);
			}
		}

		return $this->validateClusterAndRf($systemClient);
	}

	/**
	 * Validate that the requested cluster exists and has enough nodes
	 * for the requested replication factor
	 * @param Client $systemClient
	 * @return ?Task
	 */
	protected function validateClusterAndRf(Client $systemClient): ?Task {
		$nodeCount = 1;
		// Check that cluster exists
		if ($this->payload->cluster) {
			/** @var array{0:array{data?:array{0:array{Value:string}}}} $result */
			$result = $systemClient
				->sendRequest("SHOW STATUS LIKE 'cluster_{$this->payload->cluster}_nodes_view'")
				->getResult();
			if (!isset($result[0]['data'][0])) {
				return static::getErrorTask(
					"Cluster '{$this->payload->cluster}' does not exist"
				);
			}
			$nodeCount = substr_count($result[0]['data'][0]['Value'], 'replication');

			if ($nodeCount < 2) {
				return static::getErrorTask(
					"The node count for cluster {$this->payload->cluster} is too low: {$nodeCount}."
					.' You can create local sharded table.'
				);
			}
		}

		if ($nodeCount < $this->payload->options['rf']) {
			return static::getErrorTask(
				"The node count ({$nodeCount}) is lower than replication factor ({$this->payload->options['rf']})"
			);
		}

		return null;
	}

	/**
	 * Return the name of the user-visible replication cluster this node belongs
	 * to (the one that owns the sharding metadata tables), or '' when the node
	 * is standalone. The sharding plugin also creates per-shard internal
	 * clusters with md5-hash names, which we filter out here — only the
	 * cluster that contains system.sharding_table is reported.
	 *
	 * @param Client $client
	 * @return string
	 */
	protected function getJoinedClusterName(Client $client): string {
		/** @var array{0?:array{data?:array<array{Counter:string,Value:string}>}} $res */
		$res = $client
			->sendRequest("SHOW STATUS LIKE 'cluster_%_indexes'")
			->getResult();
		foreach ($res[0]['data'] ?? [] as $row) {
			if (!str_contains($row['Value'], 'system.sharding_table')) {
				continue;
			}
			if (preg_match('/^cluster_(.+)_indexes$/', $row['Counter'], $m)) {
				return $m[1];
			}
		}
		return '';
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
	 * Get task function for handling sharding case
	 * @return Closure
	 */
	protected static function getShardingFn(): Closure {
		return static function (Payload $payload, Client $client): TaskResult {
			$ts = time();
			$value = [];
			$timeout = $payload->getShardingTimeout();
			while (true) {
				// TODO: think about the way to refactor it and remove duplication
				$q = "select value[0] as value from system.sharding_state where `key` = 'table:{$payload->table}'";
				$resp = $client->sendRequest($q);
				$result = $resp->getResult();
				/** @var array{0:array{data?:array{0:array{value:string}}}} $result */
				$value = simdjson_decode($result[0]['data'][0]['value'] ?? '[]', true);

			// FLOW EXPLANATION:
			// 1. Table->shard() creates initial state with status='processing', result=null
			// 2. Operator->checkTableStatus() monitors queue completion
			// 3. When all queue items processed, sets status='done' and result=response_body
			// 4. We wait for BOTH status completion AND result to be set
			// 5. Only then we can safely call Response::fromBody() with non-null result

			/** @var array{result:?string,status?:string,type?:string} $value */
				$type = $value['type'] ?? 'unknown';
				$status = $value['status'] ?? 'processing';
				$result = $value['result'] ?? null;

			// Only proceed when:
			// - Type is 'create' (table creation)
			// - Status is not 'processing' (operation completed)
			// - Result is not null (response body is available)
				if ($type === 'create' && $status !== 'processing' && $result !== null) {
					return TaskResult::fromResponse(Response::fromBody($result));
				}
				if ((time() - $ts) > $timeout) {
					Buddy::debugvv("Sharding: CreateHandler timeout exceeded for table {$payload->table}");
					break;
				}
				Coroutine::sleep(1);
			}
			return TaskResult::withError('Waiting timeout exceeded.');
		};
	}
}
