<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Permissions;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * Handler for SHOW SHARDING MASTER
 * Shows which node is currently the sharding master process.
 */
class ShowMasterHandler extends BaseHandlerWithClient {

	/**
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Client $userClient, Client $client): TaskResult {
			$emptyResult = TaskResult::withData([])
				->column('node', Column::String)
				->column('status', Column::String);

			// The master is sharding topology info: report it only when the
			// requesting user can access at least one sharded table (SHOW
			// TABLES is permission-filtered by the daemon)
			$visibleTables = Permissions::getAccessibleTables($userClient);
			if (!in_array('shard', $visibleTables, true)) {
				return $emptyResult;
			}

			$state = new State($client);

			// Sharding not initialized yet
			if (!$state->isActive()) {
				return $emptyResult;
			}

			/** @var string $master */
			$master = $state->get('master') ?? '';

			// Determine whether the master node is currently active.
			// We use the cluster stored in state to get inactive nodes.
			/** @var string $clusterName */
			$clusterName = $state->get('cluster') ?? '';
			$cluster     = new Cluster($client, $clusterName, '');
			$inactive    = $cluster->getInactiveNodes();
			$status      = $inactive->contains($master) ? 'inactive' : 'active';

			return TaskResult::withData([['node' => $master, 'status' => $status]])
				->column('node', Column::String)
				->column('status', Column::String);
		};

		// Sharding meta lives in system.* tables that users cannot access:
		// the user client is only used for the daemon-filtered visibility probe
		return Task::create(
			$taskFn,
			[$this->manticoreClient, $this->manticoreClient->getSystemClient()]
		)->run();
	}
}
