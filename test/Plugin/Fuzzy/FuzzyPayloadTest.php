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
				'SELECT * FROM a WHERE MATCH(\'q\') OPTION fuzzy=1', ['a']
			],
			'two tables with space'     => [
				'SELECT * FROM a, b WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b']
			],
			'two tables no space'       => [
				'SELECT * FROM a,b WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b']
			],
			'two tables extra space'    => [
				'SELECT * FROM a , b WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b']
			],
			'three tables'              => [
				'SELECT * FROM a, b, c WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b', 'c']
			],
			'four tables'               => [
				'SELECT * FROM a,b,c,d WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b', 'c', 'd']
			],
			'backtick-quoted tables'    => [
				'SELECT * FROM `a`, `b` WHERE MATCH(\'q\') OPTION fuzzy=1', ['a', 'b']
			],
			'no FROM clause'            => [
				'SHOW TABLES', []
			],
			'subquery without keyword'  => [
				'SELECT * FROM (SELECT 1)', []
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
}
