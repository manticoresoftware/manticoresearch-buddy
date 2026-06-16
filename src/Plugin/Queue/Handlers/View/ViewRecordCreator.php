<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View;

use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Lib\SqlEscapingTrait;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPSQLParser\PHPSQLCreator;
use PHPSQLParser\exceptions\UnsupportedFeatureException;

final class ViewRecordCreator {
	use SqlEscapingTrait;

	public function __construct(private Client $client) {
	}

	/**
	 * @param array<string, array<int, array<array<string, mixed>>>|string> $parsedQuery
	 * @return array<string, array<string, string>>
	 * @throws ManticoreSearchClientError
	 * @throws UnsupportedFeatureException|GenericError
	 */
	public function create(
		string $viewName,
		array $parsedQuery,
		string $sourceName,
		string $originalQuery,
		string $destinationTableName,
		int $iterations,
		int $startFrom = 0,
		int $suspended = 0
	): array {
		$results = [];

		for ($i = $startFrom; $i < $iterations; $i++) {
			$bufferTableName = Payload::BUFFER_TABLE_PREFIX.$sourceName."_$i";
			$sourceFullName = "{$sourceName}_$i";

			$parsedQuery['FROM'][0]['table'] = $bufferTableName;
			$parsedQuery['FROM'][0]['no_quotes']['parts'] = [$bufferTableName];
			$parsedQuery['FROM'][0]['base_expr'] = $bufferTableName;

			$query = '';
			try {
				$query = (new PHPSQLCreator())->create($parsedQuery);
			} catch (\Exception $exception) {
				$message = "Can\'t compile SELECT query from ".json_encode($parsedQuery);
				Buddy::debugvv($message);
				Buddy::debugvv($exception->getMessage());
				GenericError::throw($message);
			}

			$escapedQuery = self::escapeSqlString($query);
			$escapedOriginalQuery = self::escapeSqlString($originalQuery);

			$sql = /** @lang ManticoreSearch */
				'INSERT INTO ' . ResourceTable::name(ResourceTable::RESOURCE_MATERIALIZED_VIEW, $viewName) .
				'(id, name, source_name, destination_name, query, original_query, suspended) VALUES ' .
				"(0,'$viewName','$sourceFullName', '$destinationTableName'," .
				"'$escapedQuery','$escapedOriginalQuery', $suspended)";

			$response = $this->client->sendRequest($sql);
			if ($response->hasError()) {
				throw ManticoreSearchClientError::create((string)$response->getError());
			}

			$results[$sourceFullName]['destination_name'] = $destinationTableName;
			$results[$sourceFullName]['query'] = $query;
		}

		return $results;
	}
}
