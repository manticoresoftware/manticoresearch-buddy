<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
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

	public function testHTTPInsertQueryFail(): void {
		echo "\nTesting the fail on the execution of HTTP insert query to a non-existing table\n";
		$query = "INSERT into {$this->testTable}(col1) VALUES(1,2) ";
		$out = static::runHttpQuery($query);
		$result = [['total' => 0,'error' => "P01: wrong number of values here near ') '",'warning' => '']];
		$this->assertEquals($result, $out);
	}

	public function testHTTPElasticInsertQueryOk(): void {
		echo "\nTesting the execution of HTTP Elastic-like insert query to a non-existing table\n";
		$query = '{"col1": 1, "col2": 2}';
		$out = static::runHttpQuery($query, true, "{$this->testTable}/_create/1");
		$outData = $out[0]['data'][0];
		if (!isset($outData['_id'], $outData['_index'], $outData['result'])) {
			$this->fail();
		}
		$result = [1, $this->testTable, 'created'];
		$this->assertEquals($result, [$outData['_id'], $outData['_index'], $outData['result']]);

		$out = static::runHttpQuery($query, true, "{$this->testTable}/_doc");
		$outData = $out[0]['data'][0];
		if (!isset($outData['_index'], $outData['result'])) {
			$this->fail();
		}
		$result = [$this->testTable, 'created'];
		$this->assertEquals($result, [$outData['_index'], $outData['result']]);

		$out = static::runHttpQuery($query, true, "{$this->testTable}/_doc/2");
		$outData = $out[0]['data'][0];
		if (!isset($outData['_id'], $outData['_index'], $outData['result'])) {
			$this->fail();
		}
		$result = [2, $this->testTable, 'updated'];
		$this->assertEquals($result, [$outData['_id'], $outData['_index'], $outData['result']]);
	}

	public function testHTTPElasticInsertQueryFail(): void {
		echo "\nTesting the fail on the execution of HTTP Elastic-like insert query to a non-existing table\n";
		$query = '{"col1": 1, "col2": 2}';
		$out = static::runHttpQuery($query, true, "{$this->testTable}/_create");
		$outData = $out[0]['data'][0];
		if (!isset($outData['error'])) {
			$this->fail();
		}
		$result = [
			'type' => 'illegal_argument_exception',
			'reason' => "Rejecting mapping update to [{$this->testTable}] as the final mapping "
				. 'would have more than 1 type: [_doc, _create]',
		];
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
		/** @var array{items:array<int,array<string,array<string,mixed>>>} */
		$outData = $out[0]['data'][0];
		$this->assertEquals(2, sizeof($outData['items']));
		if (!isset(
			$outData['items'][0]['index']['_index'], $outData['items'][0]['index']['_id'],
			$outData['items'][0]['index']['result'], $outData['items'][1]['create']['_index'],
			$outData['items'][1]['create']['_id'], $outData['items'][1]['create']['result']
		)) {
			$this->fail();
		}
		$itemsData = $outData['items'][0]['index'];
		$result = ['0', $this->testTable, 'created'];
		$this->assertEquals($result, [$itemsData['_id'], $itemsData['_index'], $itemsData['result']]);
		$itemsData = $outData['items'][1]['create'];
		$result = ['3', $this->testTable, 'created'];
		$this->assertEquals($result, [$itemsData['_id'], $itemsData['_index'], $itemsData['result']]);
	}

	public function testHTTPElasticBulkInsertQueryFail(): void {
		echo "\nTesting the fail on the execution of HTTP Elastic-like bulk insert query to a non-existing table\n";
		$query = '{ "index" : { "_index" : "' . $this->testTable . '" } }'
			. "\n"
			. '{ "title" : "Yellow Bag", "price": 12 }'
			. "\n"
			. '{ "create" : { "_index" : "' . $this->testTable . '", "_id": "3"} }'
			. "\n"
			. '{ "title" : "Red Bag", "price": 12.5, "id": 3 }'
			. "\n";
		$out = static::runHttpQuery($query, true, '_bulk');
		/** @var array<int,array<string,mixed>> */
		$outData = $out[0]['data'][0];
		if (!isset($outData[0], $outData[0]['error'])) {
			$this->fail();
		}
		$this->assertEquals('id has already been specified', $outData[0]['error']);
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
		/** @var array{items:array<int,array<string,array<string,mixed>>>} */
		$outData = $out[0]['data'][0];
		$this->assertEquals(3, sizeof($outData['items']));
		$out = static::runSqlQuery("describe {$this->testTable}");
		$res = [
			'Field	Type	Properties',
			'id	bigint',
			'title	text	indexed stored',
			'price	uint',
			'new_price	float',
		];
		$this->assertEquals($res, $out);
	}
}
