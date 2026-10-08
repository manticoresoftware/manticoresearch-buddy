<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\Fuzzy\Payload;
use PHPUnit\Framework\TestCase;

class FuzzyPayloadTest extends TestCase {

	/**
	 * @return array<string, array{string, array<string>}>
	 */
	public static function parseTableNamesProvider(): array {
		return [
			'single table'              => [
				'SELECT * FROM a WHERE MATCH(\'q\') OPTION fuzzy=1', ['a'],
			],
			'two tables with space'     => [
				'SELECT * FROM a, b WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b'],
			],
			'two tables no space'       => [
				'SELECT * FROM a,b WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b'],
			],
			'two tables extra space'    => [
				'SELECT * FROM a , b WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b'],
			],
			'three tables'              => [
				'SELECT * FROM a, b, c WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b', 'c'],
			],
			'four tables'               => [
				'SELECT * FROM a,b,c,d WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b', 'c', 'd'],
			],
			'backtick-quoted tables'    => [
				'SELECT * FROM `a`, `b` WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b'],
			],
			'no FROM clause'            => [
				'SHOW TABLES', [],
			],
			'subquery without keyword'  => [
				'SELECT * FROM (SELECT 1)', [],
			],
		];
	}

	/**
	 * @dataProvider parseTableNamesProvider
	 * @param string $query
	 * @param array<string> $expected
	 */
	public function testParseTableNames(string $query, array $expected): void {
		$this->assertSame($expected, Payload::parseTableNames($query));
	}

	public function testSqlFuzzyQueryWithEscapedApostrophe(): void {
		$payload = new Payload();
		$payload->payload = "SELECT * FROM catalog WHERE MATCH('peter\\'s') OPTION fuzzy=1";
		$payload->fuzzy = true;
		$payload->quorum = 0;
		$payload->queries = [];

		$processedQuery = '';
		$result = $payload->getQueriesSQLRequest(
			static function (string $query) use (&$processedQuery): array {
				$processedQuery = $query;
				return [[$query]];
			}
		);

		$this->assertSame("peter's", $processedQuery);
		$this->assertSame(
			"SELECT * FROM catalog WHERE MATCH('(peter\\'s^50)')",
			trim($result)
		);
	}

	public function testSqlFuzzyQueryWithEscapedApostropheAndQuorum(): void {
		$payload = new Payload();
		$payload->payload = "SELECT * FROM catalog WHERE MATCH('peter\\'s') OPTION fuzzy=1, quorum=0.5";
		$payload->fuzzy = true;
		$payload->quorum = 0.5;
		$payload->queries = [];

		$result = $payload->getQueriesSQLRequest(
			static fn(string $query): array => [[$query]]
		);

		$this->assertSame(
			"SELECT * FROM catalog WHERE MATCH('(peter\\'s^50) | \"peter\\'s\"/0.5')",
			trim($result)
		);
	}

	public function testSqlFuzzyQueryWithEscapedApostropheFallback(): void {
		$payload = new Payload();
		$payload->payload = "SELECT * FROM catalog WHERE MATCH('peter\\'s') OPTION fuzzy=1";
		$payload->fuzzy = true;
		$payload->quorum = 0;
		$payload->queries = [];

		$result = $payload->getQueriesSQLRequest(
			static fn(): array => []
		);

		$this->assertSame(
			"SELECT * FROM catalog WHERE MATCH('peter\\'s')",
			trim($result)
		);
	}

	public function testSqlNonFuzzyQueryPreservesEscapedApostrophe(): void {
		$payload = new Payload();
		$payload->payload = "SELECT * FROM catalog WHERE MATCH('peter\\'s') OPTION fuzzy=0";
		$payload->fuzzy = false;
		$payload->quorum = 0;
		$payload->queries = [];

		$called = false;
		$result = $payload->getQueriesSQLRequest(
			static function (string $query) use (&$called): array {
				$called = true;
				return [[$query]];
			}
		);

		$this->assertFalse($called);
		$this->assertSame(
			"SELECT * FROM catalog WHERE MATCH('peter\\'s')",
			trim($result)
		);
	}

}
