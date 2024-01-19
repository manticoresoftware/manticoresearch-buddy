<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Insert\Error\ParserLoadError;
use PHPUnit\Framework\TestCase;

class ParserLoadErrorTest extends TestCase {

	public function testParserLoadError(): void {
		echo "\nTesting ParserLoadError raise\n";
		$this->expectException(ParserLoadError::class);
		$this->expectExceptionMessage('Test error message');
		throw new ParserLoadError('Test error message');
	}

}
