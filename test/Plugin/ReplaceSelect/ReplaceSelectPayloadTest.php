<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Payload;
use Manticoresearch\Buddy\Core\Error\GenericError;
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
	}

	public function testHasMatchWithMatchClause(): void {
		echo "\nTesting REPLACE SELECT payload matching with MATCH() clauses\n";

		$validRequests = [
			'REPLACE INTO target SELECT id, title FROM source WHERE MATCH(title, \'@keyword\')',
			'REPLACE INTO target SELECT * FROM source WHERE MATCH(\'title, description\', \'@search\')',
			'REPLACE INTO cluster1:target SELECT id FROM source WHERE MATCH(title, \'@query\')',
			'REPLACE INTO target SELECT id, name, price FROM source WHERE MATCH(name, \'@product\') AND price > 100',
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

			$this->assertTrue(Payload::hasMatch($request), "Should match MATCH() query: $sql");
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
		$this->assertEquals('SELECT * FROM source', $payload->selectQuery);
	}

	public function testFromRequestWithMatchClause(): void {
		echo "\nTesting REPLACE SELECT with MATCH() clause parsing\n";

		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INTO target SELECT id, title FROM source WHERE MATCH(title, \'@keyword\')',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload = Payload::fromRequest($request);
		$this->assertEquals('target', $payload->targetTable);
		$this->assertEquals('SELECT id, title FROM source WHERE MATCH(title, \'@keyword\')', $payload->selectQuery);
		$this->assertNull($payload->cluster);
	}

	public function testFromRequestWithMatchAndLimit(): void {
		echo "\nTesting REPLACE SELECT with MATCH() and LIMIT/OFFSET parsing\n";

		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'payload' => 'REPLACE INTO target SELECT id, title FROM source WHERE MATCH(title, \'@keyword\') LIMIT 100 OFFSET 50',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'error' => '',
			]
		);

		$payload = Payload::fromRequest($request);
		$this->assertEquals('target', $payload->targetTable);
		$this->assertEquals('SELECT id, title FROM source WHERE MATCH(title, \'@keyword\') LIMIT 100 OFFSET 50', $payload->selectQuery);
		$this->assertEquals(100, $payload->selectLimit);
		$this->assertEquals(50, $payload->selectOffset);
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

		// Test with invalid batch size (too large)
		$originalBatchSize = $_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] ?? null;
		$originalMaxBatchSize = $_ENV['BUDDY_REPLACE_SELECT_MAX_BATCH_SIZE'] ?? null;

		// Set max batch size to a small value to test validation
		$_ENV['BUDDY_REPLACE_SELECT_MAX_BATCH_SIZE'] = 100;
		$_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] = 200; // Exceeds max, but will be clamped to 100

		// Create new payload to pick up new environment values
		$invalidPayload = Payload::fromRequest($request);

		// The Config::getBatchSize() uses max(1, min(batchSize, maxBatchSize))
		// So 200 gets clamped to 100, which is valid. We need to test a different scenario.

		// Test with negative batch size (will be clamped to 1, which is valid)
		$_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] = -10;
		$negativePayload = Payload::fromRequest($request);
		$negativePayload->validate(); // Should not throw because -10 gets clamped to 1

		// Test with zero max batch size (edge case)
		$_ENV['BUDDY_REPLACE_SELECT_MAX_BATCH_SIZE'] = 0;
		$zeroMaxPayload = Payload::fromRequest($request);

		$this->expectException(GenericError::class);
		$zeroMaxPayload->validate();

		// Restore original environment
		if ($originalBatchSize !== null) {
			$_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] = $originalBatchSize;
		} else {
			unset($_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE']);
		}

		if ($originalMaxBatchSize !== null) {
			$_ENV['BUDDY_REPLACE_SELECT_MAX_BATCH_SIZE'] = $originalMaxBatchSize;
		} else {
			unset($_ENV['BUDDY_REPLACE_SELECT_MAX_BATCH_SIZE']);
		}
	}

	public function testInvalidQuery(): void {
		echo "\nTesting invalid query handling\n";

		$this->expectException(GenericError::class);

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
