<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
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
		$expectedFields = ['id', 'query', 'time', 'protocol', 'host'];

		$realFields = array_values(array_filter(array_map('trim', explode('|', $out[1]))));
		$this->assertEquals($expectedFields, $realFields);
		$query = ' show queries ';
		$out = static::runSqlQuery($query);

		$realFields = array_values(array_filter(array_map('trim', explode('|', $out[1]))));
		$this->assertEquals($expectedFields, $realFields);
	}

	public function testSQLShowQueriesFail(): void {
		echo "\nTesting the fail on the execution of SQL SHOW QUERIES statement\n";
		$query = 'SHOW QUERIES 123';
		$out = static::runSqlQuery($query);
		$result = [
			'ERROR 1064 (42000) at line 1: P01: syntax error, unexpected identifier, '
			. "expecting VARIABLES near 'QUERIES 123'",
		];
		$this->assertEquals($result, $out);
	}

	public function testHTTPShowQueriesOk(): void {
		echo "\nTesting the execution of HTTP SHOW QUERIES statement\n";
		$query = 'SHOW QUERIES';
		$out = static::runHttpQuery($query);
		$resultColumns = [
			['id' => ['type' => 'long long']],
			['query' => ['type' => 'string']],
			['time' => ['type' => 'string']],
			['protocol' => ['type' => 'string']],
			['host' => ['type' => 'string']],
		];
		if (!(isset($out[0]['columns'], $out[0]['total']))) {
			$this->fail('Unexpected response from searchd');
		}
		$this->assertEquals('', $out[0]['error']);
		$this->assertGreaterThan(0, $out[0]['total']);
		$this->assertEquals($resultColumns, $out[0]['columns']);
	}

	public function testHTTPShowQueriesFail(): void {
		echo "\nTesting the fail on the execution of HTTP SHOW QUERIES statement\n";
		$query = 'SHOW QUERIES 123';
		$out = static::runHttpQuery($query);
		$result = [
			'error' => "P01: syntax error, unexpected identifier, expecting VARIABLES near 'QUERIES 123'",
		];
		$this->assertEquals($result, $out);
	}
}
