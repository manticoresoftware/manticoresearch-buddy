<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\ShowQueries;

use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Lib\Task;
use Manticoresearch\Buddy\Lib\TaskPool;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class Executor implements CommandExecutorInterface {
	const COL_MAP = [
		'connid' => 'id',
		'last cmd' => 'query',
		'proto' => 'proto',
		'host' => 'host',
	];

	/** @var HTTPClient $manticoreClient */
	protected HTTPClient $manticoreClient;

	/**
	 *  Initialize the executor
	 *
	 * @param Request $request
	 * @return void
	 */
	public function __construct(public Request $request) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$this->manticoreClient->setEndpoint($this->request->endpoint);

		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$taskFn = function (Request $request, HTTPClient $manticoreClient, array $tasks): array {
			// First, get response from the manticore
			$resp = $manticoreClient->sendRequest($request->query);
			$result = static::formatResponse($resp->getBody());
			// Second, get our own queries and append to the final result
			/** @var array{0:array{data:array<mixed>,total:int}} $result */
			$result[0]['data'] = array_merge($result[0]['data'], $tasks);
			$result[0]['total'] += sizeof($tasks);
			return $result;
		};
		return Task::create(
			$taskFn, [$this->request, $this->manticoreClient, static::getTasksToAppend()]
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
				['proto' => [
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
	 * @return array<array{id:int,proto:string,host:string,query:string}>
	 */
	protected static function getTasksToAppend(): array {
		$data = [];
		$tasks = TaskPool::getList();
		foreach ($tasks as $task) {
			$data[] = [
				'id' => $task->getId(),
				'proto' => 'http',
				'host' => $task->getHost(),
				'query' => $task->getBody(),
			];
		}

		return $data;
	}

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return ['manticoreClient'];
	}

	/**
	 * Instantiating the http client to execute requests to Manticore server
	 *
	 * @param HTTPClient $client
	 * $return HTTPClient
	 */
	public function setManticoreClient(HTTPClient $client): HTTPClient {
		$this->manticoreClient = $client;
		return $this->manticoreClient;
	}
}
