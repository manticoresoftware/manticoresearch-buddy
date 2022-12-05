<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
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
			. '{"proto":{"type":"string"}},{"host":{"type":"string"}}],'
			. "\n"
			. '"data":[{"proto":"http","host":"127.0.0.1:584","id":19,"query":"select"}'
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
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new HTTPClient(new Response(), $serverUrl);
		$request = Request::fromNetworkRequest($request);

		$executor = new Executor($request);
		$refCls = new ReflectionClass($executor);
		$refCls->getProperty('manticoreClient')->setValue($executor, $manticoreClient);
		$task = $executor->run();
		$task->wait();
		$this->assertEquals(true, $task->isSucceed());
		$result = $task->getResult();
		$this->assertIsArray($result);
		$this->assertEquals($respBody, $result);
		self::finishMockManticoreServer();
	}
}
