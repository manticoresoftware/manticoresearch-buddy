<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Response;
use Manticoresearch\BuddyTest\Lib\BuddyRequestError;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase {

	public function testBuddyResponseFromErrorAndMessageOk(): void {
		echo "\nTesting the building of Buddy response \n";
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

	public function testBuddyResponseFromMessageOk(): void {
		echo "\nTesting the building of Buddy response from message\n";
		$result = [
			'version' => 1,
			'type' => 'json response',
			'message' => ['test message'],
			'error' => '',
		];
		$this->assertEquals(
			json_encode($result),
			(string)Response::fromMessage($result['message'])
		);
		$this->assertEquals(
			json_encode($result),
			(string)Response::fromMessage($result['message'], RequestFormat::JSON)
		);
		$result['type'] = 'sql response';
		$this->assertEquals(
			json_encode($result),
			(string)Response::fromMessage($result['message'], RequestFormat::SQL)
		);
	}

	public function testBuddyResponseFromErrorOk(): void {
		echo "\nTesting the building of Buddy response from error\n";
		$result = [
			'version' => 1,
			'type' => 'json response',
			'message' => [
				['total' => 0, 'warning' => '', 'error' => 'simple error #1'],
			],
			'error' => 'simple error #1',
		];
		$error = new GenericError();
		$errorMsg = 'simple error #1';
		$error->setResponseError($errorMsg);
		$this->assertEquals($errorMsg, $error->getResponseError());
		$this->assertEquals(
			json_encode($result),
			(string)Response::fromError($error)
		);
		$this->assertEquals(
			json_encode($result),
			(string)Response::fromError($error, RequestFormat::JSON)
		);
		$result['type'] = 'sql response';
		$this->assertEquals(
			json_encode($result),
			(string)Response::fromError($error, RequestFormat::SQL)
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

	public function testBuddyResponseNone(): void {
		echo "\nTesting the building of the None response\n";
		$resp = Response::none();
		$this->assertInstanceOf(Response::class, $resp);
		$this->assertEquals('', (string)$resp);
	}
}
