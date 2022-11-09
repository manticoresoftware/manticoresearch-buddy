<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Lib\MntHTTPClient;
use Manticoresearch\Buddy\Lib\MntResponse;
use Manticoresearch\Buddy\Lib\MntResponseBuilder;
use Manticoresearch\BuddyTest\Lib\MockManticoreServer;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class GetManticoreResponseTest extends TestCase {

	/**
	 * @var MntHTTPClient $httpClient
	 */
	private $httpClient;

	use TestHTTPServerTrait;
	use TestProtectedTrait;

	/**
	 * @param bool $isInErrorMode
	 */
	protected function setUpServer(bool $isInErrorMode): void {
		$serverUrl = self::setUpMockMntServer($isInErrorMode);
		$this->httpClient = new MntHTTPClient(new MntResponseBuilder(), $serverUrl);
	}

	protected function tearDown(): void {
		self::finishMockMntServer();
	}

	public function testOkResponsesToSQLRequest(): void {
		echo "\nTesting Manticore success response to SQL request\n";
		$this->setUpServer(false);

		$query = 'CREATE TABLE IF NOT EXISTS test(col1 text)';
		$mntResp = new MntResponse(MockManticoreServer::CREATE_RESPONSE_OK);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'INSERT INTO test(col1) VALUES("test")';
		$mntResp = new MntResponse(MockManticoreServer::SQL_INSERT_RESPONSE_OK);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'SELECT * FROM @@system.sessions';
		$mntResp = new MntResponse(MockManticoreServer::SHOW_QUERIES_RESPONSE_OK);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));
	}

	public function testFailResponsesToSQLRequest(): void {
		echo "\nTesting Manticore fail response to SQL request\n";
		$this->setUpServer(true);

		$query = 'CREATE TABLE IF NOT EXISTS testcol1 text';
		$mntResp = new MntResponse(MockManticoreServer::CREATE_RESPONSE_FAIL);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'INSERT INTO test(col1) VALUES("test")';
		$mntResp = new MntResponse(MockManticoreServer::SQL_INSERT_RESPONSE_FAIL);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'SELECT connid AS ID FROM @@system.sessions';
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Manticore request error: No response passed from server');
		$this->httpClient->sendRequest($query);
	}

	public function testOkResponsesToJSONRequest(): void {
		echo "\nTesting Manticore success response to JSON request\n";
		$this->setUpServer(false);
		$query = '{"index":"test","id":1,"doc":{"col1" : 1}}';
		$mntResp = new MntResponse(MockManticoreServer::JSON_INSERT_RESPONSE_OK);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query, MntEndpoint::Insert));
	}

	public function testFailResponsesToJSONRequest(): void {
		echo "\nTesting Manticore fail response to JSON request\n";
		$this->setUpServer(true);
		$query = '{"index":"test","id":1,"doc":{"col1" : 1}}';
		$mntResp = new MntResponse(MockManticoreServer::JSON_INSERT_RESPONSE_FAIL);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query, MntEndpoint::Insert));
	}
}
