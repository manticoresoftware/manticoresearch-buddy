<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Plugin\Backup\Payload as BackupPayload;
use PHPUnit\Framework\TestCase;

class BackupPayloadTest extends TestCase {
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
			'args' => [
				'path' => '/tmp',
				'options' => [
					'async' => 1,
				],
			],
			'checks' => [
				'tables' => [],
				'options' => [
					'async' => true,
				],
			],
		], [ #3
			'args' => [
				'path' => '/tmp',
				'options' => [
					'async' => 'off',
				],
			],
			'checks' => [
				'tables' => [],
				'options' => [
					'async' => false,
				],
			],
		], [ #4
			'args' => [
				'path' => '/tmp',
				'options' => [
					'compress' => 'on',
				],
			],
			'checks' => [
				'tables' => [],
				'options' => [
					'compress' => true,
				],
			],
		], [ #5
			'args' => [
				'path' => '/tmp',
				'options' => [
					'compress' => '0',
				],
			],
			'checks' => [
				'tables' => [],
				'options' => [
					'compress' => false,
				],
			],
		], [ #6
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
		], [ #7
			'args' => [
				'tables' => ['user'],
				'path' => '/tmp',
			],
			'checks' => [
				'options' => [],
			],
		], [ #8
			'args' => [
				'tables' => ['user', 'people'],
				'path' => '/tmp',
			],
			'checks' => [
				'options' => [],
			],
		], [ #9
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
			$payload = BackupPayload::fromRequest(
				Request::fromArray(static::buildSQLQuery($args))
			);
			$this->assertEquals(true, is_a($payload, BackupPayload::class));

			$checks = array_replace($args, $checks);
			foreach ($checks as $key => $val) {
				$this->assertEquals($val, $payload->{$key});
			}
		}
	}


	public function testSQLQueryParsingSucceedOnRightSyntax(): void {
		$testingSet = [
			'backup to /tmp',
			'backup table hahah to /tmp',
			'backup TO /tmp',
			'backup to /tmp options async=1',
			'backup to /tmp options async =1, compress=0',
			'backup to /tmp options ASYNC =1, COMPRESS=0',
			'backup to /tmp options async = off, compress= yes',
			'backup to /tmp options async = FALSE, compress= ON',
			'backup table a, b to /tmp option async = 1',
			'backup tables b to    /tmp',
			'backup tables a, b to /tmp/path/somehwere-dir_name',
			'backup table b   to /tmp/directory',
			'backup to \'/tmp/dir\'',
			'backup to \'/tmp/folder with spaces\'',
			'backup   to  C:\windows\Backup option async     = on',
			'backup to \'C:\path\'',
			'backup to \'C:\path with spaces\'',
		];

		foreach ($testingSet as $query) {
			try {
				BackupPayload::fromRequest(
					Request::fromArray(
						[
							'version' => 1,
							'error' => '',
							'payload' => $query,
							'format' => RequestFormat::SQL,
							'endpointBundle' => ManticoreEndpoint::Sql,
							'path' => '',
						]
					)
				);
			} catch (QueryParseError $e) {
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
			'backup table a to local(/tmp)',
			'backup tables a, b, c to FD:\f/tmp',
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
				BackupPayload::fromRequest(
					Request::fromArray(
						[
							'version' => 1,
							'error' => '',
							'payload' => $query,
							'format' => RequestFormat::SQL,
							'endpointBundle' => ManticoreEndpoint::Sql,
							'path' => '',
						]
					)
				);
			} catch (QueryParseError $e) {
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
   * @return array{
   *  version:int,
   *  error:string,
   *  payload:string,
   *  format:RequestFormat,
   *  endpointBundle:ManticoreEndpoint,
   *  path:string
   * }
   */
	protected static function buildSQLQuery(array $args): array {
		$query = 'BACKUP ';

		if (isset($args['tables'])) {
			$tables = implode(', ', $args['tables']);
			$query .= "TABLE $tables ";
		}

		if (isset($args['path'])) {
			$query .= "TO {$args['path']} ";
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
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => '',
		];
	}
}
