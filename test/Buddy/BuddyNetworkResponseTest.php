<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Exception\BuddyRequestError;
use Manticoresearch\Buddy\Network\Response;
use PHPUnit\Framework\TestCase;

class BuddyNetworkResponseTest extends TestCase {

	public function testBuddyResponseBuildOk():void {
		echo "\nTesting the building of Buddy response\n";
		$msg = 'test message';
		$err = 'test error';
		$resp = "{\"type\":\"http response\",\"message\":\"$msg\",\"error\":\"$err\"}";
		$this->assertEquals($resp, (string)Response::fromStringAndError($msg, new Exception($err)));
	}

	public function testBuddyResponseBuildFail():void {
		echo "\nTesting the fail on the building of Buddy response\n";

		$msg = chr(193);
		$err = 'test error';
		$resp = (string)Response::fromStringAndError($msg, new BuddyRequestError($err));
		$this->assertStringContainsString(
			'{"type":"http response","message":"","error":"Build request error: test error"}',
			$resp
		);
	}

}
