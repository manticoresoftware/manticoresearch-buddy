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
use Manticoresearch\Buddy\Lib\ContainerBuilder;
use Manticoresearch\Buddy\Lib\ErrorQueryExecutor;
use Manticoresearch\Buddy\Lib\ErrorQueryRequest;
use Manticoresearch\Buddy\Network\Request;
//@codingStandardsIgnoreStart
use Manticoresearch\Buddy\Network\Response;
//@codingStandardsIgnoreEnd
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class RunErrorQueryTaskTest extends TestCase {

	use TestHTTPServerTrait;

	protected function tearDown(): void {
		self::finishMockManticoreServer();
	}

	/**
	 * @param Request $request
	 * @param string $mockServerUrl
	 * @param string $resp
	 */
	protected function runTask(Request $request, $mockServerUrl, $resp): void {
		$container = ContainerBuilder::create();
		$commandRequest = $container->get('ErrorQueryRequest');
		if (!isset($commandRequest) || !($commandRequest instanceof ErrorQueryRequest)) {
			$this->fail('Error query request is not instantiated');
		}
		$executor = $container->get('ErrorQueryExecutor');
		if (!isset($executor) || !($executor instanceof ErrorQueryExecutor)) {
			$this->fail('Error query executor is not instantiated');
		}

		$commandRequest->request = $request;
		$executor->request = $commandRequest;

		$cl = $executor->getManticoreClient();
		if (!isset($cl)) {
			$this->fail('Manticore client is not instantiated');
		}
		$cl->setServerUrl($mockServerUrl);

		$task = $executor->run();
		$task->wait();

		$this->assertEquals(true, $task->isSucceed());
		/** @var Response */
		$result = $task->getResult();
		$this->assertEquals($resp, $result);
	}

	public function testTaskRunWithInsertQuery(): void {
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

	public function testTaskRunWithShowQuery():void {
		echo "\nTesting the execution of a task with SHOW query request\n";
		$resp = '{"type":"http response","message":"[{\n\"columns\":'
				. '[{\"id\":{\"type\":\"long long\"}},{\"proto\":{\"type\":\"string\"}},'
				. '{\"state\":{\"type\":\"string\"}},{\"host\":{\"type\":\"string\"}},'
				. '{\"connid\":{\"type\":\"long long\"}},{\"killed\":{\"type\":\"string\"}}'
				. ',{\"last cmd\":{\"type\":\"string\"}}],\n\"data\":[{\"id\":1,\"proto\":\"http\",\"state\":\"query\",'
				. '\"host\":\"127.0.0.1:584\",\"connid\":19,\"killed\":\"0\",\"last cmd\":\"select\"}\n]'
				. ',\n\"total\":1,\n\"error\":\"\",\n\"warning\":\"\"\n}]","error":""}'
			;
		$mockServerUrl = self::setUpMockManticoreServer(false);
		$request = Request::fromArray(
			[
				'origMsg' => "sphinxql: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES'",
				'query' => 'SHOW QUERIES',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$this->runTask($request, $mockServerUrl, $resp);
	}
}
