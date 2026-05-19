<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source\ListSourceHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use PHPUnit\Framework\TestCase;

final class BaseListHandlerTest extends TestCase {
	public function testShowSourcesReturnsNameColumnMetadata(): void {
		$tablesResponse = Response::fromBody(
			(string)json_encode(
				[[
					'error' => '',
					'warning' => '',
					'total' => 3,
					'data' => [
						['Table' => 'system.source_auth_allowed', 'Type' => 'rt'],
						['Table' => 'system.source_auth_denied', 'Type' => 'rt'],
						['Table' => 'system.source_kafka', 'Type' => 'rt'],
					],
				]]
			)
		);

		$client = $this->createMock(Client::class);
		$client->expects($this->once())
			->method('sendRequest')
			->with($this->equalTo('SHOW TABLES FROM system'))
			->willReturn($tablesResponse);

		/** @var Payload<array{SHOW: array{array{expr_type: string, base_expr?: string}}}> $payload */
		$payload = new Payload();
		$handler = new ListSourceHandler($payload);
		$handler->setManticoreClient($client);

		go(
			function () use ($handler): void {
				$task = $handler->run();
				$task->wait(true);
				/** @var array{0: array{columns: array<int, array<string, array{type: string}>>, data: array<int, array{name: string}>}} $struct */
				$struct = $task->getResult()->getStruct();

				$this->assertSame(
					[['name' => ['type' => 'string']]],
					$struct[0]['columns']
				);
				$this->assertSame(
					[
						['name' => 'auth_allowed'],
						['name' => 'auth_denied'],
						['name' => 'kafka'],
					],
					$struct[0]['data']
				);
			}
		);
		Swoole\Event::wait();
	}
}
