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
		$result = ["ERROR 1064 (42000) at line 1: sphinxql: wrong number of values here near ')'"];
		$this->assertEquals($result, $out);
	}

	public function testHTTPInsertQueryOk(): void {
		// Making sure curl is installed
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
		$result = [['total' => 0,'error' => "sphinxql: wrong number of values here near ') '",'warning' => '']];
		$this->assertEquals($result, $out);
	}
}
