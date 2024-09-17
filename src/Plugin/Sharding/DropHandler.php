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
		$task->on('run', fn() => static::processHook('sharding:drop', [$args]));
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
		if (!isset($result[0]['data'][0])) {
			return static::getErrorTask(
				"table '{$this->payload->table}' is missing: "
					. 'DROP SHARDED TABLE failed: '
					."table '{$this->payload->table}' must exists"
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
			$timeout = $payload->getShardingTimeout();
			while (true) {
				// TODO: think about the way to refactor it and remove duplication
				$q = "select `value` from _sharding_state where `key` = 'table:{$payload->table}'";
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
				if ((time() - $ts) > $timeout) {
					break;
				}
				Coroutine::sleep(1);
			}
			return TaskResult::withError('Waiting timeout exceeded.');
		};
	}
}
