<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

/**
 * To make sure that things works fine we use production ready binary
 * to run and directly send requests to for various functionality
 * to make it sure that all work fine.
 */
final class DirectRequestTest extends TestCase {
	use TestFunctionalTrait;

	public function testShowQueries(): void {
		$response = static::runHttpBuddyRequest('SHOW QUERIES');
		$this->assertBasicChecks($response);
		$this->assertDataChecks($response);
	}

	public function testBackupAll(): void {
		static::runSqlQuery('CREATE TABLE test (name text)');
		static::runSqlQuery('INSERT INTO test (name) values ("some data")');
		$response = static::runHttpBuddyRequest('BACKUP TO /tmp');
		$this->assertBasicChecks($response);
		$this->assertDataChecks($response);
		$this->assertEquals(true, isset($response['message'][0]['data'][0]['Path']));
		static::runSqlQuery('DROP TABLE IF EXISTS test');
	}

	public function testInsertQuery(): void {
		$table = 'test_' . uniqid();
		$response = static::runHttpBuddyRequest(
			"INSERT INTO $table (name, value) values ('Hello', 10), ('World', 100)",
			"table $table absent"
		);
		$this->assertBasicChecks($response);
		static::runSqlQuery("DROP TABLE IF EXISTS $table");
	}

	/**
	 * @param array<mixed> $response
	 * @return void
	 */
	protected function assertBasicChecks(array $response): void {
		$this->assertEquals(true, isset($response['type']));
		$this->assertEquals('json response', $response['type']);
		$this->assertEquals(true, isset($response['version']));
		$this->assertEquals(2, $response['version']);
		$this->assertIsArray($response['message']);

		$this->assertEquals(1, sizeof($response['message']));
		$this->assertEquals(true, isset($response['message'][0]['error']));
		$this->assertEquals('', $response['message'][0]['error']);
	}

	/**
	 * @param array{message?:array<int,array{columns?:array<mixed>,data?:array<mixed>}>} $response
	 * @return void
	 */
	protected function assertDataChecks(array $response): void {
		$this->assertEquals(true, isset($response['message'][0]['columns']));
		$this->assertEquals(true, isset($response['message'][0]['data']));
	}
}
