<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

final class AutocompleteTest extends TestCase {
	use TestFunctionalTrait;

	private const string TABLE = 'autocomplete_test';
	private const string NO_FUZZY_OPTIONS = '0 AS fuzziness, 1 AS append, 0 AS prepend';

	public function setUp(): void {
		static::runSqlQuery('CREATE TABLE ' . self::TABLE . "(name text) min_infix_len='2'");
		static::runSqlQuery(
			'INSERT INTO ' . self::TABLE . " (id, name) VALUES (1, 'linen shirt'), (2, 'linen shorts'), (3, 'red dress')"
		);
	}

	public function tearDown(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS ' . self::TABLE);
	}

	public function testAutocompleteWithoutFuzzinessKeepsPrecedingWords(): void {
		$this->assertQueryResult(
			"CALL AUTOCOMPLETE('linen sh', '" . self::TABLE . "', " . self::NO_FUZZY_OPTIONS . ')',
			['query: linen shirt', 'query: linen shorts'],
			['query: shirt', 'query: shorts']
		);
	}

	public function testAutocompleteWithoutFuzzinessCompletesSingleWord(): void {
		$this->assertQueryResult(
			"CALL AUTOCOMPLETE('sh', '" . self::TABLE . "', " . self::NO_FUZZY_OPTIONS . ')',
			['query: shirt', 'query: shorts'],
			['query: linen']
		);
	}
}
