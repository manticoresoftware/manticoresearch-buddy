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
				'Index: a',
				'Index: b',
				'Index: test123',
				'Index: hello',
			]
		);
	}

	public function testShowFullTablesFiltersLikeInAProperWay(): void {
		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE 'a'", [
				'Index: a',
			], [
				'Index: b',
				'Index: test123',
				'Index: hello',
			]
		);

		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE '%'", [
				'Index: a',
				'Index: b',
				'Index: test123',
				'Index: hello',
			]
		);

		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE 't%'", [
				'Index: test123',
			], [
				'Index: a',
				'Index: b',
				'Index: hello',
			]
		);

		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE '_'", [
				'Index: a',
				'Index: b',
			], [
				'Index: test123',
				'Index: hello',
			]
		);

		$this->assertQueryResult(
			"SHOW FULL TABLES LIKE 'doesnotexist'", [

			], [
				'Index: a',
				'Index: b',
				'Index: test123',
				'Index: hello',
			]
		);
	}

}
