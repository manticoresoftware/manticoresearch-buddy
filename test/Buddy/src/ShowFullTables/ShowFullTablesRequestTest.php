<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\SQLQueryParsingError;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\Buddy\ShowFullTables\Request as ShowFullTablesRequest;
use PHPUnit\Framework\TestCase;

class ShowFullTablesRequestTest extends TestCase {
	const PARSING_SETS = [
		[ #1
			'args' => [
				'like' => '%',
			],
			'checks' => [
				'database' => 'Manticore',
			],
		], [ #2
			'args' => [
				'like' => 'hello%',
			],
			'checks' => [
				'database' => 'Manticore',
			],
		], [ #3
			'args' => [
				'database' => 'Database',
				'like' => 'test_%',
			],
			'checks' => [],
		], [ #3
			'args' => [
				'database' => 'Hello',
			],
			'checks' => [
				'like' => '',
			],
		], [ #4
			'args' => [
				'like' => 'table',
			],
			'checks' => [
				'database' => 'Manticore',
			],
		],
	];

	public function testSQLQueryParsing(): void {
		echo 'Testing queries:' . PHP_EOL;
		foreach (static::PARSING_SETS as ['args' => $args, 'checks' => $checks]) {
			$request = ShowFullTablesRequest::fromNetworkRequest(
				Request::fromArray(static::buildSQLQuery($args))
			);
			$this->assertEquals(true, is_a($request, ShowFullTablesRequest::class));

			$checks = array_replace($args, $checks);
			foreach ($checks as $key => $val) {
				$this->assertEquals($val, $request->{$key});
			}
		}
	}


	public function testSQLQueryParsingSucceedOnRightSyntax(): void {
		$testingSet = [
			'SHOW full Tables',
			'show full tables',
			'show full tables from manticore',
			'show full tables from Database',
			"show full tables like   'Hello%'",
			"show full tables from `Database`  like   'Hello%'",
		];

		foreach ($testingSet as $query) {
			try {
				ShowFullTablesRequest::fromNetworkRequest(
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
				var_dump($e->getMessage());
				$this->assertEquals(true, false, "Correct syntax parse failed: $query");
			}
			$this->assertEquals(true, true);
		}
	}

	public function testSQLQueryParsingFailedOnWrongSyntax(): void {
		$testingSet = [
			'show full tables haha',
			'show full tables from incorrect-table',
			'show full tables with trailing space ',
			'show full tables    ',
			'show full tables like',
			"show full tables like 'ffff",
			'show full tables from like %',
			'show full tables from hello like _',
		];

		foreach ($testingSet as $query) {
			try {
				ShowFullTablesRequest::fromNetworkRequest(
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
					'You have an error in your query. Please, double-check it.',
					$e->getResponseError()
				);
				continue;
			}

			$this->assertEquals(true, false, "Failure test succeeded: $query");
		}
	}

  /**
   * @param array{database?:string,like?:string} $args
   * @return array{version:int,error:string,payload:string,format:RequestFormat,endpoint:ManticoreEndpoint}
   */
	protected static function buildSQLQuery(array $args): array {
		$query = 'SHOW FULL TABLES';

		if (isset($args['database'])) {
			$query .= " FROM `{$args['database']}`";
		}

		if (isset($args['like'])) {
			$query .= " LIKE '{$args['like']}'";
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
