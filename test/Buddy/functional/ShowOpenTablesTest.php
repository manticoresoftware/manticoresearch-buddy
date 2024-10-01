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

class ShowOpenTablesTest extends TestCase {

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

	public function testShowOpenTablesWithNoArgsIsOk(): void {
		$this->assertQueryResult(
			'SHOW OPEN TABLES', [
				'Table: a',
				'Table: b',
				'Table: test123',
				'Table: hello',
			]
		);
	}

	public function testShowOpenTablesFiltersLikeInAProperWay(): void {
		$this->assertQueryResult(
			"SHOW OPEN TABLES LIKE 'a'", [
				'Table: a',
			], [
				'Table: b',
				'Table: test123',
				'Table: hello',
			]
		);

		$this->assertQueryResult(
			"SHOW OPEN TABLES LIKE 'doesnotexist'", [

			], [
				'Table: a',
				'Table: b',
				'Table: test123',
				'Table: hello',
			]
		);
	}
}
