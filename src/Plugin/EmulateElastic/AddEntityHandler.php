<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\SystemClient;
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
			$result = self::add(
				$payload->body,
				$entityId,
				$indexType,
				$entityIndex,
				$entityIndexAlias,
				$manticoreClient,
				$payload->createOnly
			);

			return TaskResult::raw(
				self::buildResponse($entityId, $entityIndex, $result)
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
	 * @param bool $createOnly
	 * @return string
	 */
	public static function add(
		string $entitySource,
		string $entityId,
		string $entityType,
		string $entityIndex,
		string $entityIndexAlias,
		HTTPClient $manticoreClient,
		bool $createOnly = false
	): string {
		$systemClient = $manticoreClient->getSystemClient();
		$query = 'CREATE TABLE IF NOT EXISTS ' . parent::ENTITY_TABLE
			. ' (_id string, _index string, _index_alias string, _type string, _source json)';
		$systemClient->sendRequest($query);
		$entityExists = self::entityExists($entityId, $entityIndex, $systemClient);
		if ($createOnly && $entityExists) {
			throw self::createVersionConflictError($entityId, $entityIndex);
		}

		if (!$createOnly && $entityExists) {
			self::deleteEntity($entityId, $entityIndex, $manticoreClient);
		}

		self::writeEntity(
			$entitySource,
			$entityId,
			$entityType,
			$entityIndex,
			$entityIndexAlias,
			$createOnly,
			$systemClient
		);
		self::updateImportedPattern($entitySource, $entityId, $entityType, $manticoreClient);
		return $entityExists ? 'updated' : 'created';
	}

	private static function deleteEntity(
		string $entityId,
		string $entityIndex,
		HTTPClient $manticoreClient
	): void {
		$query = 'DELETE FROM ' . parent::ENTITY_TABLE
			. " WHERE _id='{$entityId}' AND _index='{$entityIndex}'";
		self::executeQuery($query, $manticoreClient, 'entity replacement');
	}

	private static function writeEntity(
		string $entitySource,
		string $entityId,
		string $entityType,
		string $entityIndex,
		string $entityIndexAlias,
		bool $createOnly,
		SystemClient $systemClient
	): void {
		$documentId = self::getDocumentId($entityId, $entityIndex);
		$updatedSource = str_replace('\\"', '\\\\"', $entitySource);
		$operation = $createOnly ? 'INSERT' : 'REPLACE';
		$query = $operation . ' INTO ' . parent::ENTITY_TABLE
			. '(id, _id, _index, _index_alias, _type, _source) '
			. "VALUES({$documentId}, '{$entityId}', '{$entityIndex}', "
			. "'{$entityIndexAlias}', '{$entityType}', '{$updatedSource}')";
		/** @var array{error?:string,0?:array{data?:array<mixed>}} $queryResult */
		$queryResult = $systemClient->sendRequest($query)->getResult();
		if (!isset($queryResult['error'])) {
			return;
		}

		// A concurrent create may have inserted the deterministic document id
		// after the existence check. Translate that race to OpenSearch semantics.
		if ($createOnly && self::entityExists($entityId, $entityIndex, $systemClient)) {
			throw self::createVersionConflictError($entityId, $entityIndex);
		}
		throw new \Exception('Unknown error on Kibana entity creation');
	}

	private static function updateImportedPattern(
		string $entitySource,
		string $entityId,
		string $entityType,
		HTTPClient $manticoreClient
	): void {
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

	/**
	 * @param string $entityId
	 * @param string $entityIndex
	 * @param SystemClient $systemClient
	 * @return bool
	 */
	private static function entityExists(
		string $entityId,
		string $entityIndex,
		SystemClient $systemClient
	): bool {
		$query = 'SELECT id FROM ' . parent::ENTITY_TABLE
			. " WHERE _id='{$entityId}' AND _index='{$entityIndex}' ORDER BY id DESC";
		/** @var array{error?:string,0?:array{data?:array<array{id:int}>}} $queryResult */
		$queryResult = $systemClient->sendRequest($query)->getResult();
		if (isset($queryResult['error'])) {
			throw new \Exception('Unknown error on Kibana entity lookup');
		}
		$entities = $queryResult[0]['data'] ?? [];
		if (sizeof($entities) > 1) {
			self::deleteDuplicateEntities(array_slice($entities, 1), $systemClient);
		}
		return (bool)$entities;
	}

	/**
	 * @param array<array{id:int}> $entities
	 * @param SystemClient $systemClient
	 * @return void
	 */
	private static function deleteDuplicateEntities(array $entities, SystemClient $systemClient): void {
		$ids = array_column($entities, 'id');
		$query = 'DELETE FROM ' . parent::ENTITY_TABLE . ' WHERE id IN (' . implode(',', $ids) . ')';
		/** @var array{error?:string} $queryResult */
		$queryResult = $systemClient->sendRequest($query)->getResult();
		if (isset($queryResult['error'])) {
			throw new \Exception('Unknown error while removing duplicate Kibana entities');
		}
	}

	/**
	 * Generate a stable positive document id so concurrent writes cannot create duplicates.
	 *
	 * @param string $entityId
	 * @param string $entityIndex
	 * @return int
	 */
	private static function getDocumentId(string $entityId, string $entityIndex): int {
		$id = (int)hexdec(substr(hash('sha256', "{$entityIndex}\0{$entityId}"), 0, 15));
		return max(1, $id);
	}

	/**
	 * @param string $entityId
	 * @param string $entityIndex
	 * @return GenericError
	 */
	private static function createVersionConflictError(string $entityId, string $entityIndex): GenericError {
		$reason = "[{$entityId}]: version conflict, document already exists (current version [1])";
		$details = [
			'type' => 'version_conflict_engine_exception',
			'reason' => $reason,
			'index_uuid' => '_na_',
			'shard' => '0',
			'index' => $entityIndex,
		];
		return GenericError::create($reason)
			->setResponseErrorBody(
				[
					'error' => [
						'root_cause' => [$details],
					] + $details,
					'status' => 409,
				]
			)
			->setResponseErrorCode(409);
	}
}
