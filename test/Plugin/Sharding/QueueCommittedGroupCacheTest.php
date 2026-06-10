<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Sharding\Cluster;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Queue;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use PHPUnit\Framework\TestCase;

final class QueueCommittedGroupCacheTest extends TestCase {

	public function testIsGroupCommittedDoesNotCacheFalseBeforeCommitFlagAppears(): void {
		$group = 'rebalance_master_fail_123';
		$query = 'SELECT value[0] AS value FROM system.sharding_state '
			. "WHERE REGEX(`key`, 'rebalance_committed:.+')";
		$client = $this->createMock(Client::class);
		$client->expects($this->exactly(2))
			->method('sendRequest')
			->with($this->stringContains($query))
			->willReturnOnConsecutiveCalls(
				$this->createResponse([]),
				$this->createResponse([['value' => $group]]),
			);

		$cluster = new Cluster($client, 'i', '127.0.0.1:63312');
		$queue = new Queue($cluster, $client);
		$method = new ReflectionMethod(Queue::class, 'isGroupCommitted');
		$method->setAccessible(true);

		$this->assertFalse($method->invoke($queue, $group));
		$this->assertTrue($method->invoke($queue, $group));
	}

	/**
	 * @param array<array{value:string}> $rows
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
