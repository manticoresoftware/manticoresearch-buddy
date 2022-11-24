<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\Action;
use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Enum\Statement;
use Manticoresearch\Buddy\Interface\StatementInterface;
use Manticoresearch\Buddy\Lib\ErrorQueryRequest;
use Manticoresearch\Buddy\Lib\ManticoreStatement;
use Manticoresearch\Buddy\Lib\QueryParserLoader;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class GenerateCorrectionStatementsTest extends TestCase {

	use TestProtectedTrait;

	/**
	 * @var ErrorQueryRequest $request
	 */
	private static ErrorQueryRequest $request;

	/**
	 * @var ReflectionClass<ErrorQueryRequest> $refCls
	 */
	private static ReflectionClass $refCls;

	public static function setUpBeforeClass(): void {
		$request = Request::fromArray(
			[
				'origMsg' => "index 'test' absent, or does not support INSERT",
				'query' => 'INSERT INTO test(col1) VALUES(1)',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		self::$request = ErrorQueryRequest::fromNetworkRequest($request);
		self::$refCls = new ReflectionClass(self::$request);
	}

	/**
	 * @param array<string> $resultStmts
	 */
	protected function generate(array $resultStmts): void {
		self::$request->generateCorrectionStatements();
		$stmtInstances = (array)self::$refCls->getProperty('correctionStmts')->getValue(self::$request);
		foreach ($stmtInstances as $i => $stmt) {
			if ($stmt instanceof StatementInterface) {
				$this->assertEquals($resultStmts[$i], $stmt->getBody());
			} else {
				$this->fail("Wrong type returned instead of 'StatementInterface'");
			}
		}
	}

	/**
	 * @depends testBuildStatementWithParser
	 */
	public function testGenerateCorrectionStatementsForInsertSQLQuery(): void {
		echo "\nTesting the correction statement generation for erroneous INSERT SQL query\n";
		$stmts = [
			'CREATE TABLE IF NOT EXISTS test (col1 int)',
			'INSERT INTO test(col1) VALUES(1)',
		];
		$this->generate($stmts);
	}

	/**
	 * @depends testBuildStatementData
	 */
	public function testGenerateCorrectionStatementsForInsertJSONQuery(): void {
		echo "\nTesting the correction statement generation for erroneous INSERT JSON query\n";
		$jsonQuery = '{"index":"test","id":1,"doc":{"col1" : 1}}';
		$request = Request::fromArray(
			[
				'origMsg' => "index 'test' absent, or does not support INSERT",
				'query' => $jsonQuery,
				'format' => RequestFormat::JSON,
				'endpoint' => ManticoreEndpoint::Insert,
			]
		);
		self::$refCls->getProperty('request')->setValue(self::$request, $request);
		$stmts = [
			'CREATE TABLE IF NOT EXISTS test (col1 int)',
			$jsonQuery,
		];
		$this->generate($stmts);
	}

	/**
	 * @depends testBuildStatementData
	 */
	public function testGenerateCorrectionStatementsForShowQuery(): void {
		echo "\nTesting the correction statement generation for erroneous SHOW statement\n";
		$request = Request::fromArray(
			[
				'origMsg' => "sphinxql: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES'",
				'query' => 'SHOW QUERIES',
				'format' => RequestFormat::SQL,
				'endpoint' => ManticoreEndpoint::Cli,
			]
		);
		self::$refCls->getProperty('request')->setValue(self::$request, $request);
		$stmts = ['SELECT * FROM @@system.sessions'];
		$this->generate($stmts);
	}

	/**
	 * @depends testBuildStatementWithParser
	 */
	public function testBuildStatementData(): void {
		echo "\nTesting the build of correction statement from the original erroneous query\n";

		echo "\nTesting the build from INSERT query\n";
		$query = "INSERT INTO test(col1,col2,col3) VALUES ('m1@google.com', 1, 111111111111)";
		$stmtData = [
			'stmtBody' => $query,
			'stmtPostprocessor' => null,
			'action' => Action::Insert,
		];
		self::$refCls->getProperty('query')->setValue(self::$request, $query);
		$this->assertEquals($stmtData, self::invokeMethod(self::$request, 'buildStmtDataByAction', [Action::Insert]));

		echo "\nTesting the build from SHOW QUERIES query\n";
		$query = 'SELECT * FROM @@system.sessions';
		$stmtData = [
			'stmtBody' => $query,
			'stmtPostprocessor' => [ErrorQueryRequest::class, 'getStmtPostprocessor'],
			'action' => Action::SelectSystemSessions,
		];
		$this->assertEquals(
			$stmtData, self::invokeMethod(self::$request, 'buildStmtDataByAction', [Action::SelectSystemSessions])
		);
	}

	public function testBuildStatementWithParser(): void {
		echo "\nTesting the correction statement build\n";
		self::$refCls->getProperty('queryParserLoader')->setValue(self::$request, new QueryParserLoader());
		self::$refCls->getProperty('statementBuilder')->setValue(self::$request, new ManticoreStatement());
		$stmt = 'CREATE TABLE IF NOT EXISTS test (col1 int)';
		$this->assertEquals(
			$stmt,
			self::invokeMethod(self::$request, 'buildStmtWithParser', [Statement::INSERT, Statement::CREATE])
		);
	}

}
