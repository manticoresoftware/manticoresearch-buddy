<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Interface\InsertQueryParserInterface;
use Manticoresearch\Buddy\QueryParser\JSONInsertParser;
use Manticoresearch\Buddy\QueryParser\Loader;
use Manticoresearch\Buddy\QueryParser\SQLInsertParser;
use PHPUnit\Framework\TestCase;

class ParserLoaderTest extends TestCase {

	public function testParserLoader(): void {
		echo "\nGetting SQLInsertParser instance\n";
		$parser = Loader::getInsertQueryParser(RequestFormat::SQL);
		$this->assertInstanceOf(SQLInsertParser::class, $parser);
		$this->assertInstanceOf(InsertQueryParserInterface::class, $parser);
		echo "\nGetting JSONInsertParser instance\n";
		$parser = Loader::getInsertQueryParser(RequestFormat::JSON);
		$this->assertInstanceOf(JSONInsertParser::class, $parser);
	}

}
