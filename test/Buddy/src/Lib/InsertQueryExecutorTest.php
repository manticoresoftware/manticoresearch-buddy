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
use Manticoresearch\Buddy\Lib\InsertQueryExecutor;
use Manticoresearch\Buddy\Lib\InsertQueryRequest;
use Manticoresearch\Buddy\Lib\ManticoreHTTPClient;
use Manticoresearch\Buddy\Lib\ManticoreResponse;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\Buddy\Network\Response;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class InsertQueryExecutorTest extends TestCase {

	use TestHTTPServerTrait;

	protected function tearDown(): void {
		self::finishMockManticoreServer();
	}

	/**
	 * @param Request $networkRequest
	 * @param string $serverUrl
	 * @param string $resp
	 */
	protected function runTask(Request $networkRequest, string $serverUrl, string $resp): void {
		$request = InsertQueryRequest::fromNetworkRequest($networkRequest);

		$manticoreClient = new ManticoreHTTPClient(new ManticoreResponse(), $serverUrl);
		$executor = new InsertQueryExecutor($request);
		$executor->setManticoreClient($manticoreClient);
		ob_flush();
		$task = $executor->run();
		$task->wait();

		$this->assertEquals(true, $task->isSucceed());
		/** @var Response */
		$result = $task->getResult();
		$this->assertEquals($resp, $result);
	}

	public function testInsertQueryExecutesProperly(): void {
		echo "\nTesting the execution of a task with INSERT query request\n";
		$resp = '{"type":"http response","message":"[{\"total\":1,\"error\":\"\",\"warning\":\"\"}]","error":""}';
		$mockServerUrl = self::setUpMockManticoreServer(false);
		$request = Request::fromArray(
			[
				'origMsg' => "index 'test' absent, or does not support INSERT",
				'query' => 'INSERT INTO test(col1) VALUES(1)',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$this->runTask($request, $mockServerUrl, $resp);
	}
}
