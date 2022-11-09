<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Lib\ErrorQueryExecutor;
use Manticoresearch\Buddy\Lib\ErrorQueryRequest;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class RunErrorQueryTaskTest extends TestCase {

	use TestHTTPServerTrait;

	protected function tearDown(): void {
		self::finishMockMntServer();
	}

	/**
	 * @param array{origMsg: string, query: string, format: RequestFormat, endpoint: MntEndpoint} $mntRequest
	 * @param string $mockServerUrl
	 * @param string $resp
	 */
	protected function runTask($mntRequest, $mockServerUrl, $resp): void {
		$request = ErrorQueryRequest::fromMntRequest($mntRequest);
		$executor = new ErrorQueryExecutor($request);
		$executor->setMntClient($mockServerUrl);
		$task = $executor->run();
		sleep(1);
		$task->getStatus();
		$this->assertEquals($resp, $task->getResult());
	}

	public function testTaskRunWithInsertQuery():void {
		echo "\nTesting the execution of a task with INSERT query request\n";
		$resp = "HTTP/1.1 200\r\nServer: buddy\r\nContent-Type: application/json; charset=UTF-8\r\n"
			. "Content-Length: 95\r\n\r\n"
			. '{"type":"http response","message":"[{\"total\":1,\"error\":\"\",\"warning\":\"\"}]","error":""}';
		$mockServerUrl = self::setUpMockMntServer(false);
		$mntRequest = [
			'origMsg' => "index 'test' absent, or does not support INSERT",
			'query' => 'INSERT INTO test(col1) VALUES(1)',
			'format' => RequestFormat::SQL,
			'endpoint' => MntEndpoint::Cli,
		];
		$this->runTask($mntRequest, $mockServerUrl, $resp);
	}

	public function testTaskRunWithShowQuery():void {
		echo "\nTesting the execution of a task with SHOW query request\n";
		$resp = "HTTP/1.1 200\r\nServer: buddy\r\nContent-Type: application/json; charset=UTF-8\r\n"
			. "Content-Length: 348\r\n\r\n"
			. '{"type":"http response","message":"[{\n\"columns\":[{\"proto\":{\"type\":\"string\"}},'
			. '{\"host\":{\"type\":\"string\"}},{\"ID\":{\"type\":\"long long\"}},{\"query\":{\"type\":\"string\"}}],'
			. '\n\"data\":[{\"proto\":\"http\",\"host\":\"127.0.0.1:584\",\"ID\":19,\"query\":\"select\"}'
			. '\n],\n\"total\":1,\n\"error\":\"\",\n\"warning\":\"\"\n}]","error":""}';
		$mockServerUrl = self::setUpMockMntServer(false);
		$mntRequest = [
			'origMsg' => "sphinxql: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES'",
			'query' => 'SHOW QUERIES',
			'format' => RequestFormat::SQL,
			'endpoint' => MntEndpoint::Cli,
		];
		$this->runTask($mntRequest, $mockServerUrl, $resp);
	}
}
