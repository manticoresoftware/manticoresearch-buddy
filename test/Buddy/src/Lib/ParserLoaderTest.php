<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Interface\InsertQueryParserInterface;
use Manticoresearch\Buddy\Lib\JSONInsertParser;
use Manticoresearch\Buddy\Lib\QueryParserLoader;
use Manticoresearch\Buddy\Lib\SQLInsertParser;
use PHPUnit\Framework\TestCase;

class ParserLoaderTest extends TestCase {

	public function testParserLoader(): void {
		echo "\nGetting SQLInsertParser instance\n";
		$parser = QueryParserLoader::getInsertQueryParser(RequestFormat::SQL);
		$this->assertInstanceOf(SQLInsertParser::class, $parser);
		$this->assertInstanceOf(InsertQueryParserInterface::class, $parser);
		echo "\nGetting JSONInsertParser instance\n";
		$parser = QueryParserLoader::getInsertQueryParser(RequestFormat::JSON);
		$this->assertInstanceOf(JSONInsertParser::class, $parser);
	}

}
