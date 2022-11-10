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
// @codingStandardsIgnoreStart
use Manticoresearch\Buddy\Interface\ErrorQueryRequestInterface;
// @codingStandardsIgnoreEnd
use Manticoresearch\Buddy\Lib\ErrorQueryRequest;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class ErrorQueryRequestTest extends TestCase {

	use TestProtectedTrait;

	/**
	 * @var ErrorQueryRequestInterface $request
	 */
	private $request;

	/**
	 * @var ReflectionClass<ErrorQueryRequestInterface> $refCls
	 */
	private $refCls;

	protected function setUp(): void {
		$request = Request::fromArray(
			[
				'origMsg' => '',
				'query' => 'INSERT INTO test(col1) VALUES(1)',
				'format' => RequestFormat::SQL,
				'endpoint' => MntEndpoint::Cli,
			]
		);
		$this->request = ErrorQueryRequest::fromNetworkRequest($request);
		$this->refCls = new ReflectionClass($this->request);
	}

	public function testCreationFromNetworkRequest(): void {
		echo "\nTesting the creation of ErrorQueryRequest from manticore request data struct\n";
		$this->assertInstanceOf(ErrorQueryRequest::class, $this->request);
	}

	public function testErrorTypeDetection(): void {
		echo "\nTesting the detection of request error type\n";
		$this->refCls->getProperty('origMsg')->setValue($this->request, 'test');
		$this->assertEquals('', self::invokeMethod($this->request, 'detectErrorType'));

		$this->refCls->getProperty('origMsg')->setValue($this->request, 'index test is absent');
		$this->assertEquals('NO_INDEX', self::invokeMethod($this->request, 'detectErrorType'));

		$this->refCls->getProperty('origMsg')->setValue($this->request, 'unexpected identifier found in query');
		$this->assertEquals('UNKNOWN_COMMAND', self::invokeMethod($this->request, 'detectErrorType'));
	}

	public function testQueryTypeDetection(): void {
		echo "\nTesting the detection of request query type\n";
		$this->refCls->getProperty('query')->setValue($this->request, 'SELECT * FROM test');
		$this->assertEquals('', self::invokeMethod($this->request, 'detectQueryType'));

		$this->refCls->getProperty('query')->setValue($this->request, 'INSERT INTO test(col) VALUES (1)');
		$this->assertEquals('INSERT_QUERY', self::invokeMethod($this->request, 'detectQueryType'));

		$this->refCls->getProperty('query')->setValue($this->request, 'INSERT INTO test(col) values (2)');
		$this->assertEquals('INSERT_QUERY', self::invokeMethod($this->request, 'detectQueryType'));

		$this->refCls->getProperty('query')->setValue($this->request, 'SHOW QUERIES');
		$this->assertEquals('SHOW_QUERIES_QUERY', self::invokeMethod($this->request, 'detectQueryType'));

		$this->refCls->getProperty('query')->setValue($this->request, 'show queries');
		$this->assertEquals('SHOW_QUERIES_QUERY', self::invokeMethod($this->request, 'detectQueryType'));
	}

	public function testHandleActions(): void {
		echo "\nTesting the choice of action depending on request error type and query type\n";

		$this->refCls->getProperty('origMsg')->setValue($this->request, 'test');
		$this->refCls->getProperty('query')->setValue($this->request, 'SELECT * FROM test');
		$this->assertEquals([], self::invokeMethod($this->request, 'getHandleActions'));

		$this->refCls->getProperty('origMsg')->setValue($this->request, 'index test is absent');
		$this->refCls->getProperty('query')->setValue($this->request, 'INSERT INTO test(col) VALUES (1)');
		$actionRes = [Action::CreateIndex, Action::Insert];
		$this->assertEquals($actionRes, self::invokeMethod($this->request, 'getHandleActions'));

		$this->refCls->getProperty('origMsg')->setValue($this->request, 'unexpected identifier found in query');
		$this->refCls->getProperty('query')->setValue($this->request, 'SHOW QUERIES');
		$actionRes = [Action::SelectSystemSessions];
		$this->assertEquals($actionRes, self::invokeMethod($this->request, 'getHandleActions'));
	}

	public function testBuildCreateStatement(): void {
		echo "\nTesting the build of Create statement from previously parsed data\n";
		$parseData = [
			'name' => 'test',
			'cols' => ['col1', 'col2'],
			'colTypes' => ['int', 'text'],
		];
		$res = 'CREATE TABLE IF NOT EXISTS test (col1 int,col2 text)';
		$this->assertEquals($res, self::invokeMethod($this->request, 'buildCreateStmt', $parseData));
	}

	public function testGetStatementProcessor(): void {
		echo "\nTesting the getting of a postrpocess callback for statement\n";
		$this->assertNull(self::invokeMethod($this->request, 'getStmtPostprocessor', [Action::CreateIndex]));
		$this->assertIsCallable(
			self::invokeMethod($this->request, 'getStmtPostprocessor', [Action::SelectSystemSessions])
		);
	}

}
