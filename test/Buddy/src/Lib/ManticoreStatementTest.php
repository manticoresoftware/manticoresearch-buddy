<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Lib\ManticoreStatement;
use PHPUnit\Framework\TestCase;

class ManticoreStatementTest extends TestCase {

	public function testManticoreStatementCreate():void {
		$stmtBody = 'CREATE TABLE IF NOT EXISTS test (col text)';
		$stmt = ManticoreStatement::create($stmtBody);
		$this->assertInstanceOf(ManticoreStatement::class, $stmt);
		$postprocessor = function () {
		};
		$stmt = ManticoreStatement::create($stmtBody, $postprocessor);
		$this->assertEquals($postprocessor, $stmt->getPostprocessor());
	}

}
