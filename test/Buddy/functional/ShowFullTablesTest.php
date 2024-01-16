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

class ShowFullTablesTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var string $testTable
	 */
	protected string $testTable;

	public function setUp(): void {
		static::runSqlQuery('create table a');
		static::runSqlQuery('create table b');
		static::runSqlQuery('create table test123');
		static::runSqlQuery('create table hello');
	}

	public function tearDown(): void {
		static::runSqlQuery('drop table if exists a');
		static::runSqlQuery('drop table if exists b');
		static::runSqlQuery('drop table if exists test123');
		static::runSqlQuery('drop table if exists hello');
	}

	public function testShowFullTablesWithNoArgsIsOk(): void {
		$this->assertQueryResult(
			'SHOW FULL TABLES', [
				'Tables_in_Manticore: a',
				'Tables_in_Manticore: b',
				'Tables_in_Manticore: test123',
				'Tables_in_Manticore: hello',
			]
		);
	}

	public function testShowFullTablesFiltersLikeInAProperWay(): void {
		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE 'a'", [
				'Tables_in_Manticore: a',
			], [
				'Tables_in_Manticore: b',
				'Tables_in_Manticore: test123',
				'Tables_in_Manticore: hello',
			]
		);

		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE '%'", [
				'Tables_in_Manticore: a',
				'Tables_in_Manticore: b',
				'Tables_in_Manticore: test123',
				'Tables_in_Manticore: hello',
			]
		);

		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE 't%'", [
				'Tables_in_Manticore: test123',
			], [
				'Tables_in_Manticore: a',
				'Tables_in_Manticore: b',
				'Tables_in_Manticore: hello',
			]
		);

		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE '_'", [
				'Tables_in_Manticore: a',
				'Tables_in_Manticore: b',
			], [
				'Tables_in_Manticore: test123',
				'Tables_in_Manticore: hello',
			]
		);

		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE 'doesnotexist'", [

			], [
				'Tables_in_Manticore: a',
				'Tables_in_Manticore: b',
				'Tables_in_Manticore: test123',
				'Tables_in_Manticore: hello',
			]
		);
	}

}
