<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use PHPSQLParser\PHPSQLCreator;
use PHPSQLParser\exceptions\UnsupportedFeatureException;

final class CreateViewHandler extends BaseHandlerWithClient
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
	 * @return Task
	 */
	public function run(): Task {

		/**
		 * @return TaskResult
		 * @throws ManticoreSearchClientError|GenericError
		 */
		$taskFn = function (): TaskResult {
			$payload = $this->payload;
			$parsedPayload = $payload->model->getPayload();

			$manticoreClient = $this->manticoreClient;
			$sourceName = strtolower($parsedPayload['FROM'][0]['table']);
			$viewName = strtolower($parsedPayload['VIEW']['no_quotes']['parts'][0]);
			$destinationTableName = strtolower($parsedPayload['VIEW']['to']['no_quotes']['parts'][0]);

			if (isset($parsedPayloadd['LIMIT'])) {
				throw GenericError::create("Can't use query with limit");
			}


			self::checkAndCreateViews($manticoreClient);
			self::checkViewName($viewName, $manticoreClient);

			if (!self::checkDestinationTable($destinationTableName, $manticoreClient)) {
				return TaskResult::withError('Destination table non exist');
			}


			$sql = /** @lang ManticoreSearch */
				'SELECT * FROM ' . Payload::SOURCE_TABLE_NAME .
				" WHERE match('@name \"" . $sourceName . "\"')";

			$sourceRecords = $manticoreClient->sendRequest($sql)->getResult();

			if (is_array($sourceRecords[0]) && empty($sourceRecords[0]['data'])) {
				throw ManticoreSearchClientError::create('Chosen source not exist');
			}

			unset($parsedPayload['CREATE'], $parsedPayload['VIEW']);

			$sourceRecords = $sourceRecords[0]['data'];

			$newViews = self::createViewRecords(
				$manticoreClient, $viewName, $parsedPayload,
				$sourceName, $payload->originQuery,
				$destinationTableName, sizeof($sourceRecords)
			);

			foreach ($sourceRecords as $source) {
				$source['destination_name'] = $newViews[$source['full_name']]['destination_name'];
				$source['query'] = $newViews[$source['full_name']]['query'];
				$this->payload::$processor->execute('runWorker', [$source]);
			}


			return TaskResult::none();
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * @param Client $client
	 * @param string $viewName
	 * @param array<string, array<int, string>> $parsedQuery
	 * @param string $sourceName
	 * @param string $originalQuery
	 * @param string $destinationTableName
	 * @param int $iterations
	 * @param int $startFrom
	 * @param int $suspended
	 * @return array<string, array<string, string>>
	 * @throws ManticoreSearchClientError
	 * @throws UnsupportedFeatureException
	 */
	public static function createViewRecords(
		Client $client,
		string $viewName,
		array  $parsedQuery,
		string $sourceName,
		string $originalQuery,
		string $destinationTableName,
		int    $iterations,
		int    $startFrom = 0,
		int    $suspended = 0
	): array {

		$results = [];

		for ($i = $startFrom; $i < $iterations; $i++) {
			$bufferTableName = "_buffer_{$sourceName}_$i";
			$sourceFullName = "{$sourceName}_$i";

			$parsedQuery['FROM'][0]['table'] = $bufferTableName;
			$parsedQuery['FROM'][0]['no_quotes']['parts'] = [$bufferTableName];
			$parsedQuery['FROM'][0]['base_expr'] = $bufferTableName;

			$query = (new PHPSQLCreator())->create($parsedQuery);
			$escapedQuery = str_replace("'", "\\'", $query);
			$escapedOriginalQuery = str_replace("'", "\\'", $originalQuery);

			$sql = /** @lang ManticoreSearch */
				'INSERT INTO ' . Payload::VIEWS_TABLE_NAME .
				'(id, name, source_name, destination_name, query, original_query, suspended) VALUES ' .
				"(0,'$viewName','$sourceFullName', '$destinationTableName'," .
				"'$escapedQuery','$escapedOriginalQuery', $suspended)";

			$response = $client->sendRequest($sql);
			if ($response->hasError()) {
				throw ManticoreSearchClientError::create((string)$response->getError());
			}

			$results[$sourceFullName]['destination_name'] = $destinationTableName;
			$results[$sourceFullName]['query'] = $query;
		}

		return $results;
	}

	/**
	 * @param string $viewName
	 * @param Client $manticoreClient
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	public static function checkViewName(string $viewName, Client $manticoreClient): void {
		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . Payload::VIEWS_TABLE_NAME . " WHERE match('@name \"" . $viewName . "\"')";

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
		if ($manticoreClient->hasTable(Payload::VIEWS_TABLE_NAME)) {
			return;
		}

		$sql = /** @lang ManticoreSearch */
			'CREATE TABLE ' . Payload::VIEWS_TABLE_NAME .
			' (id bigint, name text, source_name text, destination_name text, ' .
			'query text, original_query text, suspended bool)';

		$request = $manticoreClient->sendRequest($sql);
		if ($request->hasError()) {
			throw ManticoreSearchClientError::create((string)$request->getError());
		}
	}
}
