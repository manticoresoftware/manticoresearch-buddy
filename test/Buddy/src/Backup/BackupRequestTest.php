<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Backup\Request as BackupRequest;
use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\SQLQueryParsingError;
use Manticoresearch\Buddy\Network\Request;
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
			$request = BackupRequest::fromNetworkRequest(
				Request::fromArray(static::buildSQLQuery($args))
			);
			$this->assertEquals(true, is_a($request, BackupRequest::class));

			$checks = array_replace($args, $checks);
			foreach ($checks as $key => $val) {
				$this->assertEquals($val, $request->{$key});
			}
		}
	}


	public function testSQLQueryParsingSucceedOnRightSyntax(): void {
		$testingSet = [
			'backup all',
			'BacKup ALL',
			'backup',
			'backup all to local(/tmp)',
			'backup all TO LOCAL(/tmp)',
			'backup all options async=1',
			'backup all options async =1, compress=0',
			'backup all options ASYNC =1, COMPRESS=0',
			'backup all options async = off, compress= yes',
			'backup all options async = FALSE, compress= ON',
			'backup table a, b to local(/tmp) option async = 1',
			'backup tables b to local(  /tmp)',
			'backup tables a, b',
			'backup table b',
			'backup all to local(  /path )',
			'backup    all to   local(/tmp   ) option async     = on',
		];

		foreach ($testingSet as $query) {
			try {
				BackupRequest::fromNetworkRequest(
					Request::fromArray(
						[
							'version' => 1,
							'error' => '',
							'payload' => $query,
							'format' => RequestFormat::SQL,
							'endpoint' => ManticoreEndpoint::Sql,
						]
					)
				);
			} catch (SQLQueryParsingError $e) {
				$this->assertEquals(true, false, "Correct syntax parse failed: $query");
			}
			$this->assertEquals(true, true);
		}
	}

	public function testSQLQueryParsingFailedOnWrongSyntax(): void {
		$testingSet = [
			'backup all to /tmp',
			'backup all local(/tmp)',
			'backup table %$ to local(/tmp)',
			'backup table a to /tmp',
			'backup tables a, b, c to /tmp',
			'backup ttable a to local(/tmp)',
			'backup table a to local(/tmp) options ha=1',
			'backup table a to local(/tmp) options async=10',
			'backup to local(/tmp) option async != 1',
			'backup all to local(/tmp) option async = 1, compress=h',
			'backup all option async = 1, compress=h to local(/tmp)',
			'backup to local(/tmp)',
		];

		foreach ($testingSet as $query) {
			try {
				BackupRequest::fromNetworkRequest(
					Request::fromArray(
						[
							'version' => 1,
							'error' => '',
							'payload' => $query,
							'format' => RequestFormat::SQL,
							'endpoint' => ManticoreEndpoint::Sql,
						]
					)
				);
			} catch (SQLQueryParsingError $e) {
				$this->assertEquals(
					'You have an error in your query. Please, double check it.',
					$e->getResponseError()
				);
				continue;
			}

			$this->assertEquals(true, false, "Failure test succeeded: $query");
		}
	}

  /**
   * This is helper to build query from input array
   * to reduce the code we write for tests
   * ! each assigment to query should end with space
   *
   * @param array{path?:string,tables?:string[],options?:array{async?:bool,compress?:bool}} $args
   * @return array{version:int,error:string,payload:string,format:RequestFormat,endpoint:ManticoreEndpoint}
   */
	protected static function buildSQLQuery(array $args): array {
		$query = 'BACKUP ';

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
		return [
			'version' => 1,
			'error' => '',
			'payload' => $query,
			'format' => RequestFormat::SQL,
			'endpoint' => ManticoreEndpoint::Sql,
		];
	}
}
