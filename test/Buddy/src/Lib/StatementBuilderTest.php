<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Lib\StatementBuilder;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class StatementBuilderTest extends TestCase {

	use TestProtectedTrait;

	public function testStatementBuilder(): void {
		echo "\nTesting statement builder\n";
		$stmtKeys = ['name', 'cols', 'colTypes'];
		$stmtValues = ['testTable', ['col1', 'col2'], ['text', 'int']];
		$stmtData = array_combine($stmtKeys, $stmtValues);
		$stmtBuilder = new StatementBuilder();
		$stmt = self::invokeMethod(StatementBuilder::class, 'buildCreateStmt', $stmtValues);
		$this->assertEquals('CREATE TABLE IF NOT EXISTS testTable (col1 text,col2 int)', $stmt);
		$stmt = $stmtBuilder->build('CREATE', $stmtData);
		$this->assertEquals('CREATE TABLE IF NOT EXISTS testTable (col1 text,col2 int)', $stmt);

		echo "\nTesting statement builder with incorrect operation\n";
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Unknown statement CUSTOM');
		try {
			$stmtBuilder->build('CUSTOM', $stmtData);
		} catch (RuntimeException $e) {
		}

		echo "\nTesting statement builder with incorrect data\n";
		unset($stmtData['cols']);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Build fields cols missing');
		$stmtBuilder->build('CREATE', $stmtData);
	}

}
