<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class ShowQueriesTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var string $testTable
	 */
	protected string $testTable;

	public function testSQLShowQueriesOk(): void {
		echo "\nTesting the execution of SQL SHOW QUERIES statement\n";
		$query = 'SHOW QUERIES';
		$out = static::runSqlQuery($query);
		$result = 'id	query	protocol	host';
		$this->assertEquals($result, $out[0]);
		$query = ' show queries ';
		$out = static::runSqlQuery($query);
		$this->assertEquals($result, $out[0]);
	}

	public function testSQLShowQueriesFail(): void {
		echo "\nTesting the fail on the execution of SQL SHOW QUERIES statement\n";
		$query = 'SHOW QUERIES 123';
		$out = static::runSqlQuery($query);
		$result = [
			'ERROR 1064 (42000) at line 1: sphinxql: syntax error, unexpected identifier, '
			. "expecting VARIABLES near 'QUERIES 123'",
		];
		$this->assertEquals($result, $out);
	}

	public function testHTTPShowQueriesOk(): void {
		echo "\nTesting the execution of HTTP SHOW QUEIRES statement\n";
		$query = 'SHOW QUERIES';
		$out = static::runHttpQuery($query);
		$resultColumns = [
			['id' => ['type' => 'long long']],
			['query' => ['type' => 'string']],
			['protocol' => ['type' => 'string']],
			['host' => ['type' => 'string']],
		];
		$result = ['total' => 2, 'error' => ''];
		if (!(isset($out[0]['columns'], $out[0]['total']))) {
			$this->fail('Unexpected response from searchd');
		}
		$this->assertEquals(
			$result,
			[
				'total' => $out[0]['total'],
				'error' => $out[0]['error'],
			]
		);
		$this->assertEquals($resultColumns, $out[0]['columns']);
	}

	public function testHTTPShowQueriesFail(): void {
		echo "\nTesting the fail on the execution of HTTP SHOW QUERIES statement\n";
		$query = 'SHOW QUERIES 123';
		$out = static::runHttpQuery($query);
		$result = [
			[
				'total' => 0,
				'error' => "sphinxql: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES 123'",
				'warning' => '',
			],
		];
		$this->assertEquals($result, $out);
	}
}
