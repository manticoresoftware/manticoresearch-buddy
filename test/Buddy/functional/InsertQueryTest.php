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

class InsertQueryTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var string $testTable
	 */
	protected string $testTable;

	public function setUp(): void {
		$this->testTable = 'test';
		$sqlPort = self::getListenSqlPort();
		exec("mysql -P$sqlPort -h127.0.0.1 -e 'drop table if exists {$this->testTable}' ");
	}

	public function testSqlInsertQueryOk(): void {
		echo "\nTesting the execution of SQL insert query to a non-existing table\n";
		if (!self::hasMySQL()) {
			echo "MySQL is not installed\n";
			$this->markTestSkipped();
		}
		$query = "INSERT into {$this->testTable}(col1,col2) VALUES(1,2) ";
		$sqlPort = self::getListenSqlPort();
		exec("mysql -P$sqlPort -h127.0.0.1 -e '$query' 2>&1", $out);
		$result = [];
		$this->assertEquals($result, $out);
	}

	public function testHTTPInsertQueryOk(): void {
		// Making sure curl is installed
		echo "\nTesting the execution of HTTP insert query to a non-existing table\n";
		if (!self::hasCurl()) {
			echo "Curl is not installed\n";
			$this->markTestSkipped();
		}
		$query = "INSERT into {$this->testTable}(col1,col2) VALUES(1,2) ";
		$httpPort = self::getListenHttpPort();
		exec("curl localhost:$httpPort/cli -d '$query' 2>&1", $out);
		$result = '[{"total":1,"error":"","warning":""}]';
		$this->assertEquals($result, $out[3]);
	}
}
