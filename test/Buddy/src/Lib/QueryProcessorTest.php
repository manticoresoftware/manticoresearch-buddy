<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Backup\Executor as BackupExecutor;
use Manticoresearch\Buddy\Backup\Request as BackupRequest;
use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\CommandNotAllowed;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\InsertQuery\Executor as InsertQueryExecutor;
use Manticoresearch\Buddy\InsertQuery\Request as InsertQueryRequest;
use Manticoresearch\Buddy\Lib\QueryProcessor;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\Buddy\ShowQueries\Executor as ShowQueriesExecutor;
use Manticoresearch\Buddy\ShowQueries\Request as ShowQueriesRequest;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class QueryProcessorTest extends TestCase {

	use TestProtectedTrait;
	public function testCommandProcessOk(): void {
		echo "\nTesting the processing of execution command\n";
		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'BACKUP TO /tmp',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('configSettings', []);
		$executor = QueryProcessor::process($request);
		$this->assertInstanceOf(BackupExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(BackupRequest::class, $request);

		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'SHOW QUERIES',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$executor = QueryProcessor::process($request);
		$this->assertInstanceOf(ShowQueriesExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(ShowQueriesRequest::class, $request);
	}

	public function testUnsupportedCommandProcessFail(): void {
		echo "\nTesting the processing of unsupported execution command\n";
		$this->expectException(SQLQueryCommandNotSupported::class);
		$this->expectExceptionMessage('Failed to handle query: TEST');
		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'TEST',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('configSettings', []);
		QueryProcessor::process($request);
	}

	public function testNotAllowedCommandProcessFail(): void {
		echo "\nTesting the processing of not allowed execution command\n";

		$netRequest = Request::fromArray(
			[
				'version' => 1,
				'error' => "table 'test' absent, or does not support INSERT",
				'payload' => 'INSERT INTO test(col1) VALUES("test")',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('configSettings', ['searchd.auto_schema' => '1']);
		$executor = QueryProcessor::process($netRequest);
		$this->assertInstanceOf(InsertQueryExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(InsertQueryRequest::class, $request);

		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->setStaticPropertyValue('configSettings', ['searchd.auto_schema' => '0']);
		$this->expectException(CommandNotAllowed::class);
		$this->expectExceptionMessage('Request handling is disabled: INSERT INTO test(col1) VALUES("test")');
		QueryProcessor::process($netRequest);
	}
}
