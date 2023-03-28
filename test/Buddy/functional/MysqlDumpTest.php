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

class MysqlDumpTest extends TestCase {

	const TESTS = [
		[
			'query' => '--all-databases --no-data',
			'file' => 'all-databases-no-data.sql',
			'code' => 0,
		],
		[
			'query' => '--no-data Manticore a b',
			'file' => 'no-data-only-a-b.sql',
			'code' => 0,
		],
		[
			'query' => '--no-data Manticore c',
			'file' => 'no-data-only-c.sql',
			'code' => 0,
		],
		[
			'query' => '--no-data Manticore nosuchtable',
			'file' => 'no-data-only-nosuchtable.sql',
			'code' => 6,
		],
		[
			'query' => '--all-databases',
			'file' => 'all-databases.sql',
			'code' => 0,
		],
		[
			'query' => '--all-databases --quick',
			'file' => 'all-databases-quick.sql',
			'code' => 0,
		],
		[
			'query' => 'Manticore a b',
			'file' => 'only-a-b.sql',
			'code' => 0,
		],
		[
			'query' => 'Manticore c',
			'file' => 'only-c.sql',
			'code' => 0,
		],
		[
			'query' => 'Manticore nosuchtable',
			'file' => 'only-nosuchtable.sql',
			'code' => 6,
		],
	];

	use TestFunctionalTrait;

	public function testMysqldumpWorksFine(): void {
		echo 'Testing mysql dump works fine' . PHP_EOL;

		static::dropTables();
		// Prepare simple tables and little data to test the backup
		static::createTables();

		$queries = file(__DIR__ . '/mysqldump/data.sql') ?: [];
		foreach ($queries as $query) {
			static::runSqlQuery($query);
		}

		foreach (static::TESTS as [
			'query' => $query,
			'file' => $file,
			'code' => $exitCode,
		]) {
			echo "$query\n";
			[$code, $output] = static::mysqldump($query);
			$this->assertEquals($exitCode, $code);
			$this->assertEquals($output, trim(file_get_contents(__DIR__ . "/mysqldump/$file") ?: ''));
		}

		// Tear down
		static::dropTables();
	}

	public function testMysqlRestoreFromDump(): void {
		echo 'Testing restore from dump works fine' . PHP_EOL;

		$files = glob(__DIR__ . '/mysqldump/*') ?: [];
		foreach ($files as $file) {
			echo $file . PHP_EOL;
			// In case we just testing our raw data we need to prepare it
			if (basename($file) === 'data.sql') {
				static::dropTables();
				static::createTables();
			}
			exec("mysql -h0 -P8306 < '$file'", $output, $code);
			// We check exit code only just becaues it's enough
			// to validate restoration process
			$this->assertEquals(0, $code);
		}
	}

	public static function storeTestsValidation(): void {
		$queries = static::generateTableData();
		static::writeTestsData($queries);
		foreach ($queries as $query) {
			static::runSqlQuery($query);
		}
		static::writeTestsResults();
	}

	/**
	 * Helpe function to write input data for result set
	 * @param array<string> $data
	 * @return void
	 */
	public static function writeTestsData(array $data): void {
		file_put_contents(__DIR__ . '/mysqldump/data.sql', implode(';' . PHP_EOL, $data) . ';');
	}

  /**
   * Helper function to write output sets and persist it for testing
   * @return void
   */
	public static function writeTestsResults(): void {
		foreach (static::TESTS as ['query' => $query, 'file' => $file]) {
			[, $output] = static::mysqldump($query);
			file_put_contents(__DIR__ . "/mysqldump/$file", $output);
		}
	}

  /**
   * Execute mysql dump and return exit code and output
   * @param string $query
   * @return array{0:int,1:string}
   *  It returns exit code as first element of the list
   *  and output as string that represents results of execution
   */
	protected static function mysqldump(string $query): array {
		exec(
			'set -o pipefail; '
			. 'mysqldump -h0 -P8306 --where "id > 0 ORDER BY id ASC" ' . $query
			. ' | head -n-2 | tac | head -n-6 | tac',
			$output,
			$code
		);
		return [$code, trim(implode(PHP_EOL, $output))];
	}

	/**
	 * @return array<string>
	 */
	protected static function generateTableData(): array {
		$data = [];
		// Manticore handle select from empty table in incorrect way
		// And seems like does not return columns when nothing in table
		// to temporarely bypass it we insert some data into empty table
		$data[] = 'INSERT INTO a (id) VALUES (1)';
		for ($i = 0; $i < 20; ++$i) {
			// ID cannot be 0 or it wil be autogenerated
			$id = $i + 1;
			$data[] = sprintf(
				"INSERT INTO b (id, v1, v2, v3) VALUES (%d, '%s', %d, '%s')",
				$id,
				uniqid(),
				$i * random_int(0, 1000),
				json_encode(['key' => random_int(0, 1000), 'value' => uniqid()]),
			);

			$data[] = sprintf(
				"INSERT INTO c (id, v1, v2, v3) VALUES (%d, '%s', %d, '%s')",
				$id,
				uniqid(),
				$i * random_int(0, 1000),
				json_encode(['key' => random_int(0, 1000), 'value' => uniqid()]),
			);
		}

		return $data;
	}

	/**
	 * Helper to drop testing tables if they exist
	 * @return void
	 */
	protected static function dropTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS a');
		static::runSqlQuery('DROP TABLE IF EXISTS b');
		static::runSqlQuery('DROP TABLE IF EXISTS c');
	}

	/**
	 * Helper to create required tables for testing
	 * @return void
	 */
	protected static function createTables(): void {
		static::runSqlQuery('CREATE TABLE `a`');
		static::runSqlQuery(
			'CREATE TABLE `b` (`id` bigint, `v1` text, v2 int, `v3` json engine=\'rowwise\') engine = \'columnar\''
		);
		static::runSqlQuery('CREATE TABLE `c` (`id` bigint, `v1` text, v2 int engine=\'columnar\', `v3` json)');
	}
}
