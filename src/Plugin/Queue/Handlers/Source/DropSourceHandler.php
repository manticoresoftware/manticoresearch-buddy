<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\BaseDropHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class DropSourceHandler extends BaseDropHandler
{

	/**
	 * @param string $name
	 * @param string $tableName
	 * @return int
	 * @throws ManticoreSearchClientError
	 */
	protected function processDrop(string $name, string $tableName): int {
		$manticoreClient = $this->manticoreClient;
		$sql = /** @lang Manticore */
			"SELECT * FROM $tableName WHERE match('@name \"$name\"')";


		$result = $manticoreClient->sendRequest($sql);

		if ($result->hasError()) {
			throw ManticoreSearchClientError::create((string)$result->getError());
		}

		$removed = 0;
		$result = $result->getResult();
		if (is_array($result[0])) {
			foreach ($result[0]['data'] as $sourceRow) {
				$this->payload::$processor
					->execute('stopWorkerById', [$sourceRow['full_name']]);

				self::removeSourceRowData($sourceRow, $manticoreClient);
				$removed++;
			}
		}


		return $removed;
	}

	/**
	 * @param array<string, string> $sourceRow
	 * @param Client $client
	 * @throws ManticoreSearchClientError
	 */
	protected static function removeSourceRowData(array $sourceRow, Client $client): void {

		$queries = [
			/** @lang Manticore */
			"DROP TABLE {$sourceRow['buffer_table']}",
			/** @lang Manticore */
			"UPDATE _views SET suspended=1 WHERE match('@source_name \"{$sourceRow['full_name']}\"')",
			/** @lang Manticore */
			"DELETE FROM _sources WHERE id = {$sourceRow['id']}",
		];

		foreach ($queries as $query) {
			$request = $client->sendRequest($query);
			if ($request->hasError()) {
				throw ManticoreSearchClientError::create((string)$request->getError());
			}
		}
	}

	#[\Override] protected function getName(Payload $payload): string {
		$parsedPayload = $payload->model->getPayload();
		return $parsedPayload['DROP']['sub_tree'][1]['sub_tree'][0]['no_quotes']['parts'][0];
	}

	#[\Override] protected function getTableName(): string {
		return Payload::SOURCE_TABLE_NAME;
	}
}
