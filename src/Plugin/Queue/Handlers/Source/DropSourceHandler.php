<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source;

use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\BaseDropHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\InternalBuddyClientTrait;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

/**
 * @extends BaseDropHandler<array{
 *       DROP: array{
 *           expr_type?: string,
 *           option: bool,
 *           if-exists: bool,
 *           sub_tree: array{
 *               array{
 *                   expr_type: string,
 *                   base_expr: string
 *               },
 *               array{
 *                   expr_type: string,
 *                   base_expr: string,
 *                   sub_tree: array{
 *                      array{
 *                        expr_type: string,
 *                        table: string,
 *                        no_quotes: array{
 *                            delim: bool,
 *                            parts: array<string>
 *                        },
 *                        alias: bool,
 *                        base_expr: string,
 *                        delim: bool
 *                      }
 *                   }
 *               }
 *           }
 *       }
 *   }>
 */
final class DropSourceHandler extends BaseDropHandler {
	use InternalBuddyClientTrait;

	/**
	 * @param string $tableName
	 * @return int
	 * @throws ManticoreSearchClientError
	 */
	protected function processDrop(string $tableName): int {
		$manticoreClient = $this->manticoreClient;
		$sql = /** @lang Manticore */
			"SELECT * FROM $tableName";


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

				self::suspendSourceViews($sourceRow['full_name'], $manticoreClient);
				self::dropBufferTable($sourceRow, $manticoreClient);
				$removed++;
			}
		}

		$dropResponse = $manticoreClient->sendRequest("DROP TABLE IF EXISTS $tableName");
		if ($dropResponse->hasError()) {
			throw ManticoreSearchClientError::create((string)$dropResponse->getError());
		}

		return $removed;
	}

	/**
	 * @param array<string, string> $sourceRow
	 * @throws ManticoreSearchClientError
	 */
	private static function dropBufferTable(array $sourceRow, Client $client): void {
		$systemClient = self::getSystemClient($client);
		$dropBufferRequest = $systemClient->sendRequest("DROP TABLE IF EXISTS {$sourceRow['buffer_table']}");
		unset($systemClient);
		if ($dropBufferRequest->hasError()) {
			throw ManticoreSearchClientError::create((string)$dropBufferRequest->getError());
		}
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private static function suspendSourceViews(string $sourceFullName, Client $client): void {
		foreach (ResourceTable::list($client, ResourceTable::TABLE_PREFIX_MATERIALIZED_VIEW) as $viewsTable) {
			$query = /** @lang Manticore */
				"UPDATE $viewsTable SET suspended=1 WHERE match('@source_name \"$sourceFullName\"')";
			$request = $client->sendRequest($query);
			if ($request->hasError()) {
				throw ManticoreSearchClientError::create((string)$request->getError());
			}
		}
	}

	/**
	 * @param Payload<array{
	 *       DROP: array{
	 *           expr_type?: string,
	 *           option: bool,
	 *           if-exists: bool,
	 *           sub_tree: array{
	 *               array{
	 *                   expr_type: string,
	 *                   base_expr: string
	 *               },
	 *               array{
	 *                   expr_type: string,
	 *                   base_expr: string,
	 *                   sub_tree: array{
	 *                      array{
	 *                        expr_type: string,
	 *                        table: string,
	 *                        no_quotes: array{
	 *                            delim: bool,
	 *                            parts: array<string>
	 *                        },
	 *                        alias: bool,
	 *                        base_expr: string,
	 *                        delim: bool
	 *                      }
	 *                   }
	 *               }
	 *           }
	 *       }
	 *   }> $payload
	 * @return string
	 */
	protected function getName(Payload $payload): string {
		$parsedPayload = $payload->model->getPayload();
		return $parsedPayload['DROP']['sub_tree'][1]['sub_tree'][0]['no_quotes']['parts'][0];
	}

	protected function getTableName(string $name): string {
		return ResourceTable::name(ResourceTable::RESOURCE_SOURCE, $name);
	}
}
