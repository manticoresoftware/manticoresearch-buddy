<?php declare(strict_types=1);

/*
 Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\Datatype;
use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\SQLInsertParser;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class SQLInsertParserTest extends TestCase {

	use TestProtectedTrait;

	protected static SQLInsertParser $parser;

	public function testInsertValTypeDetection(): void {
		echo "\nTesting the detection of an Insert value datatype\n";

		$parser = new SQLInsertParser();
		self::$parser = $parser;
		$this->assertEquals(Datatype::Float, self::invokeMethod($parser, 'detectValType', [0.1]));
		$this->assertEquals(Datatype::Float, self::invokeMethod($parser, 'detectValType', ['0.1']));
		$this->assertEquals(Datatype::Bigint, self::invokeMethod($parser, 'detectValType', [11111111111]));
		$this->assertEquals(Datatype::Bigint, self::invokeMethod($parser, 'detectValType', ['11111111111']));
		$this->assertEquals(Datatype::Int, self::invokeMethod($parser, 'detectValType', [1]));
		$this->assertEquals(Datatype::Json, self::invokeMethod($parser, 'detectValType', ['\'{"a":1}\'']));
		$this->assertEquals(Datatype::Json, self::invokeMethod($parser, 'detectValType', ['(1, 1.5)']));
		$this->assertEquals(
			Datatype::Multi64, self::invokeMethod($parser, 'detectValType', ['(1, 1111111111111)'])
		);
		$this->assertEquals(Datatype::Multi, self::invokeMethod($parser, 'detectValType', ['(11, 1)']));
		$this->assertEquals(Datatype::String, self::invokeMethod($parser, 'detectValType', ['testmail@google.com']));
		$this->assertEquals(Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01T01']));
		$this->assertEquals(Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01T01:01']));
		$this->assertEquals(
			Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01T01:01:01'])
		);
		$this->assertEquals(
			Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01T01:01:01.001'])
		);
		$this->assertEquals(
			Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01T01:01:01.001+01:00'])
		);
		$this->assertEquals(
			Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01T01:01:01+01:00'])
		);
		$this->assertEquals(Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01 01:01']));
		$this->assertEquals(
			Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01 01:01:01'])
		);
		$this->assertEquals(
			Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01 01:01:01.001'])
		);
		$this->assertEquals(
			Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01 01:01:01.001+01:00'])
		);
		$this->assertEquals(
			Datatype::Timestamp, self::invokeMethod($parser, 'detectValType', ['2000-01-01 01:01:01+01:00'])
		);
		$this->assertEquals(Datatype::Text, self::invokeMethod($parser, 'detectValType', ['test text']));
	}

	public function testCommaBlockReplace(): void {
		echo "\nTesting the temporary replace of blocks containg commas to tokens\n";

		$parser = new SQLInsertParser();
		$class = new \ReflectionClass($parser);
		$row = "1, 'a', (2, 'b'), 0.5";
		$resRow = '1, %2, %1, 0.5';
		$replacedBlocks = ['%1' => ["(2, 'b')"], '%2' => ["'a'"]];
		$this->assertEquals($resRow, self::invokeMethod($parser, 'replaceCommaBlocks', [$row]));
		$this->assertEquals($replacedBlocks, $class->getProperty('blocksReplaced')->getValue($parser));
		$row = "1, 'a', '{\"a\":1, \"b\":2}', 0.5";
		$resRow = '1, %2, %2, 0.5';
		$replacedBlocks = ['%2' => ["'a'", "'%0'"], '%0' => ['{"a":1, "b":2}']];
		$this->assertEquals($resRow, self::invokeMethod($parser, 'replaceCommaBlocks', [$row]));
		$this->assertEquals($replacedBlocks, $class->getProperty('blocksReplaced')->getValue($parser));
	}

	public function testInsertValuesParse(): void {
		echo "\nTesting the extraction of single values from Insert row data\n";

		$parser = new SQLInsertParser();
		$res = ["'1'", "'a'", "'{\"a\":1}'", "'0.5'"];
		$row = "'1', 'a', '{\"a\":1}', '0.5'";
		$this->assertEquals($res, self::invokeMethod($parser, 'parseInsertValues', [$row]));
		$row = "'1','a','{\"a\":1}','0.5'";
		$this->assertEquals($res, self::invokeMethod($parser, 'parseInsertValues', [$row]));
		$res = ["'1'", "'a'", "'{\"a\":1, \"b\":2}'", "'0.5'"];
		$row = "'1', 'a', '{\"a\":1, \"b\":2}', '0.5'";
		$this->assertEquals($res, self::invokeMethod($parser, 'parseInsertValues', [$row]));
	}

	public function testInsertRowsParse(): void {
		echo "\nTesting the extraction of Insert row data\n";

		$parser = new SQLInsertParser();
		$valueExpr = "('1', 'a', '{\"a\":1}', '0.5'),\n('2', 'b'), ('3', (1, 2)), ('4', 0.5)";
		$resRows = ["'1', 'a', '{\"a\":1}', '0.5'", "'2', 'b'", "'3', (1, 2)", "'4', 0.5"];
		$rowGen = self::invokeMethod($parser, 'parseInsertRows', [$valueExpr]);
		if (!is_iterable($rowGen)) {
			return;
		}
		foreach ($rowGen as $i => $row) {
			$this->assertEquals($resRows[$i], $row);
		}
	}

	public function testParseOk(): void {
		echo "\nTesting the parsing of SQL insert request\n";

		$query = 'INSERT INTO test(col1,col2,col3,col4,col5,col6,col7,col8,@timestamp) VALUES'
			. "('m1@google.com', 1, 111, '{\"b\":2}', (1,2), (1,11111111111), (0.2,0.8), 'c', '2000-01-01T12:00:00Z'),"
			. "('m2@google.com', 2, 222222222222, '{\"a\": \"(2,3)\"}', (2,3,4,5), (222222222222),"
			. " (0.8,0.2), 'qqq', '2000-01-01T12:00:30Z')";
		$res = [
			'name' => 'test',
			'cols' => ['col1', 'col2', 'col3', 'col4', 'col5', 'col6', 'col7', 'col8', '@timestamp'],
			'colTypes' => ['string', 'int', 'bigint', 'json', 'multi', 'multi64', 'json', 'text', 'timestamp'],
		];
		$this->assertEquals($res, self::$parser->parse($query));

		$parser = new SQLInsertParser();
		$query = 'INSERT INTO test(col1,col2,col3,col4,col5,col6,col7,col8) VALUES'
			. "('m1@google.com', 1, 111111111111, '{\"b\":2}', (1,2), (1,111), (0.2,1), 'c'),"
			. "('m2@google.com', 2, 222, '{\"a\": \"(2,3)\"}', (2,3,4,5), (222), (1,0.2), 'qqq')";
		$res = [
			'name' => 'test',
			'cols' => ['col1', 'col2', 'col3', 'col4', 'col5', 'col6', 'col7', 'col8'],
			'colTypes' => ['string', 'int', 'bigint', 'json', 'multi', 'multi', 'json', 'text'],
		];
		$this->assertEquals($res, $parser->parse($query));

		$parser = new SQLInsertParser();
		$query = 'INSERT INTO test(col1,col2,col3,col4,col5,col6,col7,col8) VALUES'
			. "\n"
			. "('m1@google.com', 1, 111111111111, '{\"b\":2}', (1,2), (1,111), (1,2), 'c'),"
			. "\n"
			. "('some text', 2, 222, '{\"a\": \"(2,3)\"}', (2,3,4,5), (222), (0.5,1), 'qqq')";
		$res = [
			'name' => 'test',
			'cols' => ['col1', 'col2', 'col3', 'col4', 'col5', 'col6', 'col7', 'col8'],
			'colTypes' => ['text', 'int', 'bigint', 'json', 'multi', 'multi', 'json', 'text'],
		];
		$this->assertEquals($res, $parser->parse($query));
	}

	public function testParseFail(): void {
		echo "\nTesting the fails on the parsing of SQL insert request\n";
		$parser = new SQLInsertParser();
		$query = "INSERT INTO test VALUES ('val1')";
		[$exCls, $exMsg] = self::getExceptionInfo($parser, 'parse', [$query]);
		$this->assertEquals(QueryParseError::class, $exCls);
		$this->assertEquals(
			'Cannot create table with column names missing',
			$exMsg
		);

		$parser = new SQLInsertParser();
		$query = 'INSERT INTO test(col1) VALUES';
		[$exCls, $exMsg] = self::getExceptionInfo($parser, 'parse', [$query]);
		$this->assertEquals(QueryParseError::class, $exCls);
		$this->assertEquals('Invalid query passed: INSERT INTO test(col1) VALUES', $exMsg);
	}
}
