<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Exception\BuddyRequestError;
use Manticoresearch\Buddy\Exception\GenericError;
use Manticoresearch\Buddy\Network\Response;
use PHPUnit\Framework\TestCase;

class BuddyNetworkResponseTest extends TestCase {

	public function testBuddyResponseBuildOk(): void {
		echo "\nTesting the building of Buddy response\n";
		$result = [
			'version' => 1,
			'type' => 'json response',
			'message' => ['test message'],
			'error' => 'simple error #1',
		];
		$err = 'simple error #1';
		$error = new GenericError('this error goes to log');
		$error->setResponseError($err);
		$this->assertEquals($err, $error->getResponseError());
		$this->assertEquals(
			json_encode($result),
			(string)Response::fromMessageAndError($result['message'], $error)
		);
	}

	public function testBuddyResponseBuildFail(): void {
		echo "\nTesting the fail on the building of Buddy response\n";

		$msg = [chr(193)];
		$err = 'client error #2';
		$error = new BuddyRequestError('this error goes to log');
		$error->setResponseError($err);
		$this->assertEquals($err, $error->getResponseError());
		$resp = (string)Response::fromMessageAndError($msg, $error);
		$this->assertStringContainsString(
			'"error":"' . $error->getResponseError() . '"',
			$resp
		);
	}
}
