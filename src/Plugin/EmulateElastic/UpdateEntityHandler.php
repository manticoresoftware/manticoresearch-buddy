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
 * This is the class to create Kibana-related entities, like dashboards, visualizations, etc.
 */
class UpdateEntityHandler extends BaseEntityHandler {

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, HTTPClient $manticoreClient): TaskResult {
			[$entityId, $entityIndex, $entityIndexAlias] = self::getEntityInfo($payload->path, $manticoreClient);
			$query = 'SELECT _source FROM `' . parent::ENTITY_TABLE
			. "` WHERE _id='{$entityId}' AND _index='{$entityIndex}'";
			/** @var array{error?:string,0:array{data?:array<array{_source:string}>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (!isset($queryResult[0]['data'])) {
				throw new \Exception('Unknown error on Kibana entity select');
			}
			if (!$queryResult[0]['data']) {
				/** @var array{doc?:array<mixed>} $payloadObj */
				$payloadObj = simdjson_decode($payload->body, true);
				$indexType = explode(':', $entityId)[0];
				$entitySource = array_key_exists('doc', $payloadObj) ? $payloadObj['doc'] : $payloadObj;
				AddEntityHandler::add(
					(string)json_encode($entitySource),
					$entityId,
					$indexType,
					$entityIndex,
					$entityIndexAlias,
					$manticoreClient
				);
			} else {
				self::update(
					$payload->body,
					$queryResult[0]['data'][0]['_source'],
					$entityId,
					$entityIndex,
					$manticoreClient
				);
			}

			$response = self::buildResponse($entityId, $entityIndex, 'updated') + self::buildUpdateData($payload->body);
			return TaskResult::raw($response);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 *
	 * @param string $source
	 * @param string $prevSource
	 * @param string $entityId
	 * @param string $entityIndex
	 * @param HttpClient $manticoreClient
	 * @return void
	 */
	public static function update(
		string $source,
		string $prevSource,
		string $entityId,
		string $entityIndex,
		HTTPClient $manticoreClient
	): void {
		/** @var array{doc?:array<mixed>} $sourceObj */
		$sourceObj = simdjson_decode($source, true);
		if (!$sourceObj) {
			$source = str_replace('\\"', '\\\\"', $source);
			/** @var array{doc?:array<mixed>} $sourceObj */
			$sourceObj = simdjson_decode($source, true);
		}
		if (array_key_exists('doc', $sourceObj)) {
			$sourceObj = $sourceObj['doc'];
		}
		if ($prevSource) {
			/** @var array<int|string,mixed> $prevSourceObj */
			$prevSourceObj = simdjson_decode($prevSource, true);
		} else {
			$prevSourceObj = [];
		}
		foreach ($sourceObj as $k => $v) {
			if (is_array($v)) {
				$prevSourceObj[$k] = $v + $prevSourceObj[$k];
			} else {
				$prevSourceObj[$k] = $v;
			}
		}
		$updatedSource = str_replace('\\"', '\\\\"', (string)json_encode($prevSourceObj));
		$query = 'UPDATE ' . self::ENTITY_TABLE
			. " SET _source='{$updatedSource}' WHERE _id='{$entityId}'";
		if ($entityIndex) {
			$query .= " AND _index='{$entityIndex}'";
		}
		self::executeQuery($query, $manticoreClient, 'entity update');
	}

	/**
	 * @param string $source
	 * @return array<mixed>
	 * @throws \Exception
	 */
	protected static function buildUpdateData(string $source): array {
		$sourceData = (array)simdjson_decode($source, true);
		$entityKey = array_key_first($sourceData);
		if ($entityKey === null) {
			throw new \Exception('Unknown error on Kibana entity update');
		}
		$entity = (array)$sourceData[$entityKey];
		$type = array_key_first($entity);

		return [
			'get' => [
				'_primary_term' => 1,
				'_seq_no' => 0,
				'_source' => $entity + [
					'references' => [],
					'type' => $type,
				],
				'found' => true,
			],
		];
	}
}
