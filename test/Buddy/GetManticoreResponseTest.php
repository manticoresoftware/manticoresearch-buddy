<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\BuddyTest\Lib\MockManticoreServer;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class GetManticoreResponseTest extends TestCase {

	/**
	 * @var HTTPClient $httpClient
	 */
	private $httpClient;

	use TestHTTPServerTrait;
	use TestProtectedTrait;

	/**
	 * @param bool $isInErrorMode
	 */
	protected function setUpServer(bool $isInErrorMode): void {
		$serverUrl = self::setUpMockManticoreServer($isInErrorMode);
		$this->httpClient = new HTTPClient(new Response(), $serverUrl);
	}

	protected function tearDown(): void {
		self::finishMockManticoreServer();
	}

	public function testOkResponsesToSQLRequest(): void {
		echo "\nTesting Manticore success response to SQL request\n";
		$this->setUpServer(false);

		$query = 'CREATE TABLE IF NOT EXISTS test(col1 text)';
		$mntResp = new Response(MockManticoreServer::CREATE_RESPONSE_OK);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'INSERT INTO test(col1) VALUES("test")';
		$mntResp = new Response(MockManticoreServer::SQL_INSERT_RESPONSE_OK);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'SELECT * FROM @@system.sessions';
		$mntResp = new Response(MockManticoreServer::SHOW_QUERIES_RESPONSE_OK);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));
	}

	public function testFailResponsesToSQLRequest(): void {
		echo "\nTesting Manticore fail response to SQL request\n";
		$this->setUpServer(true);

		$query = 'CREATE TABLE IF NOT EXISTS testcol1 text';
		$mntResp = new Response(MockManticoreServer::CREATE_RESPONSE_FAIL);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'INSERT INTO test(col1) VALUES("test")';
		$mntResp = new Response(MockManticoreServer::SQL_INSERT_RESPONSE_FAIL);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query));

		$query = 'SELECT connid AS ID FROM @@system.sessions';
		$this->expectException(ManticoreSearchClientError::class);
		$this->expectExceptionMessage('No response passed from server');
		$this->httpClient->sendRequest($query);
	}

	public function testOkResponsesToJSONRequest(): void {
		echo "\nTesting Manticore success response to JSON request\n";
		$this->setUpServer(false);
		$query = '{"index":"test","id":1,"doc":{"col1" : 1}}';
		$mntResp = new Response(MockManticoreServer::JSON_INSERT_RESPONSE_OK);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query, ManticoreEndpoint::Insert->value));
	}

	public function testFailResponsesToJSONRequest(): void {
		echo "\nTesting Manticore fail response to JSON request\n";
		$this->setUpServer(true);
		$query = '{"index":"test","id":1,"doc":{"col1" : 1}}';
		$mntResp = new Response(MockManticoreServer::JSON_INSERT_RESPONSE_FAIL);
		$this->assertEquals($mntResp, $this->httpClient->sendRequest($query, ManticoreEndpoint::Insert->value));
	}
}
