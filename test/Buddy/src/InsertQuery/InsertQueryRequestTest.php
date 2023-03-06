<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\InsertQuery\Request;
use Manticoresearch\Buddy\Network\Request as NetRequest;
use PHPUnit\Framework\TestCase;

class InsertQueryRequestTest extends TestCase {
	public function testCreationFromNetworkRequest(): void {
		echo "\nTesting the creation of InsertQuery\Request from manticore request data struct\n";
		$request = NetRequest::fromArray(
			[
				'version' => 1,
				'error' => '',
				'payload' => 'INSERT INTO test(int_col, string_col, float_col) VALUES(1, \'string\', 2.22)',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::CliJson,
				'path' => 'cli_json',
			]
		);
		$request = Request::fromNetworkRequest($request);
		$this->assertInstanceOf(Request::class, $request);

		echo "\nTesting the prepared quries after creating request are correct\n";

		$this->assertIsArray($request->queries);
		$this->assertEquals(2, sizeof($request->queries));
		$this->assertEquals(
			[
				'CREATE TABLE IF NOT EXISTS test (int_col int,string_col text,float_col float)',
				'INSERT INTO test(int_col, string_col, float_col) VALUES(1, \'string\', 2.22)',
			], $request->queries
		);
	}
}
