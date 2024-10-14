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

class AutoSchemaSupportTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var string $testTable
	 */
	protected string $testTable = 'test';

	protected function setUp(): void {
		static::runSqlQuery("DROP TABLE IF EXISTS {$this->testTable}");
	}

	protected function tearDown(): void {
		static::$manticoreConf = '';
	}

	/**
	 * Helper to setup auto schema
	 * @param int $value can be 0 or 1
	 * @return void
	 */
	protected function setUpAutoSchema(int $value): void {
		// Adding the auto schema option to manticore config
		$conf = str_replace(
			'searchd {' . PHP_EOL,
			'searchd {' . PHP_EOL . "    auto_schema = $value" . PHP_EOL,
			static::$manticoreConf
		);
		static::updateManticoreConf($conf);
		echo $conf . PHP_EOL;

		// Restart manticore
		static::tearDownAfterClass();
		sleep(5); // <- give 5 secs to protect from any kind of lags
		static::setUpBeforeClass();
	}

	public function testAutoSchemaOptionDisabled(): void {
		echo "\nTesting the fail on the execution of HTTP insert query with searchd auto_schema=0\n";
		$this->setUpAutoSchema(0);
		$query = "INSERT into {$this->testTable}(col1) VALUES(1) ";
		$out = static::runHttpQuery($query);
		$result = ['error' => "table 'test' absent, or does not support INSERT"];
		$this->assertEquals($result, $out);
	}

	public function testAutoSchemaOptionEnabled(): void {
		echo "\nTesting the fail on the execution of HTTP insert query with searchd auto_schema=1\n";
		$this->setUpAutoSchema(1);
		$query = "INSERT into {$this->testTable}(col1) VALUES(1) ";
		$out = static::runHttpQuery($query);
		$result = [['total' => 1, 'error' => '','warning' => '']];
		$this->assertEquals($result, $out);
	}

	public function testAutoSchemaOptionOmitted(): void {
		echo "\nTesting the fail on the execution of HTTP insert query without searchd auto_schema set\n";
		$query = "INSERT into {$this->testTable}(col1,col2) VALUES(1,2) ";
		$out = static::runHttpQuery($query);
		$result = [['total' => 1,'error' => '','warning' => '']];
		$this->assertEquals($result, $out);
	}
}
