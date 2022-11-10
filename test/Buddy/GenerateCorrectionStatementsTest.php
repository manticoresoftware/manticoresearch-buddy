<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\Action;
use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Enum\Statement;
use Manticoresearch\Buddy\Interface\QueryParserLoaderInterface;
use Manticoresearch\Buddy\Interface\StatementInterface;
use Manticoresearch\Buddy\Lib\BuddyLocator;
use Manticoresearch\Buddy\Lib\ErrorQueryRequest;
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
				'endpoint' => MntEndpoint::Cli,
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
		self::$refCls->getProperty('format')->setValue(self::$request, RequestFormat::JSON);
		self::$refCls->getProperty('queryFormat')->setValue(self::$request, RequestFormat::JSON);
		self::$refCls->getProperty('endpoint')->setValue(self::$request, MntEndpoint::Insert);
		self::$refCls->getProperty('query')->setValue(self::$request, $jsonQuery);
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
		self::$refCls->getProperty('origMsg')->setValue(
			self::$request, "sphinxql: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES'"
		);
		self::$refCls->getProperty('format')->setValue(self::$request, RequestFormat::SQL);
		self::$refCls->getProperty('queryFormat')->setValue(self::$request, RequestFormat::SQL);
		self::$refCls->getProperty('endpoint')->setValue(self::$request, MntEndpoint::Cli);
		self::$refCls->getProperty('query')->setValue(self::$request, 'SHOW QUERIES');
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

	/**
	 * @depends testHelpersLocation
	 */
	public function testBuildStatementWithParser(): void {
		echo "\nTesting the correction statement build\n";
		$stmt = 'CREATE TABLE IF NOT EXISTS test (col1 int)';
		$this->assertEquals(
			$stmt,
			self::invokeMethod(self::$request, 'buildStmtWithParser', [Statement::INSERT, Statement::CREATE])
		);
	}

	/**
	 * @depends testSetLocator
	 */
	public function testHelpersLocation(): void {
		echo "\nTesting the location of helper objects for 'errorRequest' instance\n";
		self::invokeMethod(self::$request, 'locateHelpers');
		$this->assertInstanceOf(
			QueryParserLoaderInterface::class,
			self::$refCls->getProperty('queryParserLoader')->getValue(self::$request)
		);
		$this->assertInstanceOf(
			StatementInterface::class,
			self::$refCls->getProperty('statementBuilder')->getValue(self::$request)
		);
	}

	public function testSetLocator(): void {
		echo "\nTesting the instantiating of Buddy locator\n";
		self::$request->setLocator(new BuddyLocator());
		$this->assertInstanceOf(
			BuddyLocator::class,
			self::$refCls->getProperty('locator')->getValue(self::$request)
		);
	}

}
