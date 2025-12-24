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
class AddAliasHandler extends BaseEntityHandler {

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
			/** @var array{actions:array<mixed>} */
			$request = simdjson_decode($payload->body, true);
			if (!is_array($request)) {
				throw new \Exception('Cannot parse request');
			}
			[$index, $alias] = self::extractIndexInfo($request['actions']);

			$query = "SHOW TABLES LIKE '{$index}'";
			/** @var array{0:array{data?:array<mixed>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (!isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
				throw new \Exception("Index '{$index}' does not exist");
			}

			self::addEntityAlias($index, $alias, $manticoreClient, $payload->table);

			return TaskResult::raw(['acknowledged' => 'true']);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 *
	 * @param array<mixed> $actions
	 * @return array{0:string,1:string}
	 * @throws RuntimeException
	 */
	protected static function extractIndexInfo(array $actions): array {
		$index = $alias = '';
		foreach ($actions as $action) {
			if (is_array($action) && array_key_exists('add', $action)) {
				extract($action['add']);
				return [$index, $alias];
			}
		}
		throw new \Exception('Unknown error on alias creation');
	}

}
