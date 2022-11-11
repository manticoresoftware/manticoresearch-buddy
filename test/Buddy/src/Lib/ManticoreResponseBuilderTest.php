<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Lib\ManticoreResponse;
use Manticoresearch\Buddy\Lib\ManticoreResponseBuilder;
use PHPUnit\Framework\TestCase;

class ManticoreResponseBuilderTest extends TestCase {

	public function testBuildFromBody(): void {
		$responseBody = "[\n{\n"
			. '"columns": ['
			. "\n{\n"
			. '"id": {'
			. "\n"
			. '"type": "long long"'
			. "\n}\n},\n{\n"
			. '"a": {'
			. "\n"
			. '"type": "long"'
			. "\n}\n}\n],\n"
			. '"data": ['
			. "\n{\n"
			. '"id": 1,'
			. "\n"
			. '"a": 3'
			. "\n}\n]\n}\n]";
		$mntResponse = ManticoreResponseBuilder::buildFromBody($responseBody);
		$this->assertInstanceOf(ManticoreResponse::class, $mntResponse);
		$this->assertEquals($responseBody, $mntResponse->getBody());
	}

}
