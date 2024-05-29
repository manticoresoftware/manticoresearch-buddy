<?php declare(strict_types=1);

/*
 Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\CheckInsertDataTrait;
use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\Datatype;
use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\JSONInsertParser;
use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\SQLInsertParser;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class InsertDataCheckTest extends TestCase {

	use CheckInsertDataTrait;
	use TestProtectedTrait;

	/**
	 * @var SQLInsertParser $sqlParser
	 */
	private SQLInsertParser $sqlParser;

	/**
	 * @var JSONInsertParser $jsonParser
	 */
	private JSONInsertParser $jsonParser;

	protected function setUp(): void {
		$this->sqlParser = new SQLInsertParser();
		$this->jsonParser = new JSONInsertParser();
	}

	public function testColTypesOk(): void {
		// needs to be called from native jsonparser method to be able to use checker as callable
		echo "\nTesting the check of compatible data rows in INSERT query\n";

		$types = [Datatype::Int, Datatype::Text];
		$parser = $this->sqlParser;
		$row = "1, 'a'";
		$parser->checkColTypesError(
			[$parser, 'detectValType'],
			(array)self::invokeMethod($parser, 'parseInsertValues', [$row]),
			$types,
			['col1', 'col2'],
			QueryParseError::class
		);
		$this->assertTrue(true);

		$parser = $this->jsonParser;
		$row = ['col1' => 1, 'col2' => 'a'];
		$parser->checkColTypesError(
			[$parser, 'detectValType'],
			(array)self::invokeMethod($parser, 'parseInsertValues', [$row]),
			$types,
			['col1', 'col2'],
			QueryParseError::class
		);
		$this->assertTrue(true);
	}

	public function testSQLQueryColTypesCompatibilityFail(): void {
		echo "\nTesting the check of incompatible data rows in SQL insert query\n";

		$parser = $this->sqlParser;
		$types = [Datatype::Int, Datatype::Json];
		$row = "1, 'a'";
		$this->expectException(QueryParseError::class);
		$this->expectExceptionMessage("Incompatible types in 'col2': 'text json',");
		$parser->checkColTypesError(
			[$parser, 'detectValType'],
			(array)self::invokeMethod($parser, 'parseInsertValues', [$row]),
			$types,
			['col1', 'col2'],
			QueryParseError::class
		);
	}

	public function testJSONQueryColTypesCompatibilityFail(): void {
		echo "\nTesting the check of incompatible data rows in JSON insert query\n";

		$parser = $this->jsonParser;
		$types = [Datatype::Int, Datatype::Json];
		$row = ['col1' => 1, 'col2' => 'a'];
		$this->expectException(QueryParseError::class);
		$this->expectExceptionMessage("Incompatible types in 'col2': 'text json',");
		$parser->checkColTypesError(
			[$parser, 'detectValType'],
			(array)self::invokeMethod($parser, 'parseInsertValues', [$row]),
			$types,
			['col1', 'col2'],
			QueryParseError::class
		);
	}

	public function testSQLQueryColCountFail(): void {
		echo "\nTesting the check of wrong column count rows in SQL insert query\n";

		$parser = $this->sqlParser;
		$row = '1';
		$types = [Datatype::Int, Datatype::Text];
		$this->expectException(QueryParseError::class);
		$this->expectExceptionMessage('Column count mismatch in INSERT statement');
		$parser->checkColTypesError(
			[$parser, 'detectValType'],
			(array)self::invokeMethod($parser, 'parseInsertValues', [$row]),
			$types,
			['col1', 'col2'],
			QueryParseError::class
		);
	}

	public function testJSONQueryColCountFail(): void {
		echo "\nTesting the check of wrong column count rows in JSON insert query\n";

		$parser = $this->jsonParser;
		$row = ['col1' => 1];
		$types = [Datatype::Int, Datatype::Text];
		$this->expectException(QueryParseError::class);
		$this->expectExceptionMessage('Column count mismatch in INSERT statement');
		$parser->checkColTypesError(
			[$parser, 'detectValType'],
			(array)self::invokeMethod($parser, 'parseInsertValues', [$row]),
			$types,
			['col1', 'col2'],
			QueryParseError::class
		);
	}
}
