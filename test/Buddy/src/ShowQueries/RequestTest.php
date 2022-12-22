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
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Network\Request as NetRequest;
use Manticoresearch\Buddy\ShowQueries\Request;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class ShowQueriesRequestTest extends TestCase {

	use TestProtectedTrait;

	public function testCreationFromNetworkRequestOk(): void {
		echo "\nTesting the creation of InsertQuery\Request from manticore request data struct\n";
		$request = NetRequest::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'SHOW QUERIES',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$request = Request::fromNetworkRequest($request);
		$this->assertInstanceOf(Request::class, $request);
		$this->assertEquals('SELECT * FROM @@system.sessions', $request->query);
	}

	public function testCreationFromNetworkRequestFail(): void {
		echo "\nTesting the fails on the creation of InsertQuery\Request from manticore request data struct\n";
		$request = NetRequest::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'SHOW QUERIES 123',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromNetworkRequest', [$request]);
		$this->assertEquals(SQLQueryCommandNotSupported::class, $exCls);
		$this->assertEquals('Invalid query passed: SHOW QUERIES 123', $exMsg);
	}
}
