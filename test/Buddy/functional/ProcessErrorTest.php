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

final class ProcessErrorTest extends TestCase {

	use TestFunctionalTrait;

	public function testCorrectErrorOnFailedToParseRequest(): void {
		$this->assertQueryResultContainsError(
			'tratatata',
			"P02: syntax error, unexpected identifier near 'tratatata'"
		);
		$this->assertQueryResultContainsError(
			'hello how are you?',
			"P02: syntax error, unexpected identifier near 'hello how are you?'"
		);
		$this->assertQueryResultContainsError(
			'showf tables',
			"P02: syntax error, unexpected identifier near 'showf tables'"
		);
	}

	public function testCorrectErrorOnBackupNoTables(): void {
		$this->assertQueryResultContainsError(
			'backup to /tmp',
			'You have no tables to backup.'
		);
	}
}
