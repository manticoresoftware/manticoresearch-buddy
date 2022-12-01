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

	public function testBuddyResponseBuildOk(): void {
		echo "\nTesting the building of Buddy response\n";
		$result = [
			'version' => 1,
			'type' => 'json response',
			'message' => ['test message'],
			'error' => 'test error',
		];
		$this->assertEquals(
			json_encode($result),
			(string)Response::fromMessageAndError($result['message'], new Exception($result['error']))
		);
	}

	public function testBuddyResponseBuildFail(): void {
		echo "\nTesting the fail on the building of Buddy response\n";

		$msg = [chr(193)];
		$err = 'test error';
		$resp = (string)Response::fromMessageAndError($msg, new BuddyRequestError($err));
		$this->assertStringContainsString(
			'"error":"Build request error: test error"',
			$resp
		);
	}
}
