<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class DescHandler extends BaseHandlerWithClient {
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
		$taskFn = function (): TaskResult {
			$table = $this->payload->table;
			// TODO: think about the way to refactor it and remove duplication
			$q = "DESC {$table} OPTION force=1";
			$resp = $this->manticoreClient->sendRequest($q);
			/** @var array{0:array{data:array<array{Type:string,Agent:string}>}} $result */
			$result = $resp->getResult();

			$shard = $this->findShardFromResult($result[0]['data']);
			if (!isset($shard)) {
				return TaskResult::withError('Failed to find structure from local shards or agents');
			}

			$q = $this->buildShardQuery($shard);
			$resp = $this->manticoreClient->sendRequest($q);
			return TaskResult::fromResponse($resp);
		};
		$task = Task::create($taskFn, []);
		return $task->run();
	}

	/**
	 * Find a shard from the DESC result data
	 * @param array<array{Type:string,Agent:string}> $data
	 * @return string|null
	 */
	private function findShardFromResult(array $data): ?string {
		// First try to find local shards (RF=1 case)
		$shard = $this->findLocalShard($data);
		if (isset($shard)) {
			return $shard;
		}

		// If no local found, try agent entries (RF>1 case)
		return $this->findShardFromAgent($data);
	}

	/**
	 * Find shard from local entries
	 * @param array<array{Type:string,Agent:string}> $data
	 * @return string|null
	 */
	private function findLocalShard(array $data): ?string {
		foreach ($data as $row) {
			if ($row['Type'] === 'local') {
				return $row['Agent'];
			}
		}
		return null;
	}

	/**
	 * Find shard from agent entries
	 * @param array<array{Type:string,Agent:string}> $data
	 * @return string|null
	 */
	private function findShardFromAgent(array $data): ?string {
		$currentNode = Node::findId($this->manticoreClient);
		foreach ($data as $row) {
			if ($row['Type'] === 'local') {
				continue;
			}

			// Extract shard name from agent string like "127.0.0.1:1312:system.test2_s0|..."
			$agentParts = explode('|', $row['Agent']);
			$firstAgent = $agentParts[0]; // "127.0.0.1:1312:system.test2_s0"
			$shardParts = explode(':', $firstAgent);
			$shardName = array_pop($shardParts);
			$shardNode = implode(':', $shardParts);
			if ($currentNode === $shardNode) {
				return $shardName;
			}
		}
		return null;
	}

	/**
	 * Build the query for the shard
	 * @param string $shard
	 * @return string
	 * @throws RuntimeException
	 */
	private function buildShardQuery(string $shard): string {
		return match ($this->payload->type) {
			'show' => "SHOW CREATE TABLE {$shard}",
			'desc', 'describe' => "DESC {$shard}",
			default => throw new RuntimeException("Unknown type: {$this->payload->type}"),
		};
	}
}
