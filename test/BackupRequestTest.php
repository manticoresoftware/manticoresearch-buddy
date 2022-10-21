<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Lib\BackupRequest;
use PHPUnit\Framework\TestCase;

class BackupRequestTest extends TestCase {
	const PARSING_SETS = [
		[ #1
			'args' => [
				'path' => '/tmp',
			],
			'checks' => [
				'tables' => [],
				'options' => [],
			],
		], [ #2
			'args' => [],
			'checks' => [
				'tables' => [],
				'options' => [],
				'path' => '/var/lib/manticore',
			],
		], [ #3
			'args' => [
				'options' => [
					'async' => 1,
				],
			],
			'checks' => [
				'tables' => [],
				'options' => [
					'async' => true,
				],
				'path' => '/var/lib/manticore',
			],
		], [ #4
			'args' => [
				'options' => [
					'async' => 'off',
				],
			],
			'checks' => [
				'tables' => [],
				'options' => [
					'async' => false,
				],
				'path' => '/var/lib/manticore',
			],
		], [ #5
			'args' => [
				'options' => [
					'compress' => 'on',
				],
			],
			'checks' => [
				'tables' => [],
				'options' => [
					'compress' => true,
				],
				'path' => '/var/lib/manticore',
			],
		], [ #6
			'args' => [
				'options' => [
					'compress' => '0',
				],
			],
			'checks' => [
				'tables' => [],
				'options' => [
					'compress' => false,
				],
				'path' => '/var/lib/manticore',
			],
		], [ #7
			'args' => [
				'path' => '/tmp',
				'options' => [
					'compress' => '0',
					'async' => 'true',
				],
			],
			'checks' => [
				'tables' => [],
				'options' => [
					'compress' => false,
					'async' => true,
				],
			],
		], [ #8
			'args' => [
				'tables' => ['user'],
				'path' => '/tmp',
			],
			'checks' => [
				'options' => [],
			],
		], [ #9
			'args' => [
				'tables' => ['user', 'people'],
				'path' => '/tmp',
			],
			'checks' => [
				'options' => [],
			],
		], [ #10
			'args' => [
				'path' => '/tmp',
				'tables' => ['user', 'people'],
				'options' => [
					'async' => 1,
				],
			],
			'checks' => [
				'options' => [
					'async' => true,
				],
			],
		],
	];

	public function testSQLQueryParsing(): void {
		echo 'Testing queries:' . PHP_EOL;
		foreach (static::PARSING_SETS as ['args' => $args, 'checks' => $checks]) {
			$request = BackupRequest::fromQuery(
				static::buildSQLQuery($args)
			);
			$this->assertEquals(true, is_a($request, BackupRequest::class));

			$checks = array_replace($args, $checks);
			foreach ($checks as $key => $val) {
				  $this->assertEquals($val, $request->{$key});
			}
		}
	}

  /**
   * This is helper to build query from input array
   * to reduce the code we write for tests
   * ! each assigment to query should end with space
   *
   * @param array{path?:string,tables?:string[],options?:array{async?:bool,compress?:bool}} $args
   * @return string
   */
	protected static function buildSQLQuery(array $args): string {
		$query = '';

		if (isset($args['tables'])) {
			$tables = implode(', ', $args['tables']);
			$query .= "TABLE $tables ";
		} else {
			$query .= 'ALL ';
		}

		if (isset($args['path'])) {
			$query .= "TO local({$args['path']}) ";
		}

		if (isset($args['options'])) {
			$options = [];
			foreach ($args['options'] as $key => $val) {
				$options[] = "$key = $val";
			}
			$query .= $options ? 'OPTION ' . implode(', ', $options) : '';
		}
		echo $query . PHP_EOL;
		return $query;
	}
}
