<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\AlterDistributedTable;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class Handler extends BaseHandlerWithClient
{
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
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {

			$hasTable = false;
			$allDistributedTables = array_column(
				iterator_to_array($manticoreClient->getAllTables(['distributed'])),
				0
			);

			foreach ($allDistributedTables as $table) {
				if ($table === $payload->table) {
					$hasTable = true;
					break;
				}
			}

			if (!$hasTable) {
				throw ManticoreSearchResponseError::create("Table $payload->table doesn't exist");
			}

			self::dropTable($payload->table, $manticoreClient);

			return self::createTable($payload->table, $payload->options, $manticoreClient);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public static function dropTable(string $tableName, Client $manticoreClient): void {
		$sql = /** @lang Manticore */
			"DROP TABLE $tableName";
		$request = $manticoreClient->sendRequest($sql);
		if ($request->hasError()) {
			throw ManticoreSearchResponseError::create((string)$request->getError());
		}
	}

	/**
	 * @param string $tableName
	 * @param array<array<string, string>> $options
	 * @param Client $manticoreClient
	 * @return TaskResult
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public static function createTable(string $tableName, array $options, Client $manticoreClient): TaskResult {

		$sql = /** @lang Manticore */
			"CREATE TABLE $tableName type='distributed'";
		foreach ($options as $option) {
			foreach ($option as $key => $value) {
				$sql .= " $key = $value";
			}
		}

		$request = $manticoreClient->sendRequest($sql);
		if ($request->hasError()) {
			throw ManticoreSearchResponseError::create((string)$request->getError());
		}

		return TaskResult::raw($request->getResult());
	}
}
