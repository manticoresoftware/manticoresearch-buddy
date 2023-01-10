<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\InvalidRequestError;
use Manticoresearch\Buddy\Network\Request;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase {

	use TestProtectedTrait;

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
		$this->assertEquals($payload['version'], $request->version);
		$this->assertEquals($payload['error'], $request->error);
		$this->assertEquals(ManticoreEndpoint::Cli, $request->endpoint);

		$payload['message']['path_query'] = '';
		$request = Request::fromPayload($payload);
		$this->assertEquals(ManticoreEndpoint::Sql, $request->endpoint);
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

		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidRequestError::class, $exCls);
		$this->assertEquals("Do not know how to handle 'error request' type", $exMsg);

		$payload['request_type'] = 'trololo';
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidRequestError::class, $exCls);
		$this->assertEquals("Do not know how to handle 'error request' type", $exMsg);

		$payload['message']['path_query'] = '/test';
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidRequestError::class, $exCls);
		$this->assertEquals("Do not know how to handle '/test' path_query", $exMsg);

		unset($payload['error']);
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidRequestError::class, $exCls);
		$this->assertEquals("Mandatory field 'error' is missing", $exMsg);

		$payload['error'] = 123;
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidRequestError::class, $exCls);
		$this->assertEquals("Field 'error' must be a string", $exMsg);

		$payload['error'] = 'some error';
		$payload['message']['body'] = 123;
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromPayload', [$payload]);
		$this->assertEquals(InvalidRequestError::class, $exCls);
		$this->assertEquals("Field 'body' must be a string", $exMsg);
	}

	public function testManticoreQueryValidationOk(): void {
		$query = '{"error":"some error","type":"unknown json request",'
		. '"message":{"path_query":"/cli","body":"some query"},"version":1}';
		$id = mt_rand(0, 1000000);
		$request = Request::fromString($query, $id);
		$this->assertInstanceOf(Request::class, $request);
		$this->assertEquals($id, $request->id);
	}

	public function testManticoreQueryValidationFail(): void {
		echo "\nTesting the validation of an incorrect request query from Manticore\n";
		$query = '';
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromString', [$query]);
		$this->assertEquals(InvalidRequestError::class, $exCls);
		$this->assertEquals('The payload is missing', $exMsg);

		$query = "Invalid query\nis passed\nagain";
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromString', [$query]);
		$this->assertEquals(InvalidRequestError::class, $exCls);
		$this->assertEquals('Invalid request payload is passed', $exMsg);

		$query = 'Query\nwith unvalid\n\n{"request_body"}';
		[$exCls, $exMsg] = self::getExceptionInfo(Request::class, 'fromString', [$query]);
		$this->assertEquals(InvalidRequestError::class, $exCls);
		$this->assertEquals('Invalid request payload is passed', $exMsg);
	}
}
