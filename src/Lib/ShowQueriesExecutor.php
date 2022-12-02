<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Lib\ManticoreHTTPClient;
use Manticoresearch\Buddy\Lib\ShowQueriesRequest;
use Manticoresearch\Buddy\Lib\Task;
use Manticoresearch\Buddy\Lib\TaskPool;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class ShowQueriesExecutor implements CommandExecutorInterface {
	/** @var ManticoreHTTPClient $manticoreClient */
	protected ManticoreHTTPClient $manticoreClient;

	/**
	 *  Initialize the executor
	 *
	 * @param ShowQueriesRequest $request
	 * @return void
	 */
	public function __construct(public ShowQueriesRequest $request) {
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
		$taskFn = function (ShowQueriesRequest $request, ManticoreHTTPClient $manticoreClient, array $tasks): array {
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
		$allowedFields = ['id', 'query', 'host', 'proto'];
		$colNameMap = ['connid' => 'id', 'last cmd' => 'query'];
		$resp = (array)json_decode($origResp, true);
		// Updating column names in 'data' field
		foreach ($colNameMap as $k => $v) {
			$resp[0] = (array)$resp[0];
			$resp[0]['data'] = (array)$resp[0]['data'];
			$resp[0]['data'][0] = (array)$resp[0]['data'][0];
			$resp[0]['data'][0][$v] = $resp[0]['data'][0][$k];
		}
		$resp[0]['data'][0] = array_filter(
			$resp[0]['data'][0],
			function ($k) use ($allowedFields) {
				return in_array($k, $allowedFields);
			},
			ARRAY_FILTER_USE_KEY
		);
		// Updating column names in 'columns' field
		$updatedCols = [];
		foreach ((array)$resp[0]['columns'] as $col) {
			$colKeys = array_keys((array)$col);
			$k = $colKeys[0];
			if (array_key_exists($k, $colNameMap)) {
				$updatedCols[] = [$colNameMap[$k] => $col[$k]];
			} elseif (in_array($k, $allowedFields)) {
				$updatedCols[] = $col;
			}
		}
		$resp[0]['columns'] = $updatedCols;
		return $resp;
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
	 * @param ManticoreHTTPClient $client
	 * $return ManticoreHTTPClient
	 */
	public function setManticoreClient(ManticoreHTTPClient $client): ManticoreHTTPClient {
		$this->manticoreClient = $client;
		return $this->manticoreClient;
	}
}
