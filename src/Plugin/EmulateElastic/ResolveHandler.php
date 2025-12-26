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
 * This is the parent class to handle erroneous Manticore queries
 */
class ResolveHandler extends BaseHandlerWithClient {

	use Traits\EntityAliasTrait;

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
			// Replacing the wildcard from the HTTP request with the one used in Manticore
			$tableName = str_replace('*', '%', $payload->table); 
			$query = "SHOW TABLES LIKE '{$tableName}'";
			/** @var array{0?:array{data?:array<mixed>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (!isset($queryResult[0])) {
				return TaskResult::raw([]);
			}

			$tables = [];
			foreach ($queryResult[0]['data'] as $row) {
				$tables[] = $row['Table'];
			}

			$aliasData = self::getAliasData($manticoreClient, $tables);
			return TaskResult::raw(
				self::buildResponse($tables, $aliasData)
			);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @param array<string> $tables
	 * @param array<array{index:string,alias:string}> $aliasData
	 * @return array<mixed>
	 */
	protected static function buildResponse(array $tables, array $aliasData = []): array {
		if ($aliasData) {
			$aliasData = array_combine(
				array_column($aliasData, 'index'),
				array_column($aliasData, 'alias')
			);
		}
		$res = [
			'indices' => [],
			'aliases' => [],
			'data_streams' => [],
		];
		foreach ($tables as $table) {
			$index = [
				'name' => $table,
				'attributes' => 'open',
			];
			$alias = null;
			if (isset($aliasData[$table])) {
				$index['alias'] = $aliasData[$table];
				$alias = [
					'name' => $aliasData[$table],
					'indices' => [$table],
				];
				$res['aliases'][] = $alias;
			}
			$res['indices'][] = $index;
		}

		return $res;
	}
}
