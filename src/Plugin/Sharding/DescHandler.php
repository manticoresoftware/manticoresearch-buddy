<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class DescHandler extends BaseHandlerWithClient {
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
		$taskFn = function (): TaskResult {

			$table = $this->payload->table;
			// TODO: think about the way to refactor it and remove duplication
			$q = "DESC {$table} OPTION force=1";
			$resp = $this->manticoreClient->sendRequest($q);
			/** @var array{0:array{data:array<array{Type:string,Agent:string}>}} $result */
			$result = $resp->getResult();
			$shard = null;
			foreach ($result[0]['data'] as $row) {
				if ($row['Type'] === 'local') {
					$shard = $row['Agent'];
					break;
				}
			}
			if (!isset($shard)) {
				return TaskResult::withError('Failed to find structure from local shards');
			}

			$q = match ($this->payload->type) {
				'show' => "SHOW CREATE TABLE {$shard}",
				'desc', 'describe' => "DESC {$shard}",
				default => throw new RuntimeException("Unknown type: {$this->payload->type}"),
			};
			$resp = $this->manticoreClient->sendRequest($q);
			return TaskResult::fromResponse($resp);
		};
		$task = Task::create($taskFn, []);
		return $task->run();
	}
}
