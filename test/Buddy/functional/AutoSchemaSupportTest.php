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

class AutoSchemaSupportTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var string $testTable
	 */
	protected string $testTable;

	/**
	 * @var int $checkAutoSchema
	 */
	protected int $checkAutoSchema = -1;

	protected function setUp(): void {
		$this->testTable = 'test';
		static::runSqlQuery("drop table if exists {$this->testTable}", true);
		if ($this->checkAutoSchema === -1) {
			// Shutting down searchd started with default config
			self::tearDownAfterClass();
			sleep(5); // <- give 5 secs to protect from any kind of lags
			// Adding the auto schema option to manticore config
			$conf = str_replace(
				'searchd {' . PHP_EOL,
				'searchd {' . PHP_EOL . '    auto_schema = 0' . PHP_EOL,
				self::$manticoreConf
			);
			self::updateManticoreConf($conf);
			$this->checkAutoSchema = 1;
			echo 'updated';
		}
		self::setUpBeforeClass();
	}

	protected function tearDown(): void {
		self::tearDownAfterClass();
		switch ($this->checkAutoSchema) {
			case 1:
				return;
			case 0:
				// Updating the auto schema option in manticore config
				$conf = str_replace(
					'    auto_schema = 0' . PHP_EOL,
					'    auto_schema = 1' . PHP_EOL,
					self::$manticoreConf
				);
				break;
			default:
				// Removing the auto schema option from manticore config
				$conf = str_replace('    auto_schema = 1' . PHP_EOL, '', self::$manticoreConf);
				break;
		}
		self::updateManticoreConf($conf);
	}

	public function testAutoSchemaOptionDisabled(): void {
		echo "\nTesting the fail on the execution of HTTP insert query with searchd auto_schema=0\n";
		$query = "INSERT into {$this->testTable}(col1) VALUES(1) ";
		$out = static::runHttpQuery($query);
		$result = [['total' => 0,'error' => "table 'test' absent, or does not support INSERT",'warning' => '']];
		$this->checkAutoSchema = 0;
		$this->assertEquals($result, $out);
	}

	public function testAutoSchemaOptionEnabled(): void {
		echo "\nTesting the fail on the execution of HTTP insert query with searchd auto_schema=1\n";
		$query = "INSERT into {$this->testTable}(col1) VALUES(1) ";
		$out = static::runHttpQuery($query);
		$result = [['total' => 1, 'error' => '','warning' => '']];
		$this->checkAutoSchema = -1;
		$this->assertEquals($result, $out);
	}

	public function testAutoSchemaOptionOmitted(): void {
		echo "\nTesting the fail on the execution of HTTP insert query without searchd auto_schema set\n";
		$query = "INSERT into {$this->testTable}(col1,col2) VALUES(1,2) ";
		$out = static::runHttpQuery($query);
		$result = [['total' => 0,'error' => "table 'test' absent, or does not support INSERT",'warning' => '']];
		$this->assertEquals($result, $out);
	}
}
