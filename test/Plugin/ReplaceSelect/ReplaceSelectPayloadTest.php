<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Config;
use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

class ReplaceSelectPayloadTest extends TestCase {

	public function testHasMatch(): void {
		echo "\nTesting REPLACE SELECT payload matching\n";

		// Test valid REPLACE SELECT patterns
		$validRequests = [
			'REPLACE INTO target SELECT id, name FROM source',
			'REPLACE INTO target SELECT * FROM source WHERE active = 1',
			'REPLACE INTO cluster1:target SELECT id, name, price FROM cluster2:source',
			'REPLACE INTO target SELECT id, name FROM source /* BATCH_SIZE 500 */',
		];

		foreach ($validRequests as $sql) {
			$request = Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'payload' => $sql,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
				'error' => '',
				]
			);

			$this->assertTrue(Payload::hasMatch($request), "Should match: $sql");
		}

		// Test invalid patterns
		$invalidRequests = [
			'SELECT * FROM source',
			"REPLACE INTO target VALUES (1, 'test')",
			'INSERT INTO target SELECT * FROM source',
			"UPDATE target SET name = 'test' WHERE id = 1",
		];

		foreach ($invalidRequests as $sql) {
			$request = Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'payload' => $sql,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
				'error' => '',
				]
			);

			$this->assertFalse(Payload::hasMatch($request), "Should not match: $sql");
		}
	}

	public function testFromRequestBasic(): void {
		echo "\nTesting basic REPLACE SELECT payload creation\n";

		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INTO target SELECT id, name FROM source',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload = Payload::fromRequest($request);
		$this->assertInstanceOf(Payload::class, $payload);
		$this->assertEquals('target', $payload->targetTable);
		$this->assertEquals('SELECT id, name FROM source', $payload->selectQuery);
		$this->assertEquals(Config::getBatchSize(), $payload->batchSize);
		$this->assertNull($payload->cluster);
	}

	public function testFromRequestWithCluster(): void {
		echo "\nTesting REPLACE SELECT with cluster syntax\n";

		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INTO cluster1:target SELECT * FROM cluster2:source',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload = Payload::fromRequest($request);
		$this->assertEquals('target', $payload->targetTable);
		$this->assertEquals('cluster1', $payload->cluster);
		$this->assertEquals('SELECT * FROM cluster2:source', $payload->selectQuery);
	}

	public function testFromRequestWithBatchSize(): void {
		echo "\nTesting REPLACE SELECT with batch size comment\n";

		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INTO target SELECT * FROM source /* BATCH_SIZE 500 */',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload = Payload::fromRequest($request);
		$this->assertEquals('target', $payload->targetTable);
		$this->assertEquals(500, $payload->batchSize);
		$this->assertEquals('SELECT * FROM source', $payload->selectQuery);
	}

	public function testGetTargetTableWithCluster(): void {
		echo "\nTesting target table name with cluster\n";

		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INTO cluster1:target SELECT * FROM source',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload = Payload::fromRequest($request);
		$this->assertEquals('`cluster1`:target', $payload->getTargetTableWithCluster());

		// Test without cluster
		$request2 = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INTO target SELECT * FROM source',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload2 = Payload::fromRequest($request2);
		$this->assertEquals('target', $payload2->getTargetTableWithCluster());
	}

	public function testValidation(): void {
		echo "\nTesting payload validation\n";

		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INTO target SELECT id, name FROM source',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload = Payload::fromRequest($request);
		$payload->validate(); // Should not throw

		// Test with invalid batch size
		$payload->batchSize = 0;
		$this->expectException(\Manticoresearch\Buddy\Core\Error\GenericError::class);
		$payload->validate();
	}

	public function testInvalidQuery(): void {
		echo "\nTesting invalid query handling\n";

		$this->expectException(\Manticoresearch\Buddy\Core\Error\GenericError::class);

		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INVALID SYNTAX',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		Payload::fromRequest($request);
	}
}
