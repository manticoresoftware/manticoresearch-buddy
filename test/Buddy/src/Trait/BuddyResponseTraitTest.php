<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Exception\BuddyRequestError;
use Manticoresearch\Buddy\Trait\BuddyResponseTrait;
use PHPUnit\Framework\TestCase;
use \RuntimeException;

class BuddyResponseTraitTest extends TestCase {

	use BuddyResponseTrait;

	public function testBuddyResponseBuildOk():void {
		echo "\nTesting the building of Buddy response\n";
		$msg = 'test message';
		$err = 'test error';
		$resp = "\"type\":\"http response\",\"message\":$msg,\"error\":$err";
		$resp = "HTTP/1.1 200\r\nServer: buddy\r\nContent-Type: application/json; charset=UTF-8\r\n"
			. "Content-Length: 70\r\n\r\n{\"type\":\"http response\",\"message\":\"$msg\",\"error\":\"$err\"}";
		$this->assertEquals($resp, $this->buildResponse($msg, $err));
	}

	public function testBuddyResponseBuildFail():void {
		echo "\nTesting the fail on the building of Buddy response\n";
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('JSON data encode error');
		$msg = chr(193);
		$err = 'test error';
		try {
			$this->buildResponse($msg, $err);
		} finally {
			echo "\nTesting the fail with custom error handler previously passed\n";
			$this->expectException(BuddyRequestError::class);
			$this->expectExceptionMessage('Build request error: JSON data encode error');
			$this->buildResponse($msg, $err, BuddyRequestError::class);
		}
	}

}
