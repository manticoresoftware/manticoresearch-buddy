<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\Plugin\BaseHandler;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class ClusterHealthHandler extends BaseHandler {

	/**
	 *  Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {

		$taskFn = static function (): TaskResult {
			return TaskResult::raw(
				[
					'cluster_name' =>'docker-cluster',
					'status' =>'yellow',
					'timed_out' => false,
					'number_of_nodes' => 1,
					'number_of_data_nodes' => 1,
					'active_primary_shards' => 1,
					'active_shards' => 1,
					'relocating_shards' => 0,
					'initializing_shards' => 0,
					'unassigned_shards' => 0,
					'delayed_unassigned_shards' => 0,
					'number_of_pending_tasks' => 0,
					'number_of_in_flight_fetch' => 0,
					'task_max_waiting_in_queue_millis' => 0,
					'active_shards_percent_as_number' => 100,
				]
			);
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return [];
	}

}
