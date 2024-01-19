<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\ModifyTable;

use Closure;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;
use Swoole\Coroutine;

final class Handler extends BaseHandlerWithClient {
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
		$withSharding = $this->payload->withSharding();

		// Validate that table does not exist first
		if ($withSharding) {
			$task = $this->validate();
			if ($task) {
				return $task;
			}
		}

		// We are blocking until final state and return the results
		$taskFn = $withSharding ? static::getShardingFn() : static::getDefaultFn();

		$task = Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		);

		if ($withSharding) {
			$args = $this->payload->toShardArgs();
			$task->on('run', fn() => static::processHook('shard', [$args]));
		}

		return $task->run();
	}

	/**
	 * Validate the request and return Task with error or null if ok
	 * @return ?Task
	 */
	protected function validate(): ?Task {
		// Try to validate that we do not create the same table we have
		$q = "SHOW CREATE TABLE {$this->payload->table}";
		$resp = $this->manticoreClient->sendRequest($q);
		/** @var array{0:array{data?:array{0:array{value:string}}}} $result */
		$result = $resp->getResult();
		if (isset($result[0]['data'][0])) {
			return static::getErrorTask(
				"table '{$this->payload->table}': CREATE TABLE failed: table '{$this->payload->table}' already exists"
			);
		}

		$nodeCount = 1;
		// Check that cluster exists
		if ($this->payload->cluster) {
			/** @var array{0:array{data?:array{0:array{Value:string}}}} $result */
			$result = $this->manticoreClient
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
			while (true) {
				$q = "select `value` from sharding_state where `key` = 'table:{$payload->table}'";
				$resp = $client->sendRequest($q);
				$result = $resp->getResult();
				/** @var array{0:array{data?:array{0:array{value:string}}}} $result */
				if (isset($result[0]['data'][0]['value'])) {
					$value = json_decode($result[0]['data'][0]['value'], true);
				}
				/** @var array{result:string,status?:string} $value */
				$status = $value['status'] ?? 'processing';
				if ($status !== 'processing') {
					return TaskResult::raw($value['result']);
				}
				if ((time() - $ts) > 30) {
					break;
				}
				Coroutine::sleep(1);
			}
			return TaskResult::withError('Waiting timeout exceeded.');
		};
	}

	/**
	 * Get defatul task handle function that just proxy the query
	 * @return Closure
	 */
	protected static function getDefaultFn(): Closure {
		return static function (Payload $payload, Client $client): TaskResult {
			$q = "{$payload->type} table {$payload->table} {$payload->structure} {$payload->extra}";
			$result = $client->sendRequest($q)->getResult();
			return TaskResult::raw([$result]);
		};
	}
}
