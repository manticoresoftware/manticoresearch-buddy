<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Show\Payload;
use Manticoresearch\Buddy\Base\Plugin\Show\QueriesHandler as Handler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;
use Manticoresearch\Buddy\CoreTest\Trait\TestHTTPServerTrait;
use Manticoresearch\Buddy\CoreTest\Trait\TestInEnvironmentTrait;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

class CliTableHandlerTest extends TestCase {

	use TestHTTPServerTrait;
	use TestInEnvironmentTrait;

	public function testCliTableOk(): void {
		echo "\nTesting that request to '/cli' is executed properly\n";
		$respBody = '+----+--------+----------+---------------+'
			. "\n"
			. '| id | query  | protocol | host          |'
			. "\n"
			. '+----+--------+----------+---------------+'
			. "\n"
			. '| 19 | select | http     | 127.0.0.1:584 |'
			. "\n"
			. '+----+--------+----------+---------------+'
			. "\n"
			. '1 row in set';

		$request = Request::fromArray(
			[
				'error' => '',
				'payload' => 'SHOW QUERIES',
				'version' => 2,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Cli,
				'path' => 'cli',
			]
		);

		self::setBuddyVersion();
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new HTTPClient(new Response(), $serverUrl);
		$tableFormatter = new TableFormatter();
		Payload::$type = 'queries';
		$payload = Payload::fromRequest($request);
		$handler = new Handler($payload);
		$refCls = new ReflectionClass($handler);
		$refCls->getProperty('manticoreClient')->setValue($handler, $manticoreClient);
		$refCls->getProperty('tableFormatter')->setValue($handler, $tableFormatter);

		$cid = go(
			function () use ($handler, $respBody) {
				$task = $handler->run();
				$task->wait(true);

				$this->assertEquals(true, $task->isSucceed());
				$result = $task->getResult()->getStruct();
				$this->assertIsString($result);
				$this->assertStringContainsString($respBody, $result);
				self::finishMockManticoreServer();
			}
		);
		Coroutine::join([$cid]);
	}
}
