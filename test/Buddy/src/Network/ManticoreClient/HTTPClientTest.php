<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
//use Manticoresearch\Buddy\Exception\ManticoreHTTPClientError;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use Manticoresearch\Buddy\Network\ManticoreClient\Response;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class HTTPClientTest extends TestCase {

	use TestProtectedTrait;

	/**
	 * @var HTTPClient $client
	 */
	private $client;

	/**
	 * @var ReflectionClass<HTTPClient> $refCls
	 */
	private $refCls;

	protected function setUp(): void {
		$this->client = new HTTPClient();
		$this->refCls = new \ReflectionClass(HTTPClient::class);
	}

	public function testManticoreHTTPClientCreate(): void {
		$this->assertInstanceOf(HTTPClient::class, $this->client);
		$this->assertEquals(
			HTTPClient::DEFAULT_URL,
			$this->refCls->getProperty('url')->getValue($this->client)
		);
		$this->assertEquals(ManticoreEndpoint::Cli, $this->refCls->getProperty('endpoint')->getValue($this->client));

		$client = new HTTPClient(new Response(), 'localhost:1000', ManticoreEndpoint::Insert);
		$this->assertInstanceOf(HTTPClient::class, $client);
	}

	public function testResponseUrlSetOk(): void {
		$url = 'http://localhost:1000';
		$this->client->setServerUrl($url);
		$this->assertEquals($url, $this->refCls->getProperty('url')->getValue($this->client));
	}

	// public function testResponseUrlSetFail(): void {
	// 	$url = 'some_unvalid_url';
	// 	$this->expectException(ManticoreHTTPClientError::class);
	// 	$this->expectExceptionMessage("Manticore request error: Malformed request url '$url' passed");
	// 	$this->client->setServerUrl($url);
	// }

}
