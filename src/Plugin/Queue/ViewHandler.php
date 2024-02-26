<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Manticoresearch\Buddy\Base\Plugin\Queue\SourceHandlers\SourceHandler;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class ViewHandler extends BaseHandlerWithClient {

	const VIEWS_TABLE_NAME = '_views';

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
	 * @return Task
	 */
	public function run(): Task {

		/**
		 * @param Payload $payload
		 * @param Client $manticoreClient
		 * @return TaskResult
		 * @throws ManticoreSearchClientError
		 */
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {

			$sourceName = $payload->parsedPayload['FROM'][0]['table'];
			$viewName = $payload->parsedPayload['VIEW'][1];

			self::checkAndCreateViews($manticoreClient);
			self::checkName($viewName);

			/**
			 * CREATE MATERIALIZED VIEW view_table TO destination_kafka AS
			 * SELECT
			 * id,
			 * term as name,
			 * abbrev as short_name,
			 * UTC_TIMESTAMP() as received_at,
			 * GlossDef.size as size
			 * FROM kafka;
			 */

			Buddy::debug(json_encode($payload->parsedPayload));




			$sql = /** @lang ManticoreSearch */
				'SELECT * FROM ' . SourceHandler::SOURCE_TABLE_NAME . ' ' .
				"WHERE name = '$sourceName'";

			$sourceRecords = $manticoreClient->sendRequest($sql)->getResult();
			if (!$sourceRecords) {
				throw ManticoreSearchClientError::create('Chosen source not exist');
			}

			foreach ($sourceRecords as $source) {
			}

			return TaskResult::none();
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}


	/**
	 * @param string $viewName
	 * @param Client $manticoreClient
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	public static function checkName(string $viewName, Client $manticoreClient): void {
		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . self::VIEWS_TABLE_NAME . " WHERE name = '$viewName'";

		$record = $manticoreClient->sendRequest($sql)->getResult();
		if (is_array($record[0]) && $record[0]['total']) {
			throw ManticoreSearchClientError::create("View $viewName already exist");
		}
	}

	/**
	 * @param Client $manticoreClient
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	public static function checkAndCreateViews(Client $manticoreClient): void {
		if ($manticoreClient->hasTable(self::VIEWS_TABLE_NAME)) {
			return;
		}

		$sql = /** @lang ManticoreSearch */
			'CREATE TABLE ' . self::VIEWS_TABLE_NAME . ' (id bigint, name text, query text)';

		$request = $manticoreClient->sendRequest($sql);
		if ($request->hasError()) {
			throw ManticoreSearchClientError::create((string)$request->getError());
		}
	}
}
