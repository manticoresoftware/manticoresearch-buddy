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
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;
use Swoole\Coroutine;

final class DropHandler extends BaseHandlerWithClient {
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
		// Try to validate that we do not create the same table we have
		$q = "SHOW CREATE TABLE {$this->payload->table}";
		$resp = $this->manticoreClient->sendRequest($q);
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
			return static::getErrorTask(
				"table '{$this->payload->table}' is missing: "
					. 'DROP SHARDED TABLE failed: '
					."table '{$this->payload->table}' must exist"
			);
		}

		if (false === stripos($result[0]['data'][0]['Create Table'], "type='distributed'")) {
			return static::getErrorTask(
				"table '{$this->payload->table}' is not distributed: "
					. 'DROP SHARDED TABLE failed: '
					."table '{$this->payload->table}' must be distributed"
			);
		}

		// In case we have no state, means table is not sharded
		$state = $this->getTableState($this->payload->table);
		if (!$state) {
			return static::getErrorTask(
				"table '{$this->payload->table}' is not sharded: "
					. 'DROP SHARDED TABLE failed: '
					."table '{$this->payload->table}' be created with sharding"
			);
		}

		return null;
	}

	/**
	 * @param string $table
	 * @return array{result:string,status?:string,type?:string}|array{}
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	protected function getTableState(string $table): array {
		// TODO: think about the way to refactor it and remove duplication
		$q = "select value[0] as value from _sharding_state where `key` = 'table:{$table}'";
		$resp = $this->manticoreClient->sendRequest($q);

		/** @var array{0:array{data?:array{0:array{value:string}}}} $result */
		$result = $resp->getResult();

		if (isset($result[0]['data'][0]['value'])) {
			/** @var array{result:string,status?:string,type?:string} $value */
			$value = json_decode($result[0]['data'][0]['value'], true);
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
