<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source\BaseCreateSourceHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class CreateViewHandler extends BaseHandlerWithClient
{

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
			$viewName = $payload->parsedPayload['VIEW']['no_quotes']['parts'][0];
			$destinationTableName = $payload->parsedPayload['VIEW']['to']['no_quotes']['parts'][0];

			if (isset($payload->parsedPayload['LIMIT'])) {
				throw GenericError::create("Can't use query with limit");
			}


			self::checkAndCreateViews($manticoreClient);
			self::checkViewName($viewName, $manticoreClient);
			self::checkDestinationTable($destinationTableName, $manticoreClient);


			$sql = /** @lang ManticoreSearch */
				'SELECT * FROM ' . BaseCreateSourceHandler::SOURCE_TABLE_NAME .
				" WHERE match('@name \"" . $sourceName . "\"')";

			$sourceRecords = $manticoreClient->sendRequest($sql)->getResult();

			if (is_array($sourceRecords) && empty($sourceRecords[0]['data'])) {
				throw ManticoreSearchClientError::create('Chosen source not exist');
			}

			unset($payload->parsedPayload['CREATE'], $payload->parsedPayload['VIEW']);
			foreach ($sourceRecords[0]['data'] as $source) {
				$payload->parsedPayload['FROM'][0]['table'] = $source['buffer_table'];
				$payload->parsedPayload['FROM'][0]['no_quotes']['parts'] = [$source['buffer_table']];
				$payload->parsedPayload['FROM'][0]['base_expr'] = $source['buffer_table'];

				$payload::$sqlQueryParser::setParsedPayload($payload->parsedPayload);

				$sourceFullName = $source['full_name'];
				$escapedQuery = str_replace("'", "\\'", $payload::$sqlQueryParser::getCompletedPayload());
				$escapedOriginalQuery = str_replace("'", "\\'", $payload->originQuery);

				$sql = /** @lang ManticoreSearch */
					'INSERT INTO ' . self::VIEWS_TABLE_NAME .
					'(id, name, source_name, destination_name, query, original_query) VALUES ' .
					"(0,'$viewName','$sourceFullName', '$destinationTableName', '$escapedQuery','$escapedOriginalQuery')";

				$response = $manticoreClient->sendRequest($sql);
				if ($response->hasError()) {
					throw ManticoreSearchClientError::create($response->getError());
				}
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
	public static function checkViewName(string $viewName, Client $manticoreClient): void {
		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . self::VIEWS_TABLE_NAME . " WHERE match('@name \"" . $viewName . "\"')";

		$record = $manticoreClient->sendRequest($sql)->getResult();
		if (is_array($record[0]) && $record[0]['total']) {
			throw ManticoreSearchClientError::create("View $viewName already exist");
		}
	}

	/**
	 * @param string $tableName
	 * @param Client $manticoreClient
	 * @return bool
	 */
	public static function checkDestinationTable(string $tableName, Client $manticoreClient): bool {
		return $manticoreClient->hasTable($tableName);
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
			'CREATE TABLE ' . self::VIEWS_TABLE_NAME .
			' (id bigint, name text, source_name text, destination_name text, query text, original_query text)';

		$request = $manticoreClient->sendRequest($sql);
		if ($request->hasError()) {
			throw ManticoreSearchClientError::create((string)$request->getError());
		}
	}
}
