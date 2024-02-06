<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\DistributedInsert;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class Handler extends BaseHandlerWithClient {
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
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, Client $client): TaskResult {
			$ex = explode(' ', $payload->query);
			$table = $ex[2];
			$ex = explode(':', $table);
			$cluster = '';
			if (sizeof($ex) > 1) {
				$cluster = $ex[0];
				$table = $ex[1];
			}
			$res = $client->sendRequest("SHOW CREATE TABLE $table")->getResult();
			$tableSchema = $res[0]['data'][0]['Create Table'] ?? '';
			if (!str_contains($tableSchema, "type='distributed'")) {
				throw new RuntimeException('The table is not distributed');
			}

			if (!preg_match_all("/local='(?P<local>[^']+)'|agent='(?P<agent>[^']+)'/ius", $tableSchema, $m)) {
				throw new RuntimeException('Failed to match tables from the schema');
			}

			$tables = [];
			$locals = array_filter($m['local']);
			foreach ($locals as $t) {
				$tables[] = [
					'name' => $t,
					'url' => '',
				];
			}
			$agents = array_filter($m['agent']);
			foreach ($agents as $agent) {
				$ex = explode('|', $agent);
				$host = strtok($ex[0], ':');
				$port = (int)strtok(':');
				$t = strtok(':');
				$tables[] = [
					'name' => $t,
					'url' => "$host:$port",
				];
			}
			$tableCount = sizeof($tables);
			$parts = explode('values', $payload->query);
			$id = (int)ltrim($parts[1], ' (');
			$shard = $id % $tableCount;

			$info = $tables[$shard];
			if ($info['url']) {
				$client->setServerUrl($info['url']);
			}
			$query = str_ireplace(
				'insert into ' . ($cluster ? "$cluster:$table" : $table),
				"insert into {$info['name']}",
				$payload->query
			);

			$response = $client->sendRequest($query)->getResult();
			return TaskResult::raw($response);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}
}
