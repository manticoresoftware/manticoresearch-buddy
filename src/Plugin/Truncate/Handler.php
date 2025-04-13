<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Truncate;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class Handler extends BaseHandlerWithClient
{

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
	 * @throws GenericError
	 */
	public function run(): Task {
		$taskFn = function (): TaskResult {
			$tableExists = $this->manticoreClient->hasTable($this->payload->table);
			if (!$tableExists) {
				throw GenericError::create(
					"Table {$this->payload->table} does not exist"
				);
			}

			$shards = $this->manticoreClient->getTableShards($this->payload->table);
			$requests = [];
			foreach ($shards as $shard) {
				$requests[] = [
					'url' => $shard['url'],
					'path' => 'sql?mode=raw',
					'request' => "DELETE FROM {$shard['name']} WHERE id > 0",
				];
			}

			$this->manticoreClient->sendMultiRequest($requests);
			return TaskResult::none();
		};

		return Task::create($taskFn)->run();
	}
}
