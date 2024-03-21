<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\BaseDropHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Base\Plugin\Queue\QueueProcess;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class DropViewHandler extends BaseDropHandler
{

	/**
	 * @throws ManticoreSearchClientError
	 */
	protected static function processDrop(string $name, string $tableName, Client $manticoreClient): int {
		$sql = /** @lang Manticore */
			"SELECT * FROM $tableName WHERE match('@name \"$name\"')";

		$result = $manticoreClient->sendRequest($sql);

		if ($result->hasError()) {
			throw ManticoreSearchClientError::create($result->getError());
		}

		$removed = 0;
		foreach ($result->getResult()[0]['data'] as $row) {
			QueueProcess::getInstance()->stopWorkerByName($row['source_name']);

			$sql = /** @lang Manticore */
				"DELETE FROM $tableName WHERE id = " . $row['id'];
			$request = $manticoreClient->sendRequest($sql);
			if ($request->hasError()) {
				throw ManticoreSearchClientError::create($request->getError());
			}

			$removed++;
		}

		return $removed;
	}


	#[\Override] protected function getName(Payload $payload): string {
		return $payload->parsedPayload['DROP']['sub_tree'][2]['sub_tree'][0]['no_quotes']['parts'][0];
	}

	#[\Override] protected function getTableName(): string {
		return CreateViewHandler::VIEWS_TABLE_NAME;
	}
}
