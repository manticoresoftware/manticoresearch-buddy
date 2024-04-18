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

class MultipleQueriesTest extends TestCase
{

	use TestFunctionalTrait;

	/**
	 * @var string $testTable1
	 */
	protected string $testTable1;

	/**
	 * @var string $testTable2
	 */
	protected string $testTable2;

	public function setUp(): void {
		$this->testTable1 = 'test1';
		$this->testTable2 = 'test2';
		static::runSqlQuery("drop table if exists {$this->testTable1}", false);
		static::runSqlQuery("drop table if exists {$this->testTable2}", false);
	}

	public function testSqlMultipleBuddyQueriesOk(): void {
		echo "\nTesting the execution of multiple SQL queries with Buddy\n";
		$query = "INSERT into {$this->testTable1}(col1,col2) VALUES(1,2);"
			. "INSERT into {$this->testTable2}(col1,col2) VALUES(1,2)";
		$out = static::runSqlQuery($query);
		$result = [];
		$this->assertEquals($result, $out);
		$selectResult = [
			'+------+------+',
			'| col1 | col2 |',
			'+------+------+',
			'|    1 |    2 |',
			'+------+------+',
		];
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable1}");
		$this->assertEquals($selectResult, $out);
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable2}");
		$this->assertEquals($selectResult, $out);

		$selectResult = [
			'+------+------+',
			'| col1 | col2 |',
			'+------+------+',
			'|    1 |    2 |',
			'|    1 |    2 |',
			'+------+------+',
		];
		$query = "INSERT into {$this->testTable2}(col1,col2) VALUES(1,2);SHOW QUERIES";
		$out = static::runSqlQuery($query);
		$first = '+------+--------------+----------+----------+-----------------+';
		$second = '| id   | query        | time     | protocol | host            |';
		$this->assertEquals($first, $out[0]);
		$this->assertEquals($second, $out[1]);
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable2}");
		$this->assertEquals($selectResult, $out);
	}

	public function testSqlMultipleBuddyQueriesWithHungOk(): void {
		// TODO: fix the problem with the Request-Id header of multiple queries
		// to avoid the incorrect handling of hunq requests
		echo "\nTesting the execution of multiple SQL queries with Buddy when some of them hung\n";
		$query = 'TEST 3;'
			. "INSERT into {$this->testTable2}(col1,col2) VALUES(1,2)";
		$out = static::runSqlQuery($query);
		$this->assertCount(0, $out);
		$selectResult = [
			'+------+------+',
			'| col1 | col2 |',
			'+------+------+',
			'|    1 |    2 |',
			'+------+------+',
		];
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable2}");
		$this->assertEquals($selectResult, $out);

		$query = 'TEST 3/deferred;'
			. "INSERT into {$this->testTable1}(col1,col2) VALUES(1,2)";
		$out = static::runSqlQuery($query);
		$this->assertCount(5, $out);
		$selectResult = [
			'+------+------+',
			'| col1 | col2 |',
			'+------+------+',
			'|    1 |    2 |',
			'+------+------+',
		];
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable1}");
		$this->assertEquals($selectResult, $out);
	}

	public function testSqlMultipleBuddyQueriesFail(): void {
		echo "\nTesting the fail on the execution of multiple SQL queries with Buddy\n";
		$query = "INSERT into {$this->testTable1}(col1,col2) VALUES(1);"
			. "INSERT into {$this->testTable2}(col1,col2) VALUES(1)";
		$out = static::runSqlQuery($query);
		$result = [
			"ERROR 1064 (42000) at line 1: P01: wrong number of values here near ')'",
		];
		$this->assertEquals($result, $out);
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable1}");
		$result = "ERROR 1064 (42000) at line 1: unknown local table(s) 'test1' in search request";
		$this->assertEquals($result, $out[0]);
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable2}");
		$result = "ERROR 1064 (42000) at line 1: unknown local table(s) 'test2' in search request";
		$this->assertEquals($result, $out[0]);

		$query = "INSERT into {$this->testTable1}(col1,col2) VALUES(1,2);"
			. "INSERT into {$this->testTable1}(col1,col2) VALUES(1)";
		$out = static::runSqlQuery($query);
		$result = ["ERROR 1064 (42000) at line 1: P01: wrong number of values here near ')'"];
		$this->assertEquals($result, $out);
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable1}");
		$selectResult = [
			'+------+------+',
			'| col1 | col2 |',
			'+------+------+',
			'|    1 |    2 |',
			'+------+------+',
		];
		$this->assertEquals($selectResult, $out);

		$query = "INSERT into {$this->testTable2}(col1,col2) VALUES(1,2);SHOW QUERIES 123";
		$out = static::runSqlQuery($query);
		$result = 'ERROR 1064 (42000) at line 1: P01: syntax error, unexpected identifier, '
			. "expecting VARIABLES near 'QUERIES 123'";
		$this->assertEquals($result, $out[0]);
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable2}");
		$selectResult = [
			'+------+------+',
			'| col1 | col2 |',
			'+------+------+',
			'|    1 |    2 |',
			'+------+------+',
		];
		$this->assertEquals($selectResult, $out);
	}

	public function testSqlMultipleQueriesOk(): void {
		echo "\nTesting the execution of multiple SQL queries with and without Buddy\n";
		$query = "INSERT into {$this->testTable1}(col1,col2) VALUES(1,2);"
			. "INSERT into {$this->testTable1}(col1,col2) VALUES(1,2);";
		$out = static::runSqlQuery($query);
		$result = [];
		$this->assertEquals($result, $out);
		$selectResult = [
			'+------+------+',
			'| col1 | col2 |',
			'+------+------+',
			'|    1 |    2 |',
			'|    1 |    2 |',
			'+------+------+',
		];
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable1}");
		$this->assertEquals($selectResult, $out);

		$query = "INSERT into {$this->testTable1}(col1,col2) VALUES(1,2);SHOW QUERIES";
		$out = static::runSqlQuery($query);
		$expectedFields = ['id', 'query', 'time', 'protocol', 'host'];
		$realFields = array_values(array_filter(array_map('trim', explode('|', $out[1]))));
		$this->assertEquals($expectedFields, $realFields);
		$selectResult = [
			'+------+------+',
			'| col1 | col2 |',
			'+------+------+',
			'|    1 |    2 |',
			'|    1 |    2 |',
			'|    1 |    2 |',
			'+------+------+',
		];
		$out = static::runSqlQuery("select col1,col2 from {$this->testTable1}");
		$this->assertEquals($selectResult, $out);
	}
}
