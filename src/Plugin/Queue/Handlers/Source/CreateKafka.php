<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;

final class CreateKafka extends BaseCreateSourceHandler
{
	/**
	 * @throws ManticoreSearchClientError
	 */
	public static function handle(Payload $payload, Client $manticoreClient): TaskResult {


		$options = self::parseOptions($payload);

		$sql = /** @lang ManticoreSearch */
			'SELECT * FROM ' . Payload::SOURCE_TABLE_NAME .
			" WHERE match('@name \"" . $options->name . "\"')";


		$record = $manticoreClient->sendRequest($sql)->getResult();
		if (is_array($record[0]) && $record[0]['total']) {
			throw ManticoreSearchClientError::create("Source $options->name already exist");
		}

		for ($i = 0; $i < $options->numConsumers; $i++) {
			$attrs = json_encode(
				[
					'broker' => $options->brokerList,
					'topic' => $options->topicList,
					'group' => $options->consumerGroup ?? 'manticore',
					'batch' => $options->batch ?? '100',
				]
			);

			/** @l $query */
			$query = /** @lang ManticoreSearch */
				"CREATE TABLE _buffer_{$options->name}_{$i} $options->schema";

			$request = $manticoreClient->sendRequest($query);
			if ($request->hasError()) {
				throw ManticoreSearchClientError::create((string)$request->getError());
			}

			$escapedPayload = str_replace("'", "\\'", $payload->originQuery);
			/** @l $query */
			$query = /** @lang ManticoreSearch */
				'INSERT INTO ' . Payload::SOURCE_TABLE_NAME . ' (id, type, name, full_name, buffer_table, attrs, original_query) ' .
				'VALUES ' .
				"(0, '" . self::SOURCE_TYPE_KAFKA . "', '$options->name','{$options->name}_$i'," .
				"'_buffer_{$options->name}_$i', '$attrs', '$escapedPayload')";

			$request = $manticoreClient->sendRequest($query);
			if ($request->hasError()) {
				throw ManticoreSearchClientError::create((string)$request->getError());
			}
		}

		self::cleanOrphanViews($options->name, $options->numConsumers,  $manticoreClient);

		return TaskResult::none();
	}

	public static function cleanOrphanViews(string $sourceName, int $maxIndex, Client $client) {
		$viewsTable = Payload::VIEWS_TABLE_NAME;
		if (!$client->hasTable($viewsTable)){
			return;
		}

		// Todo debug
		$sql = /** @lang Manticore */
			"SELECT * FROM $viewsTable " .
			"WHERE MATCH('@source_name \"" . $sourceName . "_*\"') AND suspended=1";

		$request = $client->sendRequest($sql);
		if ($request->hasError()) {
			throw ManticoreSearchClientError::create((string)$request->getError());
		}

		$ids = [];
		foreach ($request->getResult()[0]['data'] as $orphanView) {
			$viewSourceName = explode('_', $orphanView['source_name']);
			// remove index from array, leave only name
			$index = array_pop($viewSourceName);
			$viewSourceName = implode('_', $viewSourceName);

			echo "-------+++++------>$index < $maxIndex \n";
			if ($viewSourceName !== $sourceName || $index < $maxIndex) {
				continue;
			}

			$ids[] = $orphanView['id'];
		}

		if ($ids !== []){
			$ids = implode(',', $ids);
			Buddy::debugv("Remove orphan views records ids ($ids)");
			$sql = /** @lang manticore */
				"DELETE FROM $viewsTable WHERE id in ($ids)";
			$rawResult = $client->sendRequest($sql);
			if ($rawResult->hasError()) {
				throw ManticoreSearchClientError::create($rawResult->getError());
			}
		}
	}


	public static function parseOptions(Payload $payload): \stdClass {
		$result = new \stdClass();

		$result->name = $payload->parsedPayload['SOURCE']['name'];
		$result->schema = $payload->parsedPayload['SOURCE']['create-def']['base_expr'];


		foreach ($payload->parsedPayload['SOURCE']['options'] as $option) {
			if (!isset($option['sub_tree'][0]['base_expr'])) {
				continue;
			}

			match ((string)$option['sub_tree'][0]['base_expr']) {
				'broker_list' =>
				$result->brokerList = SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				'topic_list' =>
				$result->topicList = SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				'consumer_group' =>
				$result->consumerGroup = SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				'num_consumers' =>
				$result->numConsumers = (int)SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				'batch' =>
				$result->batch = (int)SqlQueryParser::removeQuotes($option['sub_tree'][2]['base_expr']),
				default => ''
			};
		}
		return $result;
	}
}
