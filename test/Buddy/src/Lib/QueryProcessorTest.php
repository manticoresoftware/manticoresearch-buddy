<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\SQLQueryCommandMissing;
use Manticoresearch\Buddy\Exception\SQLQueryCommandNotSupported;
use Manticoresearch\Buddy\Exception\UnvalidMntRequestError;
use Manticoresearch\Buddy\Lib\BackupExecutor;
use Manticoresearch\Buddy\Lib\BackupRequest;
use Manticoresearch\Buddy\Lib\ErrorQueryExecutor;
use Manticoresearch\Buddy\Lib\ErrorQueryRequest;
use Manticoresearch\Buddy\Lib\QueryProcessor;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class QueryProcessorTest extends TestCase {

	use TestProtectedTrait;

	public function testMakeCommandOk(): void {
		echo "\nTesting the making of request command\n";
		$mntRequest = [
			'errorMsg' => '',
			'query' => 'INSERT INTO test(col1) VALUES(1)',
			'format' => RequestFormat::SQL,
			'endpoint' => '',
		];
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->getProperty('mntRequest')->setValue($mntRequest);
		$this->assertEquals('ERROR QUERY', self::invokeMethod(QueryProcessor::class, 'makeCommand'));

		$mntRequest['query'] = 'BACKUP test';
		$refCls->getProperty('mntRequest')->setValue($mntRequest);
		$this->assertEquals('BACKUP', self::invokeMethod(QueryProcessor::class, 'makeCommand'));
	}

	public function testMakeCommandFail(): void {
		echo "\nTesting the fail on an incorrect request query\n";
		$mntRequest['query'] = '';
		$refCls = new ReflectionClass(QueryProcessor::class);
		$refCls->getProperty('mntRequest')->setValue($mntRequest);
		$this->expectException(SQLQueryCommandMissing::class);
		$this->expectExceptionMessage('Command missing in SQL query');
		self::invokeMethod(QueryProcessor::class, 'makeCommand');
	}

	public function testManticoreRequestValidationOk(): void {
		echo "\nTesting the validation of a correct Manticore request\n";
		$request = [
			'type' => 'some error',
			'message' => 'some query',
			'reqest_type' => 'sphinqxl',
			'endpoint' => 'cli',
			'listen' => '127.0.0.1:9308',
		];
		self::invokeMethod(QueryProcessor::class, 'validateMntRequest', [$request]);
		$this->assertTrue(true);
	}

	public function testManticoreRequestValidationFail(): void {
		echo "\nTesting the validation of an incorrect Manticore request\n";
		$request = [
			'type' => 'some error',
			'message' => 'some query',
			'reqest_type' => 'test',
			'endpoint' => 'cli',
			'listen' => '127.0.0.1:9308',
		];
		$this->expectException(UnvalidMntRequestError::class);
		$this->expectExceptionMessage("Manticore request parse error: Unknown request type 'test'");
		try {
			self::invokeMethod(QueryProcessor::class, 'validateMntRequest', [$request]);
		} finally {
			$request['reqest_type'] = [];
			$this->expectException(UnvalidMntRequestError::class);
			$this->expectExceptionMessage("Manticore request parse error: Field 'reqest_type' must be a string");
			try {
				self::invokeMethod(QueryProcessor::class, 'validateMntRequest', [$request]);
			} finally {
				unset($request['reqest_type']);
				$this->expectException(UnvalidMntRequestError::class);
				$this->expectExceptionMessage(
					"Manticore request parse error: Mandatory field 'reqest_type' is missing"
				);
				try {
					self::invokeMethod(QueryProcessor::class, 'validateMntRequest', [$request]);
				} finally {
					$this->assertTrue(true);
				}
			}
		}
	}

	public function testManticoreQueryValidationOk(): void {
		$query = "Some valid\nManticore query\n\n"
			. '{"type":"some error","message":"some query","reqest_type":"sphinqxl",'
			. '"endpoint":"cli","listen":"127.0.0.1:9308"}';
		self::invokeMethod(QueryProcessor::class, 'validateMntQuery', [$query]);
		$this->assertTrue(true);
	}

	public function testManticoreQueryValidationFail(): void {
		echo "\nTesting the validation of an incorrect request query from Manticore\n";
		$query = '';
		$this->expectException(UnvalidMntRequestError::class);
		$this->expectExceptionMessage('Manticore request parse error: Query is missing');
		try {
			self::invokeMethod(QueryProcessor::class, 'validateMntQuery', [$query]);
		} finally {
			$query = "Unvalid query\nis passed\nagain";
			$this->expectException(UnvalidMntRequestError::class);
			$this->expectExceptionMessage(
				"Manticore request parse error: Request body is missing in query '{$query}'"
			);
			try {
				self::invokeMethod(QueryProcessor::class, 'validateMntQuery', [$query]);
			} finally {
				$query = 'Query\nwith unvalid\n\n{"request_body"}';
				$this->expectException(UnvalidMntRequestError::class);
				$this->expectExceptionMessage(
					"Manticore request parse error: Unvalid request body '{\"request_body\"}' is passed"
				);
				self::invokeMethod(QueryProcessor::class, 'validateMntQuery', [$query]);
			}
		}
	}

	public function testCommandProcessOk(): void {
		echo "\nTesting the processing of execution command\n";
		$command = 'BACKUP';
		$executor = QueryProcessor::processCommand($command);
		$this->assertInstanceOf(BackupExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(BackupRequest::class, $request);

		$command = 'ERROR QUERY';
		$executor = QueryProcessor::processCommand($command);
		$this->assertInstanceOf(ErrorQueryExecutor::class, $executor);
		$refCls = new ReflectionClass($executor);
		$request = $refCls->getProperty('request')->getValue($executor);
		$this->assertInstanceOf(ErrorQueryRequest::class, $request);
	}

	public function testCommandProcessFail(): void {
		echo "\nTesting the processing of incorrect execution command\n";
		$this->expectException(SQLQueryCommandNotSupported::class);
		$this->expectExceptionMessage("Command 'TEST' is not supported");
		QueryProcessor::processCommand('TEST');
	}

}
