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
use Manticoresearch\Buddy\Lib\BackupExecutor;
use Manticoresearch\Buddy\Lib\BackupRequest;
use Manticoresearch\Buddy\Lib\ErrorQueryExecutor;
use Manticoresearch\Buddy\Lib\ErrorQueryRequest;
use Manticoresearch\Buddy\Lib\QueryProcessor;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class QueryProcessorTest extends TestCase {

	use TestProtectedTrait;
	public function testCommandProcessOk(): void {
		echo "\nTesting the processing of execution command\n";
		$request = Request::fromArray(
			[
				'origMsg' => '',
				'query' => 'BACKUP',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$executor = QueryProcessor::process($request);
		$this->assertInstanceOf(BackupExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(BackupRequest::class, $request);

		$request = Request::fromArray(
			[
				'origMsg' => '',
				'query' => 'ERROR QUERY',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$executor = QueryProcessor::process($request);
		$this->assertInstanceOf(ErrorQueryExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(ErrorQueryRequest::class, $request);
	}

	public function testCommandProcessFail(): void {
		echo "\nTesting the processing of incorrect execution command\n";
		// $this->expectException(SQLQueryCommandNotSupported::class);
		// $this->expectExceptionMessage("Command 'TEST' is not supported");
		$request = Request::fromArray(
			[
				'origMsg' => '',
				'query' => 'TEST',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$executor = QueryProcessor::process($request);
		$this->assertInstanceOf(ErrorQueryExecutor::class, $executor);
	}

}
