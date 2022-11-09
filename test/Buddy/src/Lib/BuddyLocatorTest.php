<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Interface\QueryParserLoaderInterface;
use Manticoresearch\Buddy\Lib\BuddyLocator;
use Manticoresearch\Buddy\Lib\QueryParserLoader;

use PHPUnit\Framework\TestCase;

/**
 * BuddyLocator test case.
 */
class BuddyLocatorTest extends TestCase {

	public function testLocation(): void {
		echo "\nTesting resource location\n";
		$buddyLocator = new BuddyLocator();
		$clsLocated = $buddyLocator->getByInterface(QueryParserLoaderInterface::class);
		$this->assertInstanceOf(QueryParserLoader::class, $clsLocated);
	}

}
