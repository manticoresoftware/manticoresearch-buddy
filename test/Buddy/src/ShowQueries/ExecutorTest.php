<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request as NetRequest;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Plugin\ShowQueries\Handler;
use Manticoresearch\Buddy\Plugin\ShowQueries\Payload;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class ExecutorTest extends TestCase {

	use TestHTTPServerTrait;

	public function testShowQueriesExecutesProperly(): void {
		echo "\nTesting the 'show queries' executes properly and we got the correct Manticore response received\n";
		$respBody = json_decode(
			"[{\n"
			. '"columns":[{"id":{"type":"long long"}},{"query":{"type":"string"}},'
			. '{"protocol":{"type":"string"}},{"host":{"type":"string"}}],'
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
				'version' => 1,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new HTTPClient(new Response(), $serverUrl);
		$payload = Payload::fromRequest($request);

		$handler = new Handler($payload);
		$handler->setManticoreClient($manticoreClient);
		$handler->setTableFormatter(new TableFormatter());
		$task = $handler->run(Task::createRuntime());
		$task->wait();
		$this->assertEquals(true, $task->isSucceed());
		$result = $task->getResult()->getStruct();
		$this->assertIsArray($result);
		$this->assertEquals($respBody, $result);
		self::finishMockManticoreServer();
	}
}
