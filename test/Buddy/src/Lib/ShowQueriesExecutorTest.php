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
use Manticoresearch\Buddy\Lib\ManticoreHTTPClient;
use Manticoresearch\Buddy\Lib\ManticoreResponseBuilder;
use Manticoresearch\Buddy\Lib\ShowQueriesExecutor;
use Manticoresearch\Buddy\Lib\ShowQueriesRequest;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\Buddy\Network\Response;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class ShowQueriesExecutorTest extends TestCase {

	use TestHTTPServerTrait;

	public function testShowQueriesExecutesProperly(): void {
		echo "\nTesting the 'show queries' executes properly and we got the correct Manticore response received\n";
		$respBody = "[{\n"
			. '"columns":[{"proto":{"type":"string"}},{"host":{"type":"string"}},'
			. '{"ID":{"type":"long long"}},{"query":{"type":"string"}}],'
			. "\n"
			. '"data":[{"proto":"http","host":"127.0.0.1:584","ID":19,"query":"select"}'
			. "\n],\n"
			. '"total":1,'
			. "\n"
			. '"error":"",'
			. "\n"
			. '"warning":""'
			. "\n}]";
		$request = Request::fromArray(
			[
				'origMsg' => "sphinxql: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES'",
				'query' => 'SHOW QUERIES',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new ManticoreHTTPClient(new ManticoreResponseBuilder(), $serverUrl);
		$request = ShowQueriesRequest::fromNetworkRequest($request);

		$executor = new ShowQueriesExecutor($request);
		$refCls = new ReflectionClass($executor);
		$refCls->getProperty('manticoreClient')->setValue($executor, $manticoreClient);
		$task = $executor->run();
		$task->wait();
		$this->assertEquals(true, $task->isSucceed());
		$result = $task->getResult();
		$this->assertInstanceOf(Response::class, $result);
		$result = (array)json_decode((string)$result, true);
		$this->assertArrayHasKey('message', $result);
		$this->assertEquals($respBody, $result['message']);
		self::finishMockManticoreServer();
	}

}
