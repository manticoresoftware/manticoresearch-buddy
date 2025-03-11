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

class InsertQueryTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var string $testTable
	 */
	protected string $testTable;

	public function setUp(): void {
		$this->testTable = 'test';
		static::runSqlQuery("drop table if exists {$this->testTable}", false);
	}

	public function testSqlInsertQueryOk(): void {
		echo "\nTesting the execution of SQL insert query to a non-existing table\n";
		$query = "INSERT into {$this->testTable}(col1,col2) VALUES(1,2) ";
		$out = static::runSqlQuery($query);
		$result = [];
		$this->assertEquals($result, $out);
	}

	public function testSqlInsertQueryFail(): void {
		echo "\nTesting the fail on the execution of SQL insert query to a non-existing table\n";
		$query = "INSERT into {$this->testTable}(col1) VALUES(1,2) ";
		$out = static::runSqlQuery($query);
		$result = ["ERROR 1064 (42000) at line 1: P01: wrong number of values here near ')'"];
		$this->assertEquals($result, $out);
	}

	public function testHTTPInsertQueryOk(): void {
		echo "\nTesting the execution of HTTP insert query to a non-existing table\n";
		$query = "INSERT into {$this->testTable}(col1,col2) VALUES(1,2) ";
		$out = static::runHttpQuery($query);
		$result = [['total' => 1,'error' => '','warning' => '']];
		$this->assertEquals($result, $out);
	}

	public function testHTTPInsertQueryWithUppercasedTableNameOk(): void {
		echo "\nTesting the execution of HTTP insert query with an uppercased table name to a non-existing table\n";
		$query = 'INSERT into ' . strtoupper($this->testTable) . '(col1,col2) VALUES(1,2) ';
		$out = static::runHttpQuery($query);
		$result = [['total' => 1,'error' => '','warning' => '']];
		$this->assertEquals($result, $out);
	}

	public function testHTTPInsertQueryFail(): void {
		echo "\nTesting the fail on the execution of HTTP insert query to a non-existing table\n";
		$query = "INSERT into {$this->testTable}(col1) VALUES(1,2) ";
		$out = static::runHttpQuery($query);
		$result = ['error' => "P01: wrong number of values here near ') '"];
		$this->assertEquals($result, $out);
	}

	public function testHTTPElasticInsertQueryOk(): void {
		echo "\nTesting the execution of HTTP Elastic-like insert query to a non-existing table\n";
		$query = '{"col1": 1, "col2": 2}';
		$out = static::runHttpQuery($query, true, "{$this->testTable}/_create/1");
		/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string}> $out */
		$this->assertArrayHasKey(0, $out);
		$outData = $out[0]['data'][0];
		if (!isset($outData['id'], $outData['table'], $outData['result'])) {
			$this->fail();
		}
		$result = [1, $this->testTable, 'created'];
		$this->assertEquals($result, [$outData['id'], $outData['table'], $outData['result']]);

		$out = static::runHttpQuery($query, true, "{$this->testTable}/_doc");
		/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string}> $out */
		$this->assertArrayHasKey(0, $out);
		$outData = $out[0]['data'][0];
		if (!isset($outData['table'], $outData['result'])) {
			$this->fail();
		}
		$result = [$this->testTable, 'created'];
		$this->assertEquals($result, [$outData['table'], $outData['result']]);

		$out = static::runHttpQuery($query, true, "{$this->testTable}/_doc/2");
		/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string}> $out */
		$this->assertArrayHasKey(0, $out);
		$outData = $out[0]['data'][0];
		if (!isset($outData['id'], $outData['table'], $outData['result'])) {
			$this->fail();
		}
		$result = [2, $this->testTable, 'created'];
		$this->assertEquals($result, [$outData['id'], $outData['table'], $outData['result']]);
	}

	public function testHTTPElasticInsertQueryFail(): void {
		echo "\nTesting the fail on the execution of HTTP Elastic-like insert query to a non-existing table\n";
		$query = '{"col1": 1, "col2": 2}';
		$out = static::runHttpQuery($query, true, "{$this->testTable}/_create");
		/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string}> $out */
		$this->assertArrayHasKey(0, $out);
		$outData = $out[0]['data'][0];
		if (!isset($outData['error'])) {
			$this->fail();
		}
		$result = '/test/_create - unsupported endpoint';
		$this->assertEquals($result, $outData['error']);
	}

	public function testHTTPElasticBulkInsertQueryOk(): void {
		echo "\nTesting the execution of HTTP Elastic-like bulk insert query to a non-existing table\n";
		$query = '{ "index" : { "_index" : "' . $this->testTable . '" } }'
			. "\n"
			. '{ "title" : "Yellow Bag", "price": 12 }'
			. "\n"
			. '{ "create" : { "_index" : "' . $this->testTable . '" } }'
			. "\n"
			. '{ "title" : "Red Bag", "price": 12.5, "id": 3 }'
			. "\n";
		$out = static::runHttpQuery($query, true, '_bulk');
		/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string}> $out */
		$this->assertArrayHasKey(0, $out);
		/** @var array{items:array<int,array<string,array<string,mixed>>>} */
		$outData = $out[0]['data'][0];
		$this->assertEquals(2, sizeof($outData['items']));
		if (!isset(
			$outData['items'][0]['index']['_index'], $outData['items'][0]['index']['id'],
			$outData['items'][0]['index']['result'], $outData['items'][1]['create']['_index'],
			$outData['items'][1]['create']['id'], $outData['items'][1]['create']['result']
		)) {
			$this->fail();
		}
		$itemsData = $outData['items'][0]['index'];
		$result = [$this->testTable, 'created'];
		$this->assertNotEquals('0', $itemsData['id']);
		$this->assertEquals($result, [$itemsData['_index'], $itemsData['result']]);
		$itemsData = $outData['items'][1]['create'];
		$result = [$this->testTable, 'created'];
		$this->assertEquals($result, [$itemsData['_index'], $itemsData['result']]);
	}

	public function testHTTPElasticBulkInsertQueryFail(): void {
		echo "\nTesting the fail on the execution of HTTP Elastic-like bulk insert query to a non-existing table\n";
		$query = '{ "index" : { "_index" : "' . $this->testTable . '" } }'
			. "\n"
			. '{ "title" : "Yellow Bag", "price": 12 }'
			. "\n"
			. '{ "create" : { "_index" : "' . $this->testTable . '", "_id": "2"} }'
			. "\n"
			. '{ "title" : "Red Bag", "price": 12.5, "id": 3 }'
			. "\n";
		$out = static::runHttpQuery($query, true, '_bulk');
		/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string}> $out */
		$this->assertArrayHasKey(0, $out);
		$outData = $out[0]['data'][0];
		if (!isset($outData['errors'])) {
			$this->fail();
		}

		$this->assertEquals(true, $outData['errors']);
	}

	public function testAutoColumnAddOnInsert(): void {
		echo "\nTesting the execution of HTTP Elastic-like bulk insert query to a non-existing table\n";
		$query = '{ "index" : { "_index" : "' . $this->testTable . '" } }'
			. "\n"
			. '{ "title" : "Yellow Bag", "price": 12 }'
			. "\n"
			. '{ "create" : { "_index" : "' . $this->testTable . '" } }'
			. "\n"
			. '{ "title" : "Red Bag"}'
			. "\n"
			. '{ "create" : { "_index" : "' . $this->testTable . '" } }'
			. "\n"
			. '{ "title" : "Green Bag", "new_price": 20.5 }'
			. "\n";
		$out = static::runHttpQuery($query, true, '_bulk');
		/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string}> $out */
		$this->assertArrayHasKey(0, $out);
		/** @var array{items:array<int,array<string,array<string,mixed>>>} */
		$outData = $out[0]['data'][0];
		$this->assertEquals(3, sizeof($outData['items']));
		$out = static::runSqlQuery("describe {$this->testTable}");
		$res = [
			'+-----------+--------+-------------------+',
			'| Field     | Type   | Properties        |',
			'+-----------+--------+-------------------+',
			'| id        | bigint |                   |',
			'| title     | string | indexed attribute |',
			'| price     | uint   |                   |',
			'| new_price | float  |                   |',
			'+-----------+--------+-------------------+',
		];
		$this->assertEquals($res, $out);
	}
}
