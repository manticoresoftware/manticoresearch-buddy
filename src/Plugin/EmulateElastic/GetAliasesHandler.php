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
 * This is the parent class to handle erroneous Manticore queries
 */
class GetAliasesHandler extends BaseEntityHandler {

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
			$query = "SHOW TABLES LIKE '" . parent::ALIAS_TABLE . "'";
			/** @var array{0?:array{data?:array<mixed>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (!isset($queryResult[0])) {
				return TaskResult::raw([]);
			}

			$aliasInfo = self::get($payload->table, $manticoreClient);
			return TaskResult::raw($aliasInfo);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 *
	 * @param string $indexAlias
	 * @param HttpClient $manticoreClient
	 * @return array<string,mixed>
	 */
	public static function get(string $indexAlias, HTTPClient $manticoreClient): array {
		$query = 'SELECT index FROM ' . parent::ALIAS_TABLE . " WHERE alias='{$indexAlias}'";
		/** @var array{0:array{data?:array<array{index:string}>}} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query)->getResult();
		if (!isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
			return [];
		}
		$aliasInfo = [];
		foreach ($queryResult[0]['data'] as $dataRow) {
			$aliasInfo[$dataRow['index']] = [
				'aliases' => [
					$indexAlias => [],
				],
			];
		}
		return $aliasInfo;
	}
}
