<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Plugin\Insert\QueryParser\Datatype;
use Manticoresearch\Buddy\Plugin\Insert\QueryParser\JSONInsertParser;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class JSONInsertParserTest extends TestCase {

	use TestProtectedTrait;

	protected static JSONInsertParser $parser;

	public function testNdJSONParse(): void {
		echo "\nTesting the parsing of NDJSON data\n";
		$query = '{ "insert" : { "index" : "test", "id" : 1, "doc": { "col1" : 1 } } }'
			. "\n"
			. '{ "insert" : { "index" : "test", "id" : 2, "doc": { "col1" : 2 } } }';
		$res = [
			'{ "insert" : { "index" : "test", "id" : 1, "doc": { "col1" : 1 } } }',
			'{ "insert" : { "index" : "test", "id" : 2, "doc": { "col1" : 2 } } }',
		];
		foreach (JSONInsertParser::parseNdJSON($query) as $i => $row) {
			$this->assertEquals($res[$i], $row);
		}
	}

	public function testInsertValTypeDetection(): void {
		echo "\nTesting the detection of an Insert value datatype\n";
		self::$parser = new JSONInsertParser();

		$this->assertEquals(Datatype::Float, self::invokeMethod(self::$parser, 'detectValType', [0.1]));
		$this->assertEquals(Datatype::Text, self::invokeMethod(self::$parser, 'detectValType', ['0.1']));
		$this->assertEquals(Datatype::Bigint, self::invokeMethod(self::$parser, 'detectValType', [11111111111]));
		$this->assertEquals(Datatype::Text, self::invokeMethod(self::$parser, 'detectValType', ['11111111111']));
		$this->assertEquals(Datatype::Int, self::invokeMethod(self::$parser, 'detectValType', [1]));
		$this->assertEquals(Datatype::Json, self::invokeMethod(self::$parser, 'detectValType', [['a' => 1]]));
		$this->assertEquals(
			Datatype::Multi64, self::invokeMethod(self::$parser, 'detectValType', [[1, 1111111111111]])
		);
		$this->assertEquals(Datatype::Multi, self::invokeMethod(self::$parser, 'detectValType', [[11, 1]]));
		$this->assertEquals(Datatype::Json, self::invokeMethod(self::$parser, 'detectValType', [[1, 0.1]]));
		$this->assertEquals(
			Datatype::String,
			self::invokeMethod(self::$parser, 'detectValType', ['testmail@google.com'])
		);
		$this->assertEquals(Datatype::Text, self::invokeMethod(self::$parser, 'detectValType', ['test text']));
	}

	public function testInsertRowParse(): void {
		echo "\nTesting the extraction of Insert row data\n";

		$parserCls = new \ReflectionClass(self::$parser);
		//$query = ['insert' => ['index' => 'test', 'id' => 1, 'doc' => ['col1' => 10, 'col2' => 'a']]];
		$query = ['index' => 'test', 'id' => 1, 'doc' => ['col1' => 10, 'col2' => 'a']];
		$resp = ['col1' => 10, 'col2' => 'a'];
		$this->assertEquals($resp, self::$parser->parseJSONRow($query));
		$this->assertEquals('test', $parserCls->getProperty('name')->getValue(self::$parser));
		$this->assertEquals(['col1', 'col2'], $parserCls->getProperty('cols')->getValue(self::$parser));

		$row = ['update' => ['index' => 'test', 'id' => 1, 'doc' => ['col1' => 10, 'col2' => 'a']]];
		$this->expectException(QueryParseError::class);
		//$this->expectExceptionMessage("Operation name 'insert' is missing");
		$this->expectExceptionMessage("Mandatory request field 'index' is missing");
		$this->assertEquals([], self::$parser->parseJSONRow($row));
	}

	public function testInsertValuesParse(): void {
		echo "\nTesting the extraction of single values from Insert row data\n";
		$row = ['col1' => 10, 'col2' => 'a'];
		$this->assertEquals([10, 'a'], self::invokeMethod(self::$parser, 'parseInsertValues', [$row]));
	}

	public function testParseOk(): void {
		echo "\nTesting the parsing of JSON insert request\n";
		$query = '{"index" : "test", "id" : 1, "doc": { "col1" : "a" } }';
		$res = [
			'name' => 'test',
			'cols' => ['col1'],
			'colTypes' => ['text'],
		];
		$this->assertEquals($res, self::$parser->parse($query));

		$query = '{"index" : "test", "id" : 1, "doc": { "col1" : "m1@google.com", "col2": 1,'
			. '"col3": 111111111111, "col4": {"b": 2}, "col5": [1, 2], "col6": [1, 222222222222], "col7": "c",'
			. '"col8": 0.1 } }';
		$res = [
			'name' => 'test',
			'cols' => ['col1', 'col2', 'col3', 'col4', 'col5', 'col6', 'col7', 'col8'],
			'colTypes' => ['string', 'int', 'bigint', 'json', 'multi', 'multi64', 'text', 'float'],
		];
		$this->assertEquals($res, self::$parser->parse($query));

		$query = '{ "insert" : { "index" : "test", "id" : 1, "doc": { "col1" : 10, "col2": "a" } } }'
			. "\n"
			. '{ "insert" : { "index" : "test", "id" : 2, "doc": { "col1" : 20, "col2": "b" } } }';
		$res = [
			'name' => 'test',
			'cols' => ['col1', 'col2'],
			'colTypes' => ['int', 'text'],
		];
		$this->assertEquals($res, self::$parser->parse($query));
	}

	public function testParseFail(): void {

		echo "\nTesting the parsing of an incorrect JSON insert request\n";
		$query = '{ "insert" : { "index" : "test", "id" : 1, "doc": { "col1" : 10, "col2": "a" } } }'
			. "\n"
			. '{ "insert" : { "index" : "test", "id" : 2, "doc": { "col1" : "c", "col2": "b" } } }';

		[$exCls, $exMsg] = self::getExceptionInfo(self::$parser, 'parse', [$query]);
		$this->assertEquals(QueryParseError::class, $exCls);
		$this->assertEquals("Incompatible types in 'col1': 'text int',", $exMsg);

		$query = '{ "update" : { "index" : "test", "id" : 1, "doc": { "col1" : 10, "col2": "a" } } }';
		[$exCls, $exMsg] = self::getExceptionInfo(self::$parser, 'parse', [$query]);
		$this->assertEquals(QueryParseError::class, $exCls);
		$this->assertEquals("Operation name 'insert' is missing", $exMsg);
	}
}
