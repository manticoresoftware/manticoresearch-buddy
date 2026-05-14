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
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

use RuntimeException;

/**
 * Handling requests to extract and build info on Kibana entities, like templates, dashboards, etc.
 */
class CatHandler extends BaseHandlerWithClient {

	use Traits\KibanaVersionTrait;
	use Traits\QueryMapLoaderTrait;

	const CAT_ENTITIES = ['templates', 'plugins', 'indices'];

	/**
	 *  Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, HTTPClient $manticoreClient): TaskResult {
			$pathParts = explode('/', $payload->path);
			self::checkRequestValidity($pathParts);
			$entityTable = "_{$pathParts[1]}";

			if (in_array($pathParts[1], self::CAT_ENTITIES)) {
				if (!isset($pathParts[2])) {
					$queryMapName = 'Plugins';
					self::initQueryMap($queryMapName);
					/** @var array{name:string,patterns:string,content:string} $entityInfo */
					$entityInfo = self::$queryMap[$queryMapName][$entityTable];
					$catInfo = self::getVersionedEntity($entityInfo, $manticoreClient);

					return TaskResult::raw($catInfo);
				}
				if ($pathParts[1] === 'indices') {
					return TaskResult::raw(
						self::buildCatIndicesInfo($manticoreClient, $pathParts[2])
					);
				}
			}

			$entityNamePattern = $pathParts[2];
			$query = "SELECT * FROM {$entityTable} WHERE MATCH('{$entityNamePattern}')";
			/** @var array{0:array{data?:array<array{name:string,patterns:string,content:string}>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (!isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
				return TaskResult::raw([]);
			}

			$catInfo = [];
			foreach ($queryResult[0]['data'] as $entityInfo) {
				$catInfo[] = match ($entityTable) {
					'_templates' => self::buildCatTemplateRow($entityInfo),
					default => []
				};
			}

			return TaskResult::raw($catInfo);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 *
	 * @param array<string> $pathParts
	 */
	private static function checkRequestValidity(array $pathParts): void {
		if (!isset($pathParts[1], $pathParts[2])
			&& !in_array($pathParts[1], self::CAT_ENTITIES) && !str_ends_with($pathParts[1], '*')) {
			throw new \Exception('Cannot parse request');
		}
	}

	/**
	 *
	 * @param HTTPClient $manticoreClient
	 * @param string $tablePattern
	 * @return array<int,array<string,mixed>>
	 */
	private static function buildCatIndicesInfo(HTTPClient $manticoreClient, string $tablePattern): array {
		$query = 'SHOW TABLES';
		if ($tablePattern !== '*') {
			$query .= " LIKE $tablePattern";
		}
		/** @var array{0:array{data?:array<array{Table:string,patterns:string,content:string}>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (!isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
			return [];
		}

		$catInfo = [];
		foreach ($queryResult[0]['data'] as $tableRow) {
			$tableName = $tableRow['Table'];
			$statusMap = self::getTableStatusMap($manticoreClient, $tableName);
			$catInfo[] = self::buildCatIndicesRow($tableName, $statusMap);
		}

		return $catInfo;
	}

	/**
	 *
	 * @param HTTPClient $manticoreClient
	 * @param string $tableName
	 * @return array<string,mixed>
	 */
	private static function getTableStatusMap(HTTPClient $manticoreClient, string $tableName): array {
		$query = 'SHOW TABLE `' . str_replace('`', '``', $tableName) . '` STATUS';
		$response = $manticoreClient->sendRequest($query);
		if ($response->hasError()) {
			return [];
		}

		/** @var array{0:array{data?:array<array{Variable_name?:string,Value?:mixed}>}} $queryResult */
		$queryResult = $response->getResult();
		if (!isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
			return [];
		}

		$statusMap = [];
		foreach ($queryResult[0]['data'] as $statusRow) {
			if (!isset($statusRow['Variable_name'], $statusRow['Value']) || !$statusRow['Variable_name']) {
				continue;
			}
			$statusMap[$statusRow['Variable_name']] = $statusRow['Value'];
		}

		return $statusMap;
	}

	/**
	 * @param array<string,mixed> $statusMap
	 * @return array<mixed>
	 */
	private static function buildCatIndicesRow(string $tableName, array $statusMap): array {
		$docsCount = $statusMap['indexed_documents'] ?? 0;
		$docsDeleted = $statusMap['killed_documents'] ?? 0;

		return [
			'docs.count' => $docsCount,
			'docs.deleted' => $docsDeleted,
			'health' => 'green',
			'index' => $tableName,
			'pri' => '1',
			'rep' => '1',
			'status' => 'open',
		];
	}

	/**
	 *
	 * @param array{name:string,patterns:string,content:string} $entityInfo
	 * @return array<string,mixed>
	 */
	private static function buildCatTemplateRow(array $entityInfo): array {
		return [
			'name' => $entityInfo['name'],
			'order' => 0,
			'index_patterns' => simdjson_decode($entityInfo['patterns'], true),
		] + simdjson_decode($entityInfo['content'], true);
	}

}
