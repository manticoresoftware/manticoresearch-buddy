<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Insert\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use PHPUnit\Framework\TestCase;

class InsertQueryPayloadTest extends TestCase {
	public function testCreationFromNetworkRequest(): void {
		echo "\nTesting the creation of InsertQuery\Request from manticore request data struct\n";
		$request = Request::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'INSERT INTO test(int_col, string_col, float_col, @timestamp)'
					. ' VALUES(1, \'string\', 2.22, \'2000-01-01T12:00:00Z\')',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);
		$payload = Payload::fromRequest($request);
		$this->assertInstanceOf(Payload::class, $payload);

		echo "\nTesting the prepared quries after creating request are correct\n";

		$this->assertIsArray($payload->queries);
		$this->assertEquals(2, sizeof($payload->queries));
		$this->assertEquals(
			[
				'CREATE TABLE IF NOT EXISTS test (int_col int,string_col text,float_col float, @timestamp timestamp)',
				'INSERT INTO test(int_col, string_col, float_col) VALUES(1, \'string\', 2.22,'
					. '\'2000-01-01T12:00:00Z\')',
			], $payload->queries
		);
	}
}
