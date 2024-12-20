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

class BenchLoadTest extends TestCase {
	// [manticore query, buddy query, degrade factor]
	const TEST_QUERIES = [
		'TEST 0',
		'SHOW FULL TABLES',
		'SHOW QUERIES',
		'INSERT INTO test (name) VALUES (\'value\'); DROP TABLE test;',
	];

	// How many iterations we do to detect avg time for query (per single thread)
	const ITERATIONS = 1000;

	use TestFunctionalTrait {
		setUpBeforeClass as traitSetUpBeforeClass;
		tearDownAfterClass as traitTearDownAfterClass;
	}

	public static function setUpBeforeClass(): void {
		static::$configFileName = 'manticore-bench.conf';

		static::traitSetUpBeforeClass();
	}

	public static function tearDownAfterClass(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test');

		static::traitTearDownAfterClass();
	}

	public function testSequentialLoadIsOk(): void {
		echo "Testing sequential load\n\n";
		$this->runTestsWithThreads(1, 1);
	}

	public function testParallelLoadIsOk(): void {
		echo "Testing parallel load\n\n";
		$this->runTestsWithThreads(2, 2);
	}

	public function testParallelLoadWithConcurrencyIsOk(): void {
		echo "Testing parallel with concurrency load\n\n";
		$this->runTestsWithThreads(2, 3);
	}

	/**
	 * Run tests with required threads for all queries we have
	 * @param int $threads
	 * @param int $concurrency
	 * @return void
	 */
	public function runTestsWithThreads(int $threads, int $concurrency): void {
		foreach (static::TEST_QUERIES as $query) {
			echo "Testing query: $query\n";
			$output = static::bench($query, static::ITERATIONS, $threads, $concurrency);
			$this->assertEquals(
				false,
				str_contains($output, 'apr_socket_recv: Connection refused (111)'),
				'Connection to the buddy was refused'
			);
			$this->assertStringContainsString('Failed requests:        0', $output);
		}
	}

	/**
	 * Run iterations with same query and return output of ab
	 * @param string $query
	 * @param int $iterations
	 * @param int $threads
	 * @param int $concurrency
	 * @return string
	 */
	protected static function bench(string $query, int $iterations, int $threads = 1, int $concurrency = 1): string {
		$iterations = $threads * $iterations;
		$port = 8308; //static::$listenBuddyPort;

		$request = [
			'type' => 'unknown json request',
			'error' => '',
			'version' => Buddy::PROTOCOL_VERSION,
			'message' => [
				'path_query' => '/cli_json',
				'body' => $query,
			],
		];
		$payloadFile = \sys_get_temp_dir() . '/payload-' . uniqid() . '.json';
		file_put_contents($payloadFile, json_encode($request));
		$cmd = "ab -l -p $payloadFile -T application/json -c $concurrency -n $iterations http://127.0.0.1:$port/ 2>&1";
		echo $cmd . PHP_EOL;
		return shell_exec($cmd) ?: '';
	}
}
