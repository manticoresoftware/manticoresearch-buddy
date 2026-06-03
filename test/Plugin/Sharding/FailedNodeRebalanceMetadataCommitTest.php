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

final class FailedNodeRebalanceMetadataCommitTest extends TestCase {

	public function testFailedNodeRebalanceDoesNotRewriteCommittedSchemeImmediately(): void {
		$queries = [];
		$okResponse = $this->createResponse([]);

		$client = $this->createMock(Client::class);
		$client->method('sendRequest')->willReturnCallback(
			function (string $query) use (&$queries, $okResponse): Response {
				$queries[] = $query;
				$schemaQueryRegex = '/SELECT\s+node\s+FROM\s+system\.sharding_table.*'
					. 'shards\s+in\s*\(([^)]+)\)/is';
				if (preg_match($schemaQueryRegex, $query, $m)) {
					$shardList = array_map('intval', preg_split('/\s*,\s*/', trim($m[1])) ?: []);
					$rows = [];
					foreach ($shardList as $shard) {
						if ($shard !== 0) {
							continue;
						}
						$rows = [
							['node' => 'node1'],
							['node' => 'node2'],
							['node' => 'node3'],
						];
						break;
					}
					return $this->createResponse($rows);
				}
				return $okResponse;
			}
		);

		$cluster = new Cluster($client, 'c', 'node1');
		$queue = new Queue($cluster, $client);
		$table = new Table($client, $cluster, 'master_fail', 'id bigint', '');

		$schema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0]),
				'connections' => new Set(['node1', 'node2', 'node3']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([0]),
				'connections' => new Set(['node1', 'node2', 'node3']),
			],
			[
				'node' => 'node3',
				'shards' => new Set([0]),
				'connections' => new Set(['node1', 'node2', 'node3']),
			],
			]
		);

		$newSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0]),
				'connections' => new Set(['node1', 'node2']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([0]),
				'connections' => new Set(['node1', 'node2']),
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
			new Set(['node3']),
			'rebalance_master_fail_test'
		);

		$this->assertNotEmpty($tailIds, 'Failed-node rebalance should still queue wrapper work');

		$metadataWrites = array_values(
			array_filter(
				$queries,
				static fn(string $query): bool => (bool)preg_match(
					'/\b(?:DELETE\s+FROM|INSERT\s+INTO)\s+system\.sharding_table\b/i',
					$query
				)
			)
		);
		$this->assertSame(
			[],
			$metadataWrites,
			'Rebalance should not rewrite system.sharding_table before queued physical work completes'
		);
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
}
