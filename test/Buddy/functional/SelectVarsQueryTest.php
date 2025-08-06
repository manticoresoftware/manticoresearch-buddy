<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class SelectVarsQueryTest extends TestCase {

	use TestFunctionalTrait;

	public function testSelectVarsQuery(): void {
		$this->assertQueryResult(
			'SELECT  @@session.auto_increment_increment AS auto_increment_increment, '
			. '@@character_set_connection, '
			. '@@character_set_results AS test_results, '
			. '@@collation_connection AS test_connection', [
				'auto_increment_increment',
				'1',
				'character_set_connection',
				'utf8',
				'test_results',
				'utf8mb4',
				'test_connection',
				'utf8mb4_0900_ai_ci',
			]
		);
	}

}
