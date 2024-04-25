<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Create;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
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

			if (!$client->hasTable($payload->sourceTableName)) {
				throw GenericError::create("Source table $payload->sourceTableName not exists");
			}

			if ($client->hasTable($payload->destinationTableName)) {
				throw GenericError::create("Destination table $payload->destinationTableName already exists");
			}

			try {
				self::flushRamchunk($payload->sourceTableName, $client);
				$freezeResult = self::freezeTable($payload->sourceTableName, $client);
				$dataDirPath = self::parseTablePath($payload->sourceTableName, $freezeResult);
				$destinationTablePath = $dataDirPath . $payload->sourceTableName .
					DIRECTORY_SEPARATOR . $payload->sourceTableName;
				self::importTable($payload->destinationTableName, $destinationTablePath, $client);
			} catch (GenericError $exception) {
				self::unfreezeTable($payload->sourceTableName, $client);
			}

			return TaskResult::none();
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}


	/**
	 * @param string $tableName
	 * @param Client $client
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 */
	private static function flushRamchunk(string $tableName, Client $client): void {
		$sql = "flush ramchunk $tableName";
		$result = $client->sendRequest($sql);
		if ($result->hasError()) {
			throw GenericError::create("Can't flush ramchunk for $tableName. Reason: " . $result->getError());
		}
	}

	/**
	 * @param string $tableName
	 * @param Client $client
	 * @return mixed
	 * @throws ManticoreSearchClientError|GenericError
	 */
	private static function freezeTable(string $tableName, Client $client): mixed {
		$sql = "FREEZE $tableName";
		$result = $client->sendRequest($sql);
		if ($result->hasError()) {
			throw GenericError::create("Can't freeze table $tableName. Reason: " . $result->getError());
		}
		return $result->getResult();
	}

	/**
	 * @param string $tableName
	 * @param mixed $freezeResult
	 * @return string
	 * @throws GenericError
	 */
	private static function parseTablePath(string $tableName, mixed $freezeResult): string {

		if ($tableName === '') {
			throw GenericError::create("Table name can't be empty");
		}
		if (!is_array($freezeResult) || !isset($freezeResult[0]['data'][0]['normalized'])) {
			throw GenericError::create('No normalized result in freeze response');
		}

		$explodedPath = explode($tableName, $freezeResult[0]['data'][0]['normalized']);

		return $explodedPath[0];
	}

	/**
	 * @param string $tableName
	 * @param Client $client
	 * @return void
	 * @throws ManticoreSearchClientError|GenericError
	 */
	private static function unfreezeTable(string $tableName, Client $client): void {
		$sql = "UNFREEZE $tableName";
		$result = $client->sendRequest($sql);
		if ($result->hasError()) {
			throw GenericError::create("Can't unfreeze table $tableName. Reason: " . $result->getError());
		}
	}


	/**
	 * @param string $tableName
	 * @param string $destinationTablePath
	 * @param Client $client
	 * @return true
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 */
	public static function importTable(string $tableName, string $destinationTablePath, Client $client): true {
		$sql = "import table $tableName from '$destinationTablePath'";
		$result = $client->sendRequest($sql);
		if ($result->hasError()) {
			throw GenericError::create("Can't import table $tableName. Reason: " . $result->getError());
		}
		return true;
	}

}
