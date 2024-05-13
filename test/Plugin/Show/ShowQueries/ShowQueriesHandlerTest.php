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
use Manticoresearch\Buddy\Core\Network\Request as NetRequest;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;
use Manticoresearch\Buddy\CoreTest\Trait\TestHTTPServerTrait;
use Manticoresearch\Buddy\CoreTest\Trait\TestInEnvironmentTrait;
use PHPUnit\Framework\TestCase;

class ShowQueriesHandlerTest extends TestCase {

	use TestHTTPServerTrait;
	use TestInEnvironmentTrait;

	public function testShowQueriesExecutesProperly(): void {
		echo "\nTesting the 'show queries' executes properly and we got the correct Manticore response received\n";
		$respBody = json_decode(
			"[{\n"
			. '"columns":[{"id":{"type":"long long"}},{"query":{"type":"string"}},'
			. '{"time":{"type":"string"}},{"protocol":{"type":"string"}},{"host":{"type":"string"}}],'
			. "\n"
			. '"data":[{"protocol":"http","host":"127.0.0.1:584","id":19,"query":"select"}'
			. "\n],\n"
			. '"total":1,'
			. "\n"
			. '"error":"",'
			. "\n"
			. '"warning":""'
			. "\n}]", true
		);

		$request = NetRequest::fromArray(
			[
				'error' => "P01: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES'",
				'payload' => 'SHOW QUERIES',
				'version' => 2,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);
		$serverUrl = self::setUpMockManticoreServer(false);
		self::setBuddyVersion();
		$manticoreClient = new HTTPClient(new Response(), $serverUrl);
		$manticoreClient->setIsAsync(false);
		Payload::$type = 'queries';
		$payload = Payload::fromRequest($request);

		$handler = new Handler($payload);
		$handler->setManticoreClient($manticoreClient);
		$handler->setTableFormatter(new TableFormatter());
		go(
			function () use ($handler, $respBody) {
				$task = $handler->run();
				$task->wait();
				$this->assertEquals(true, $task->isSucceed());
				$result = $task->getResult()->getStruct();
				$this->assertIsArray($result);
				$this->assertEquals($respBody, $result);
				self::finishMockManticoreServer();
			}
		);

		Swoole\Event::wait();
	}
}
