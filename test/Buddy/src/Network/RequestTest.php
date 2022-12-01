<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\InvalidRequestError;
use Manticoresearch\Buddy\Network\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase {

	public function testManticoreRequestValidationOk(): void {
		echo "\nTesting the validation of a correct Manticore request\n";
		$payload = [
			'error' => 'some error',
			'type' => 'unknown json request',
			'message' => [
				'path_query' => '/cli',
				'body' => 'some query',
			],
			'version' => 1,
		];
		$request = Request::fromPayload($payload);
		$this->assertInstanceOf(Request::class, $request);
		$this->assertEquals($payload['message']['body'], $request->payload);
		$this->assertEquals(RequestFormat::JSON, $request->format);
	}

	public function testManticoreRequestValidationFail(): void {
		echo "\nTesting the validation of an incorrect Manticore request\n";
		$payload = [
			'error' => 'some error',
			'type' => 'error request',
			'message' => [
				'path_query' => '/cli',
				'body' => 'some query',
			],
			'version' => 1,
		];
		$this->expectException(InvalidRequestError::class);
		$this->expectExceptionMessage("Do not know how to handle 'error request' type");
		try {
			Request::fromPayload($payload);
		} finally {
			$payload['request_type'] = 'trololo';
			$this->expectException(InvalidRequestError::class);
			$this->expectExceptionMessage("Do not know how to handle 'error request' type");
			try {
				Request::fromPayload($payload);
			} finally {
				unset($payload['error']);
				$this->expectException(InvalidRequestError::class);
				$this->expectExceptionMessage(
					"Manticore request parse error: Mandatory field 'error' is missing"
				);
				try {
					Request::fromPayload($payload); // @phpstan-ignore-line
				} finally {
					$this->assertTrue(true);
				}
			}
		}
	}

	public function testManticoreQueryValidationOk(): void {
		$query = '{"error":"some error","type":"unknown json request",'
			. '"message":{"path_query":"/cli","body":"some query"},"version":1}';
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
					'Manticore request parse error: Invalid request payload is passed'
				);
				Request::fromString($query);
			}
		}
	}
}
