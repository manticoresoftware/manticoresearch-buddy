<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the class to update Kibana-related entities, like dashboards, visualizations, etc.
 */
class AddEntityHandler extends BaseEntityHandler {

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, HTTPClient $manticoreClient): TaskResult {
			[$entityId, $entityIndex, $entityIndexAlias] = self::getEntityInfo($payload->path, $manticoreClient);
			$indexType = explode(':', $entityId)[0];
			self::add($payload->body, $entityId, $indexType, $entityIndex, $entityIndexAlias, $manticoreClient);

			return TaskResult::raw(
				self::buildResponse($entityId, $entityIndex, 'created')
			);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 *
	 * @param string $entitySource
	 * @param string $entityId
	 * @param string $entityType
	 * @param string $entityIndex
	 * @param string $entityIndexAlias
	 * @param HttpClient $manticoreClient
	 * @return void
	 */
	public static function add(
		string $entitySource,
		string $entityId,
		string $entityType,
		string $entityIndex,
		string $entityIndexAlias,
		HTTPClient $manticoreClient
	): void {
		$query = 'CREATE TABLE IF NOT EXISTS ' . parent::ENTITY_TABLE
			. ' (_id string, _index string, _index_alias string, _type string, _source json)';
		$manticoreClient->sendRequest($query);
		$updatedSource = str_replace('\\"', '\\\\"', $entitySource);
		$query = 'INSERT INTO ' . parent::ENTITY_TABLE . '(_id, _index, _index_alias,  _type, _source) '
			. "VALUES('{$entityId}', '{$entityIndex}', '{$entityIndexAlias}', '{$entityType}', '{$updatedSource}')";
		self::executeQuery($query, $manticoreClient, 'entity creation');

		// Checking if there're previously imported entities related to this pattern and updating them
		if ($entityType !== 'index-pattern') {
			return;
		}
		$importedPatternId = ImportKibanaHandler::checkEntityPatterns(
			$entitySource,
			'import',
			$manticoreClient
		);
		if (!$importedPatternId) {
			return;
		}
		ImportKibanaHandler::updateEntityPatterns($importedPatternId, $entityId, $manticoreClient);
	}
}
