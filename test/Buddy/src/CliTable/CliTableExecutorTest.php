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

class CliTableExecutorTest extends TestCase {

	use TestHTTPServerTrait;

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

		$request = NetRequest::fromArray(
			[
				'error' => '',
				'payload' => 'SHOW QUERIES',
				'version' => 1,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Cli,
				'path' => 'cli',
			]
		);
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new HTTPClient(new Response(), $serverUrl);
		$tableFormatter = new TableFormatter();
		$request = Request::fromNetworkRequest($request);

		$executor = new Executor($request);
		$refCls = new ReflectionClass($executor);
		$refCls->getProperty('manticoreClient')->setValue($executor, $manticoreClient);
		$refCls->getProperty('tableFormatter')->setValue($executor, $tableFormatter);
		$task = $executor->run(Task::createRuntime());
		$task->wait();
		$this->assertEquals(true, $task->isSucceed());
		$result = $task->getResult()->getMessage();
		$this->assertIsString($result);
		$this->assertStringContainsString($respBody, $result);
		self::finishMockManticoreServer();
	}
}
