<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/


namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

/**
 * @template T of array
 */
abstract class BaseDropHandler extends BaseHandlerWithClient {

	/**
	 * Initialize the executor
	 *
	 * @param Payload<T> $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request
	 * @return Task
	 */
	public function run(): Task {

		$name = $this->getName($this->payload);
		$tableName = $this->getTableName();

		/**
		 * @param string $name
		 * @param string $tableName
		 * @return TaskResult
		 */
		$taskFn = function (string $name, string $tableName): TaskResult {
			$manticoreClient = $this->manticoreClient;
			if (!$manticoreClient->hasTable($tableName)) {
				return TaskResult::none();
			}

			return TaskResult::withTotal($this->processDrop($name, $tableName));
		};

		return Task::create(
			$taskFn,
			[$name, $tableName]
		)->run();
	}


	abstract protected function processDrop(string $name, string $tableName): int;

	/**
	 * @param Payload<T> $payload
	 * @return string
	 */
	abstract protected function getName(Payload $payload): string;

	abstract protected function getTableName(): string;

}
