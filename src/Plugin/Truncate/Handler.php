<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Truncate;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class Handler extends BaseHandlerWithClient
{

	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request
	 *
	 * @return Task
	 * @throws GenericError
	 */
	public function run(): Task {
		$taskFn = function (): TaskResult {
			$tableExists = $this->manticoreClient->hasTable($this->payload->table);
			if (!$tableExists) {
				throw GenericError::create(
					"Table {$this->payload->table} does not exist"
				);
			}

			$shards = $this->getShards($this->payload->table);
			$requests = [];
			foreach ($shards as $shard) {
				$requests[] = [
					'url' => $shard['url'],
					'path' => 'sql?mode=raw',
					'request' => "TRUNCATE TABLE {$shard['name']}",
				];
			}

			$this->manticoreClient->sendMultiRequest($requests);
			return TaskResult::none();
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * Parse local and agent shards from a distributed or sharded table schema.
	 *
	 * The vendor's Client::getTableShards() only recognizes the legacy
	 * type='distributed' form. Sharded tables use type='shard', so we parse
	 * SHOW CREATE TABLE directly here to support both.
	 *
	 * @param string $table
	 * @return array<array{name:string,url:string}>
	 */
	private function getShards(string $table): array {
		/** @var array{0:array{data:array<array{"Create Table":string}>}} $res */
		$res = $this->manticoreClient
			->sendRequest("SHOW CREATE TABLE $table OPTION force=1")
			->getResult();
		$tableSchema = $res[0]['data'][0]['Create Table'] ?? '';
		if (!$tableSchema) {
			throw GenericError::create("There is no such table: {$table}");
		}
		if (!str_contains($tableSchema, "type='distributed'")
			&& !str_contains($tableSchema, "type='shard'")) {
			throw GenericError::create("Table {$table} is not a distributed or sharded table");
		}
		if (!preg_match_all("/local='(?P<local>[^']+)'|agent='(?P<agent>[^']+)'/ius", $tableSchema, $m)) {
			throw GenericError::create('Failed to match shards from the schema');
		}
		$shards = [];
		foreach (array_filter($m['local']) as $name) {
			$shards[] = ['name' => $name, 'url' => ''];
		}
		foreach (array_filter($m['agent']) as $agent) {
			$ex = explode('|', $agent);
			$host = strtok($ex[0], ':');
			$port = (int)strtok(':');
			$name = (string)strtok(':');
			$shards[] = ['name' => $name, 'url' => "$host:$port"];
		}
		return $shards;
	}
}
