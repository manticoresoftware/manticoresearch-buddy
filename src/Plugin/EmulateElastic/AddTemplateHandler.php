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
class AddTemplateHandler extends BaseHandlerWithClient {

	const TEMPLATE_TABLE = '_templates';

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
			/** @var array<string,mixed> */
			$request = simdjson_decode($payload->body, true);
			if (!is_array($request) || !isset($request['index_patterns'])) {
				throw new \Exception('Cannot parse request');
			}
			$patterns = json_encode($request['index_patterns']);
			if (!isset($request['index_patterns'])) {
				continue;
			}
			unset($request['index_patterns']);
			$content = json_encode($request);
			$templateTable = self::TEMPLATE_TABLE;

			// We may need to do a wildcard search by template name so we index it
			$query = "CREATE TABLE IF NOT EXISTS {$templateTable} "
				. "(name string indexed attribute, patterns json, content json) min_prefix_len='2'";
			$manticoreClient->sendRequest($query);

			$query = "SELECT 1 FROM {$templateTable} WHERE name='{$payload->table}'";
			/** @var array{0:array{data:mixed}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			$query = $queryResult[0]['data']
				? "UPDATE {$templateTable} SET patterns='{$patterns}', content='{$content}'"
					. "WHERE name='{$payload->table}'"
				: "INSERT INTO {$templateTable} (name, patterns, content) "
					. "VALUES ('{$payload->table}', '{$patterns}', '{$content}')";
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (isset($queryResult['error'])) {
				throw new \Exception('Unknown error on alias creation');
			}

			return TaskResult::raw(
				[
					'acknowledged' => 'true',
					'index' => $payload->table,
					'shards_acknowledged' => 'true',
				]
			);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}
}
