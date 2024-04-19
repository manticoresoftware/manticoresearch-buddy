<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Show;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithTableFormatter;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskPool;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class QueriesHandler extends BaseHandlerWithTableFormatter {
	const COL_MAP = [
		'connid' => 'id',
		'last cmd' => 'query',
		'last cmd time' => 'time',
		'proto' => 'protocol',
		'host' => 'host',
	];

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
		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$taskFn = static function (
			Payload $payload,
			Client $manticoreClient,
			TableFormatter $tableFormatter,
			array $tasks
		): TaskResult {
			// First, get response from the manticore
			$time0 = hrtime(true);
			$resp = $manticoreClient->sendRequest(
				'SELECT * FROM @@system.sessions',
				$payload->path
			);
			$result = static::formatResponse($resp->getBody());
			// Second, get our own queries and append to the final result
			/** @var array{0:array{data:array<mixed>,total:int}} $result */
			$result[0]['data'] = array_merge($result[0]['data'], $tasks);
			$result[0]['total'] += sizeof($tasks);
			if ($payload->hasCliEndpoint) {
				return TaskResult::raw($tableFormatter->getTable($time0, $result[0]['data'], $result[0]['total']));
			}
			return TaskResult::raw($result);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient, $this->tableFormatter, static::getTasksToAppend()]
		)->run();
	}

	/**
	 * Process the results
	 *
	 * @param string $origResp
	 * @return array<mixed>
	 */
	public static function formatResponse(string $origResp): array {
		$struct = [
			'columns' => [
				['id' => [
					'type' => 'long long',
				],
				],
				['query' => [
					'type' => 'string',
				],
				],
				['time' => [
					'type' => 'string',
				],
				],
				['protocol' => [
					'type' => 'string',
				],
				],
				['host' => [
					'type' => 'string',
				],
				],
			],
			'data' => [],
			'total' => 0,
			'error' => '',
			'warning' => '',
		];
		$resp = (array)json_decode($origResp, true);
		/** @var array{0:array{error?:string,warning?:string,data?:array<array<string,mixed>>}} $resp */
		if (isset($resp[0]['data'])) {
			$struct['error'] = $resp[0]['error'] ?? '';
			$struct['warning'] = $resp[0]['warning'] ?? '';
			foreach ($resp[0]['data'] as $row) {
				++$struct['total'];
				$newRow = [];
				foreach (static::COL_MAP as $oldKey => $newKey) {
					if (!isset($row[$oldKey])) {
						continue;
					}

					$newRow[$newKey] = $row[$oldKey];
				}
				$struct['data'][] = $newRow;
			}
		}
		return [$struct];
	}

	/**
	 * This method appends our running queries from global state to result
	 *
	 * @return array<array{id:int,protocol:string,host:string,query:string}>
	 */
	protected static function getTasksToAppend(): array {
		$data = [];
		/** @var array<array{id:int,body:string,http:string,host:string}> $tasks */
		$tasks = TaskPool::getList();
		/** @var array{id:int,protocol:string,host:string,body:string} $task */
		foreach ($tasks as $task) {
			// ! same order as in COL_MAP
			$data[] = [
				'id' => $task['id'],
				'query' => $task['body'],
				'time' => '0us',
				'protocol' => 'http',
				'host' => $task['host'],
			];
		}

		return $data;
	}
}
