<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\ShowFields;

use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Lib\Task\Task;
use Manticoresearch\Buddy\Lib\Task\TaskResult;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use RuntimeException;
use parallel\Runtime;

final class Executor implements CommandExecutorInterface {
  /** @var HTTPClient $manticoreClient */
	protected HTTPClient $manticoreClient;

	/**
	 * Initialize the executor
	 *
	 * @param Request $request
	 * @return void
	 */
	public function __construct(public Request $request) {
	}

  /**
	 * Process the request
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(Runtime $runtime): Task {
		$this->manticoreClient->setPath($this->request->path);

		$taskFn = static function (Request $request, HTTPClient $manticoreClient): TaskResult {
			$query = "desc {$request->table}";
			/** @var array<array{data:array<array{Field:string,Type:string}>}> */
			$descResult = $manticoreClient->sendRequest($query)->getResult();
			$data = [];
			foreach ($descResult[0]['data'] as $row) {
				// Sometimes we can have two rows for same field
				// We take last one and use map to make sure we display only once
				$data[$row['Field']] = [
					'Field' => $row['Field'],
					'Type' => $row['Type'],
					'Null' => 'NO',
					'Key' => '',
					'Default' => '',
					'Extra' => '',
				];
			}

			return new TaskResult(
				[[
					'total' => sizeof($data),
					'error' => '',
					'warning' => '',
					'columns' => [
						[
							'Field' => [
								'type' => 'string',
							],
							'Type' => [
								'type' => 'string',
							],
							'Null' => [
								'type' => 'string',
							],
							'Key' => [
								'type' => 'string',
							],
							'Default' => [
								'type' => 'string',
							],
							'Extra' => [
								'type' => 'string',
							],
						],
					],
					'data' => array_values($data),
				],
				]
			);
		};

		return Task::createInRuntime(
			$runtime, $taskFn, [$this->request, $this->manticoreClient]
		)->run();
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
