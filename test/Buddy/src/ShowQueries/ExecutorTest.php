<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Lib\TableFormatter;
use Manticoresearch\Buddy\Lib\Task\Task;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use Manticoresearch\Buddy\Network\ManticoreClient\Response;
use Manticoresearch\Buddy\Network\Request as NetRequest;
use Manticoresearch\Buddy\ShowQueries\Executor;
use Manticoresearch\Buddy\ShowQueries\Request;
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
				'error' => "sphinxql: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES'",
				'payload' => 'SHOW QUERIES',
				'version' => 1,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new HTTPClient(new Response(), $serverUrl);
		$request = Request::fromNetworkRequest($request);

		$executor = new Executor($request);
		$executor->setManticoreClient($manticoreClient);
		$executor->setTableFormatter(new TableFormatter());
		$task = $executor->run(Task::createRuntime());
		$task->wait();
		$this->assertEquals(true, $task->isSucceed());
		$result = $task->getResult()->getMessage();
		$this->assertIsArray($result);
		$this->assertEquals($respBody, $result);
		self::finishMockManticoreServer();
	}
}
