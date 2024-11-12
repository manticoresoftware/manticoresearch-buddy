<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\AlterRenameTable;

use Manticoresearch\Buddy\Core\Error\GenericError;
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
		$taskFn = static function (Payload $payload, Client $client): TaskResult {

			$hasTable = false;
			foreach (iterator_to_array($client->getAllTables()) as $tableInfo) {
				if ($tableInfo[0] === $payload->sourceTableName) {
					if ($tableInfo[1] !== 'rt') {
						throw ManticoreSearchResponseError::create("Table $payload->sourceTableName should be RT");
					}
					$hasTable = true;
					break;
				}
			}

			if (!$hasTable) {
				throw GenericError::create("Source table $payload->sourceTableName not exists");
			}

			if ($client->hasTable($payload->destinationTableName)) {
				throw GenericError::create("Destination table $payload->destinationTableName already exists");
			}
			self::createTableLike($payload->sourceTableName, $payload->destinationTableName, $client);

			$result = self::attachTable($payload->sourceTableName, $payload->destinationTableName, $client);

			self::dropTable($payload->sourceTableName, $client);

			return TaskResult::raw($result);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}


	/**
	 * @param string $sourceTableName
	 * @param string $destinationTableName
	 * @param Client $client
	 * @return void
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 */
	private static function createTableLike(
		string $sourceTableName,
		string $destinationTableName,
		Client $client
	): void {

		$sql = /** @lang ManticoreSearch */
			"create table $destinationTableName like $sourceTableName";
		$result = $client->sendRequest($sql);
		if ($result->hasError()) {
			throw GenericError::create(
				"Can't create $destinationTableName table like $sourceTableName. " .
				'Reason: ' . $result->getError()
			);
		}
	}


	/**
	 * @param string $sourceTableName
	 * @param string $destinationTableName
	 * @param Client $client
	 * @return mixed
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 */
	private static function attachTable(
		string $sourceTableName,
		string $destinationTableName,
		Client $client
	): mixed {

		$sql = /** @lang ManticoreSearch */
			"ATTACH TABLE $sourceTableName TO TABLE $destinationTableName";
		$result = $client->sendRequest($sql);
		if ($result->hasError()) {
			throw GenericError::create(
				"Can't attach $sourceTableName table to $destinationTableName. " .
				'Reason: ' . $result->getError()
			);
		}
		return $result->getResult();
	}


	/**
	 * @param string $tableName
	 * @param Client $client
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 */
	private static function dropTable(string $tableName, Client $client): void {
		$sql = /** @lang ManticoreSearch */
			"drop table $tableName";
		$result = $client->sendRequest($sql);
		if ($result->hasError()) {
			throw GenericError::create("Can't drop table $tableName. Reason: " . $result->getError());
		}
	}
}
