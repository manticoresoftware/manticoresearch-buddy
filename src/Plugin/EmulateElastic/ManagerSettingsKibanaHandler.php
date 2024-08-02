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
class ManagerSettingsKibanaHandler extends BaseHandler {

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

		$queryMap = [
			'.kibana_task_manager/_update_by_query' => [
				'batches' => 0,
				'deleted' => 0,
				'failures' => [],
				'noops' => 0,
				'requests_per_second' => -1.0,
				'retries' => [
					'bulk' => 0,
					'search' => 0,
				],
				'throttled_millis' => 0,
				'throttled_until_millis' => 0,
				'timed_out' => false,
				'took' => 0,
				'total' => 0,
				'updated' => 0,
				'version_conflicts' => 0,
			],
			'.kibana_task_manager' => [
				'.kibana_task_manager_1' => [
					'aliases' => [
						'.kibana_task_manager' => [],
					],
					'mappings' => [
						'dynamic' => 'strict',
						'_meta' => [
							'migrationMappingPropertyHashes' => [
								'migrationVersion' => '4a1746014a75ade3a714e1db5763276f',
								'task' => '235412e52d09e7165fac8a67a43ad6b4',
								'updated_at' => '00da57df13e94e9d98437d13ace4bfe0',
								'references' => '7997cf5a56cc02bdc9c93361bde732b0',
								'namespace' => '2f4316de49999235636386fe51dc06c1',
								'type' => '2f4316de49999235636386fe51dc06c1',
								'config' => '87aca8fdb053154f11383fce3dbf3edf',
							],
						],
						'properties' => [
							'config' => [
								'dynamic' => 'true',
								'properties' => [
									'buildNum' => [
										'type' => 'keyword',
									],
								],
							],
							'migrationVersion' => [
								'dynamic' => 'true',
								'properties' => [
									'task' => [
										'type' => 'text',
										'fields' => [
											'keyword' => [
												'type' => 'keyword',
												'ignore_above' => 256,
											],
										],
									],
								],
							],
							'namespace' => [
								'type' => 'keyword',
							],
							'references' => [
								'type' => 'nested',
								'properties' => [
									'id' => [
										'type' => 'keyword',
									],
									'name' => [
										'type' => 'keyword',
									],
									'type' => [
										'type' => 'keyword',
									],
								],
							],
							'task' => [
								'properties' => [
									'attempts' => [
										'type' => 'integer',
									],
									'ownerId' => [
										'type' => 'keyword',
									],
									'params' => [
										'type' => 'text',
									],
									'retryAt' => [
										'type' => 'date',
									],
									'runAt' => [
										'type' => 'date',
									],
									'schedule' => [
										'properties' => [
											'interval' => [
												'type' => 'keyword',
											],
										],
									],
									'scheduledAt' => [
										'type' => 'date',
									],
									'scope' => [
										'type' => 'keyword',
									],
									'startedAt' => [
										'type' => 'date',
									],
									'state' => [
										'type' => 'text',
									],
									'status' => [
										'type' => 'keyword',
									],
									'taskType' => [
										'type' => 'keyword',
									],
									'user' => [
										'type' => 'keyword',
									],
								],
							],
							'type' => [
								'type' => 'keyword',
							],
							'updated_at' => [
								'type' => 'date',
							],
						],
					],
					'settings' => [
						'index' => [
							'number_of_shards' => '1',
							'auto_expand_replicas' => '0-1',
							'provided_name' => '.kibana_task_manager_1',
							'creation_date' => '1712030677238',
							'number_of_replicas' => '0',
							'uuid' => 'MSrKYfaHRcqh1k_g0ZWAXQ',
							'version' => [
								'created' => '7060099',
							],
						],
					],
				],
			],
		];

		if (!isset($queryMap[$this->payload->path])) {
			throw new \Exception("Unknown request path passed: {$this->payload->path}");
		}

		$taskFn = static function (array $resp): TaskResult {
			return TaskResult::raw($resp);
		};

		return Task::create($taskFn, [$queryMap[$this->payload->path]])->run();
	}

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return [];
	}

}
