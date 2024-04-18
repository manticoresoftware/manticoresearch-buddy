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

class CliTableTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var string $testTable
	 */
	protected string $testTable;

	public function testCliQueryOk(): void {
		echo "\nTesting the execution of queries with the 'cli' endpoint\n";
		// Creating a test table for the following tests
		$query = 'CREATE TABLE test(f string)';
		static::runHTTPQuery($query, true);

		$query = 'SHOW TABLES';
		$out = static::runHTTPQuery($query, true, 'cli');
		if (isset($out[0]['columns'])) {
			$result = preg_match(
				"/\+-+\+-+\+\n"
				. "\| Index\s+\| Type\s+\|\n"
				. "\+-+\+-+\+\n"
				. "\| test\s+\| rt\s+\|\n"
				. "\+-+\+-+\+\n"
				. "1 row in set \(\d\.\d{3} sec\)\n/s",
				$out[0]['columns']
			);
			$this->assertEquals(1, $result);
		}

		$query = 'SELECT * FROM test';
		$out = static::runHTTPQuery($query, true, 'cli');
		if (isset($out[0]['columns'])) {
			$result = preg_match("/Empty set \(\d\.\d{3} sec\)\n/s", $out[0]['columns']);
			$this->assertEquals(1, $result);
		}

		$query = "INSERT INTO test(f) VALUES('value 1')";
		$out = static::runHTTPQuery($query, true, 'cli');
		if (isset($out[0]['columns'])) {
			$result = preg_match('/Query OK, 1 row affected \(\d\.\d{3} sec\)/', $out[0]['columns']);
			$this->assertEquals(1, $result);
		}
		$multiLineValue = 'value 2'
			. '\n'
			. 'value 3';
		$query = "INSERT INTO test(f) VALUES('$multiLineValue')";
		$out = static::runHTTPQuery($query, true, 'cli');

		$query = 'SELECT * FROM test';
		$out = static::runHTTPQuery($query, true, 'cli');
		if (isset($out[0]['columns'])) {
			$result = preg_match(
				"/\+-+\+-+\+\n"
				. "\| id\s+\| f\s+\|\n"
				. "\+-+\+-+\+\n"
				. "\| \d+ \| value 2 \|\n"
				. "\|\s+\| value 3 \|\n"
				. "\| \d+ \| value 1 \|\n"
				. "\+-+\+-+\+\n"
				. "2 rows in set \(\d\.\d{3} sec\)\n/s",
				$out[0]['columns']
			);
			$this->assertEquals(1, $result);
		}

		$query = 'DROP TABLE test';
		$out = static::runHTTPQuery($query, true, 'cli');
		if (isset($out[0]['columns'])) {
			$result = preg_match("/Query OK, 0 rows affected \(\d\.\d{3} sec\)\n/s", $out[0]['columns']);
			$this->assertEquals(1, $result);
		}

		$query = 'SHOW QUERIES';
		$out = static::runHTTPQuery($query, true, 'cli');
		if (!isset($out[0]['columns'])) {
			return;
		}

		$pattern = "/\+-+\+-+\+-+\+-+\+-+\+\n".
			"\| id\s+\| query\s+\| time\s+\| protocol \| host\s+\|\n".
			"\+-+\+-+\+-+\+-+\+-+\+\n".
			"\| \d+ \| select\s+\| \d+us ago\s+\| http\s+\| 127\.0\.0\.1:\d+ \|\n".
			"\| [a-z0-9.]+ \| SHOW QUERIES \| \d+ms ago\s+\| http\s+\| 127\.0\.0\.1:\d+ \|\n".
			"\+-+\+-+\+-+\+-+\+-+\+\n".
			"2 rows in set \(\d\.\d{3} sec\)\n/";
		$this->assertMatchesRegularExpression($pattern, $out[0]['columns']);
	}
}
