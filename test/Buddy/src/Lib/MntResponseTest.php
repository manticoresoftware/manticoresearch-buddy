<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Exception\MntResponseError;
use Manticoresearch\Buddy\Lib\MntResponse;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class MntResponseTest extends TestCase {

	use TestProtectedTrait;

	/**
	 * @var MntResponse
	 */
	protected $response;

	/**
	 * @var ReflectionClass<MntResponse> $refCls
	 */
	protected $refCls;

	protected function setUp(): void {
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
		$this->response = new MntResponse($responseBody);
		$this->refCls = new \ReflectionClass(MntResponse::class);
	}

	public function testMntResponseCreateOk():void {
		echo "\nTesting the creation of Manticore response\n";
		$columns = [
			[
				'id' => ['type' => 'long long'],
			],
			[
				'a' => ['type' => 'long'],
			],
		];
		$data = [
			['id' => 1, 'a' => 3],
		];
		$this->assertInstanceOf(MntResponse::class, $this->response);
		$this->assertNull($this->refCls->getProperty('error')->getValue($this->response));
		$this->assertEquals($data, $this->refCls->getProperty('data')->getValue($this->response));
		$this->assertEquals($columns, $this->refCls->getProperty('columns')->getValue($this->response));
	}

	public function testMntResponseFail(): void {
		echo "\nTesting the fail on the creation of Manticore response\n";
		$this->expectException(MntResponseError::class);
		$this->expectExceptionMessage('Manticore response error: Unvalid JSON found');
		new MntResponse('{"some unvalid json"}');
	}

	public function testHasError(): void {
		echo "\nTesting the getting of the 'error' property\n";
		$this->assertFalse($this->response->hasError());
		$this->refCls->getProperty('error')->setValue($this->response, 'test error');
		$this->assertTrue($this->response->hasError());
		$this->assertEquals('test error', $this->response->getError());
	}

	public function testPostprocessOk(): void {
		echo "\nTesting the call of the postprocessor function\n";
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
			. '"id": 2,'
			. "\n"
			. '"a": 4'
			. "\n}\n]\n}\n]";
		$processor = function ($body, $data, $columns, $arg) {
			return isset($body, $data, $columns) ? $arg : null;
		};
		$this->response->postprocess($processor, [$responseBody]);
		$this->assertEquals($responseBody, $this->refCls->getProperty('body')->getValue($this->response));
	}

	public function testPostprocessFail(): void {
		echo "\nTesting the fail on the postprocessor function call\n";
		$processor = function ($body, $data, $columns) {
			return isset($body, $data, $columns) ? 'some unvalid json' : null;
		};
		$this->expectException(MntResponseError::class);
		$this->expectExceptionMessage('Manticore response error: Unvalid JSON found');
		$this->response->postprocess($processor);
	}

}
