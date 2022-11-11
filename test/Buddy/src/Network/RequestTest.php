<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Exception\InvalidRequestError;
use Manticoresearch\Buddy\Network\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase {

	public function testManticoreRequestValidationOk(): void {
		echo "\nTesting the validation of a correct Manticore request\n";
		$payload = [
			'type' => 'some error',
			'message' => 'some query',
			'request_type' => 'sphinqxl',
			'endpoint' => 'cli',
			'listen' => '127.0.0.1:9308',
		];
		$request = Request::fromPayload($payload);
		$this->assertInstanceOf(Request::class, $request);
		$this->assertEquals($payload['message'], $request->query);
		$this->assertEquals($payload['type'], $request->origMsg);
	}

	public function testManticoreRequestValidationFail(): void {
		echo "\nTesting the validation of an incorrect Manticore request\n";
		$payload = [
			'type' => 'some error',
			'message' => 'some query',
			'request_type' => 'test',
			'endpoint' => 'cli',
			'listen' => '127.0.0.1:9308',
		];
		$this->expectException(InvalidRequestError::class);
		$this->expectExceptionMessage("Manticore request parse error: Unknown request type 'test'");
		try {
			Request::fromPayload($payload);
		} finally {
			$payload['request_type'] = 'trololo';
			$this->expectException(InvalidRequestError::class);
			$this->expectExceptionMessage("Manticore request parse error: Field 'request_type' must be a string");
			try {
				Request::fromPayload($payload);
			} finally {
				$payload['request_type'] = '';
				$this->expectException(InvalidRequestError::class);
				$this->expectExceptionMessage(
					"Manticore request parse error: Mandatory field 'request_type' is missing"
				);
				try {
					Request::fromPayload($payload);
				} finally {
					$this->assertTrue(true);
				}
			}
		}
	}

	public function testManticoreQueryValidationOk(): void {
		$query = "Some valid\nManticore query\n\n"
			. '{"type":"some error","message":"some query","request_type":"sphinqxl",'
			. '"endpoint":"cli","listen":"127.0.0.1:9308"}'
		;
		$request = Request::fromString($query);
		$this->assertInstanceOf(Request::class, $request);
	}

	public function testManticoreQueryValidationFail(): void {
		echo "\nTesting the validation of an incorrect request query from Manticore\n";
		$query = '';
		$this->expectException(InvalidRequestError::class);
		$this->expectExceptionMessage('Manticore request parse error: Query is missing');
		try {
			Request::fromString($query);
		} finally {
			$query = "Invalid query\nis passed\nagain";
			$this->expectException(InvalidRequestError::class);
			$this->expectExceptionMessage(
				"Manticore request parse error: Request body is missing in query '{$query}'"
			);
			try {
				Request::fromString($query);
			} finally {
				$query = 'Query\nwith unvalid\n\n{"request_body"}';
				$this->expectException(InvalidRequestError::class);
				$this->expectExceptionMessage(
					"Manticore request parse error: Invalid request body '{\"request_body\"}' is passed"
				);
				Request::fromString($query);
			}
		}
	}
}
