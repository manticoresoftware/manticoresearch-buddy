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
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskPool;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class QueriesHandler extends BaseHandlerWithClient {
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
			Client $manticoreClient,
			array $tasks
		): TaskResult {
			// First, get response from the manticore
			$resp = $manticoreClient->sendRequest(
				'SELECT * FROM @@system.sessions'
			);
			return static::buildTaskResult($resp, $tasks);
		};

		return Task::create(
			$taskFn,
			[$this->manticoreClient, static::getTasksToAppend()]
		)->run();
	}

	/**
	 * Process the results
	 *
	 * @param Response $response
	 * @param array<array{id:int,protocol:string,host:string,query:string}> $tasks
	 * @return TaskResult
	 */
	public static function buildTaskResult(Response $response, array $tasks): TaskResult {
		/** @var array<array{id:int,protocol:string,host:string,query:string}> $data */
		$data = $response->getData();
		$newData = [];
		foreach ($data as &$row) {
			$newRow = [];
			foreach (static::COL_MAP as $oldKey => $newKey) {
				if (!isset($row[$oldKey])) {
					continue;
				}

				$newRow[$newKey] = $row[$oldKey];
			}
			$newData[] = $newRow;
		}
		unset($data);
		$newData = array_merge($newData, $tasks);
		return TaskResult::withData($newData)
			->column('id', Column::Long)
			->column('query', Column::String)
			->column('time', Column::String)
			->column('protocol', Column::String)
			->column('host', Column::String);
	}

	/**
	 * This method appends our running queries from global state to result
	 *
	 * @return array<array{id:int,protocol:string,host:string,query:string,time:string}>
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
