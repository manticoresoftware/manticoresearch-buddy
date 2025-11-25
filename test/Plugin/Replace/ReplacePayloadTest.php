<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\Replace\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;
use PHPUnit\Framework\TestCase;

class ReplacePayloadTest extends TestCase {

	public static function setUpBeforeClass(): void {
		Payload::setParser(SqlQueryParser::getInstance());
	}
	public function testCreationFromSQLRequest(): void {
		echo "\nTesting the creation of Replace Payload from SQL request\n";
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => "P01: syntax error, unexpected SET, expecting VALUES near 'SET col1 = 'value1'",
				'payload' => "REPLACE INTO test SET col1 = 'value1', col2 = 123 WHERE id = 1",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);

		$this->assertTrue(Payload::hasMatch($request));

		$payload = Payload::fromRequest($request);
		$this->assertInstanceOf(Payload::class, $payload);
		$this->assertEquals('test', $payload->table);
		$this->assertEquals(1, $payload->id);
		$this->assertIsArray($payload->set);
		$this->assertArrayHasKey('col1', $payload->set);
		$this->assertEquals("'value1'", $payload->set['col1']);
	}

	public function testCreationFromSQLRequestWithNegativeId(): void {
		echo "\nTesting the creation of Replace Payload from SQL request with negative ID\n";
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => "P01: syntax error, unexpected SET, expecting VALUES near 'SET col1 = 'value'",
				'payload' => "REPLACE INTO test SET col1 = 'value' WHERE id = -1",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);

		$this->assertTrue(Payload::hasMatch($request));

		$payload = Payload::fromRequest($request);
		$this->assertInstanceOf(Payload::class, $payload);
		$this->assertEquals('test', $payload->table);
		$this->assertEquals(-1, $payload->id);
		$this->assertLessThan(0, $payload->id);
	}

	public function testCreationFromElasticLikeRequest(): void {
		echo "\nTesting the creation of Replace Payload from Elastic-like request\n";
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => '{"doc":{"col1":"value1","col2":123}}',
				'format' => RequestFormat::JSON,
				'endpointBundle' => ManticoreEndpoint::Bulk,
				'path' => 'test/_update/1',
			]
		);
		$payload = Payload::fromRequest($request);
		$this->assertInstanceOf(Payload::class, $payload);
		$this->assertEquals('test', $payload->table);
		$this->assertEquals(1, $payload->id);
		$this->assertTrue($payload->isElasticLikePath);
		$this->assertIsArray($payload->set);
	}

	public function testClusterTableNameParsing(): void {
		echo "\nTesting cluster:table name parsing\n";
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => "P01: syntax error, unexpected SET, expecting VALUES near 'SET'",
				'payload' => "REPLACE INTO mycluster:test SET col1 = 'value' WHERE id = 1",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);

		$this->assertTrue(Payload::hasMatch($request));

		$payload = Payload::fromRequest($request);
		$this->assertEquals('test', $payload->table);
		$this->assertEquals('mycluster', $payload->cluster);
	}

	public function testHasMatch(): void {
		echo "\nTesting hasMatch for valid REPLACE query\n";
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => "P01: syntax error, unexpected SET, expecting VALUES near 'SET'",
				'payload' => "REPLACE INTO test SET col1 = 'value' WHERE id = 1",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);
		$this->assertTrue(Payload::hasMatch($request));
	}

	public function testHasMatchElasticLike(): void {
		echo "\nTesting hasMatch for Elastic-like update request\n";
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => '{"doc":{"col1":"value1"}}',
				'format' => RequestFormat::JSON,
				'endpointBundle' => ManticoreEndpoint::Bulk,
				'path' => 'test/_update/1',
			]
		);
		$this->assertTrue(Payload::hasMatch($request));
	}
}
