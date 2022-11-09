<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Exception\MntHTTPClientError;
use Manticoresearch\Buddy\Lib\MntHTTPClient;
use Manticoresearch\Buddy\Lib\MntResponseBuilder;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class MntHTTPClientTest extends TestCase {

	use TestProtectedTrait;

	/**
	 * @var MntHTTPClient $client
	 */
	private $client;

	/**
	 * @var ReflectionClass<MntHTTPClient> $refCls
	 */
	private $refCls;

	protected function setUp(): void {
		$this->client = new MntHTTPClient();
		$this->refCls = new \ReflectionClass(MntHTTPClient::class);
	}

	public function testMntHTTPClientCreate(): void {
		$this->assertInstanceOf(MntHTTPClient::class, $this->client);
		$this->assertNull($this->refCls->getProperty('url')->getValue($this->client));
		$this->assertEquals(MntEndpoint::Cli, $this->refCls->getProperty('endpoint')->getValue($this->client));

		$client = new MntHTTPClient(new MntResponseBuilder(), 'localhost:1000', MntEndpoint::Insert);
		$this->assertInstanceOf(MntHTTPClient::class, $client);
	}

// 	public function testResponseBuilderSet(): void {
// 		$this->client->setResponseBuilder(new MntResponseBuilder());
// 		$respBuilder = $this->refCls->getProperty('responseBuilder')->getValue($this->client);
// 		$this->assertInstanceOf(MntResponseBuilder::class, $respBuilder);
// 	}

	public function testResponseUrlSetOk(): void {
		$url = 'http://localhost:1000';
		$this->client->setServerUrl($url);
		$this->assertEquals($url, $this->refCls->getProperty('url')->getValue($this->client));
	}

	public function testResponseUrlSetFail(): void {
		$url = 'some_unvalid_url';
		$this->expectException(MntHTTPClientError::class);
		$this->expectExceptionMessage("Manticore request error: Malformed request url '$url' passed");
		$this->client->setServerUrl($url);
	}

// 	public function testEndpointSet(): void {
// 		$this->client->setEndpoint(MntEndpoint::Insert);
// 		$this->assertEquals(MntEndpoint::Insert, $this->refCls->getProperty('endpoint')->getValue($this->client));
// 	}

}
