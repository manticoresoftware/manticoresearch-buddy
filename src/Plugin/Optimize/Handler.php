<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Optimize;

use Manticoresearch\Buddy\Base\Lib\ShardSchemaTrait;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class Handler extends BaseHandlerWithClient
{
	use ShardSchemaTrait;

	public function __construct(public Payload $payload) {
	}

	public function run(): Task {
		$taskFn = function (): TaskResult {
			if (!$this->manticoreClient->hasTable($this->payload->table)) {
				throw GenericError::create(
					"Table {$this->payload->table} does not exist"
				);
			}

			$shards = $this->getShards($this->manticoreClient, $this->payload->table);
			$requests = [];
			foreach ($shards as $shard) {
				$requests[] = [
					'url' => $shard['url'],
					'path' => 'sql?mode=raw',
					'request' => "OPTIMIZE TABLE {$shard['name']}",
				];
			}

			$this->manticoreClient->sendMultiRequest($requests);
			return TaskResult::none();
		};

		return Task::create($taskFn)->run();
	}
}
