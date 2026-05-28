<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Set;
use Ds\Vector;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Cluster;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Queue;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Table;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type QueueItem array{
 *   id:int,
 *   node:string,
 *   query:string,
 *   rollback_query:string,
 *   wait_for_id:int,
 *   operation_group:string,
 *   status:string
 * }
 */
final class FailedNodeRebalanceQueueSequencingTest extends TestCase {

	public function testFailedNodeRebalanceFlushesClusterAttachWorkBeforeWrapperRecreation(): void {
		/** @var array<int,QueueItem> $queueItems */
		$queueItems = [];
		$okResponse = $this->createResponse([]);

		$client = $this->makeQueueCapturingClient($queueItems, $okResponse);

		$cluster = new Cluster($client, 'i', 'node2');
		$queue = new Queue($cluster, $client);
		$table = new Table($client, $cluster, 'master_fail', 'title text, gid int', '');

		$schema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1]),
				'connections' => new Set(['node1', 'node2', 'node3']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([1, 2]),
				'connections' => new Set(['node1', 'node2', 'node3']),
			],
			[
				'node' => 'node3',
				'shards' => new Set([0, 2]),
				'connections' => new Set(['node1', 'node2', 'node3']),
			],
			]
		);

		$newSchema = new Vector(
			[
			[
				'node' => 'node2',
				'shards' => new Set([0, 1, 2]),
				'connections' => new Set(['node2', 'node3']),
			],
			[
				'node' => 'node3',
				'shards' => new Set([0, 1, 2]),
				'connections' => new Set(['node2', 'node3']),
			],
			]
		);

		$method = new ReflectionMethod(Table::class, 'handleFailedNodesRebalance');
		$method->setAccessible(true);
		/** @var array<string,int> $tailIds */
		$tailIds = $method->invoke(
			$table,
			$queue,
			$schema,
			$newSchema,
			new Set(['node1']),
			'rebalance_master_fail_test'
		);

		$attachItems = array_filter(
			$queueItems,
			static fn(array $item): bool => $item['operation_group'] === 'rebalance_master_fail_test'
				&& str_starts_with($item['query'], 'ALTER CLUSTER ')
				&& str_contains($item['query'], ' ADD system.master_fail_s')
		);
		$this->assertNotEmpty(
			$attachItems,
			'Failed-node rebalance must queue ALTER CLUSTER ADD to materialize missing live replicas'
		);
		$lastAttachId = max(array_keys($attachItems));

		/** @var array<int,QueueItem> $wrapperDropItems */
		$wrapperDropItems = array_filter(
			$queueItems,
			static fn(array $item): bool => isset($tailIds[$item['node']])
				&& $item['operation_group'] === 'rebalance_master_fail_test'
				&& $item['query'] === 'DROP TABLE IF EXISTS master_fail OPTION force=1'
		);
		$this->assertCount(2, $wrapperDropItems, 'Expected wrapper drop on both surviving nodes');
		foreach ($wrapperDropItems as $item) {
			$this->assertSame(
				$lastAttachId,
				$item['wait_for_id'],
				'Wrapper drop must wait for the last live ALTER CLUSTER ADD queue item'
			);
		}

		$lastWrapperDropId = max(array_keys($wrapperDropItems));
		foreach ($tailIds as $tailId) {
			$this->assertArrayHasKey($tailId, $queueItems);
			$this->assertStringStartsWith('CREATE TABLE `master_fail`', $queueItems[$tailId]['query']);
			$this->assertSame(
				$lastWrapperDropId,
				$queueItems[$tailId]['wait_for_id'],
				'Wrapper create must remain chained after the wrapper drops'
			);
		}
	}

	/**
	 * @param array<array<string,mixed>> $rows
	 */
	private function createResponse(array $rows): Response {
		$response = $this->createMock(Response::class);
		$response->method('getResult')->willReturn(
			\Manticoresearch\Buddy\Core\Network\Struct::fromData(
				[[
					'error' => '',
					'warning' => '',
					'total' => sizeof($rows),
					'data' => $rows,
				]]
			)
		);
		$response->method('hasError')->willReturn(false);
		$response->method('getError')->willReturn('');
		return $response;
	}

	/**
	 * @param array<int,QueueItem> $queueItems
	 */
	private function makeQueueCapturingClient(array &$queueItems, Response $okResponse): Client {
		$client = $this->createMock(Client::class);
		$client->method('sendRequest')->willReturnCallback(
			function (string $query) use (&$queueItems, $okResponse): Response {
				return $this->handleSendRequest($query, $queueItems, $okResponse);
			}
		);
		return $client;
	}

	/**
	 * @param array<int,QueueItem> $queueItems
	 */
	private function handleSendRequest(string $query, array &$queueItems, Response $okResponse): Response {
		if (preg_match('/INSERT\s+INTO\s+i:system\.sharding_queue/is', $query)) {
			$item = $this->parseQueueInsert($query);
			$queueItems[$item['id']] = $item;
			return $okResponse;
		}

		$schemaQueryRegex = '/SELECT\s+node\s+FROM\s+system\.sharding_table.*'
			. 'shards\s+in\s*\(([^)]+)\)/is';
		if (!preg_match($schemaQueryRegex, $query, $m)) {
			return $okResponse;
		}

		$shardList = array_map('intval', preg_split('/\s*,\s*/', trim($m[1])) ?: []);
		return $this->createResponse($this->schemaRowsForShards($shardList));
	}

	/**
	 * @param list<int> $shardList
	 * @return array<array{node:string}>
	 */
	private function schemaRowsForShards(array $shardList): array {
		foreach ($shardList as $shard) {
			if ($shard === 0) {
				return [
					['node' => 'node1'],
					['node' => 'node3'],
				];
			}

			if ($shard === 1) {
				return [
					['node' => 'node1'],
					['node' => 'node2'],
				];
			}
		}

		return [];
	}

	/**
	 * @return array{id:int,node:string,query:string,rollback_query:string,wait_for_id:int,operation_group:string,status:string}
	 */
	private function parseQueueInsert(string $sql): array {
		$valuesPos = stripos($sql, 'VALUES');
		self::assertNotFalse($valuesPos, 'Queue insert must contain VALUES clause');
		$tuple = trim(substr($sql, $valuesPos + 6));
		$start = strpos($tuple, '(');
		$end = strrpos($tuple, ')');
		self::assertNotFalse($start);
		self::assertNotFalse($end);
		$tuple = substr($tuple, $start + 1, $end - $start - 1);

		$fields = array_map(
			static fn(?string $field): string => trim((string)$field),
			str_getcsv($tuple, ',', "'", '\\')
		);

		self::assertGreaterThanOrEqual(12, sizeof($fields), 'Unexpected queue insert tuple shape');

		return [
			'id' => (int)$fields[0],
			'node' => $fields[1],
			'query' => $this->decodeSqlString($fields[2]),
			'rollback_query' => $this->decodeSqlString($fields[3]),
			'wait_for_id' => (int)$fields[4],
			'operation_group' => $this->decodeSqlString($fields[6]),
			'status' => $this->decodeSqlString($fields[8]),
		];
	}

	private function decodeSqlString(string $value): string {
		return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
	}
}
