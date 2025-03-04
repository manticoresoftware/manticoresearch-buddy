<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\Show\Payload as ShowFullOrOpenTablesPayload;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

class ShowFullOrOpenTablesPayloadTest extends TestCase {
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
		ShowFullOrOpenTablesPayload::$type = 'expanded tables';
		foreach (static::PARSING_SETS as ['args' => $args, 'checks' => $checks]) {
			foreach (['FULL', 'OPEN'] as $queryType) {
				$payload = ShowFullOrOpenTablesPayload::fromRequest(
					Request::fromArray(static::buildSQLQuery($args, $queryType))
				);
				$this->assertEquals(true, is_a($payload, ShowFullOrOpenTablesPayload::class));

				$checks = array_replace($args, $checks);
				foreach ($checks as $key => $val) {
					$this->assertEquals($val, $payload->{$key});
				}
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
			'show open tables',
			"show open tables like   'Hello%'",
		];

		foreach ($testingSet as $query) {
			try {
				ShowFullOrOpenTablesPayload::fromRequest(
					Request::fromArray(
						[
							'version' => Buddy::PROTOCOL_VERSION,
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
				ShowFullOrOpenTablesPayload::fromRequest(
					Request::fromArray(
						[
							'version' => Buddy::PROTOCOL_VERSION,
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
   * @param string $queryType
   * @return array{
   *  version:int,
   *  error:string,
   *  payload:string,
   *  format:RequestFormat,
   *  endpointBundle:ManticoreEndpoint,
   *  path:string
   * }
   */
	protected static function buildSQLQuery(array $args, string $queryType): array {
		$query = "SHOW {$queryType} TABLES";

		if (isset($args['database'])) {
			$query .= " FROM `{$args['database']}`";
		}

		if (isset($args['like'])) {
			$query .= " LIKE '{$args['like']}'";
		}
		echo $query . PHP_EOL;
		return [
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => '',
			'payload' => $query,
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => '',
		];
	}
}
