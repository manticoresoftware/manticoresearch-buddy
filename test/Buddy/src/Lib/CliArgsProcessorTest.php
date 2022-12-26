<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Lib\CliArgsProcessor;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class CliArgsProcessorTest extends TestCase {

	use TestProtectedTrait;

	protected function setUp():void {
		CliArgsProcessor::run();
	}

	protected function tearDown():void {
		putenv('TELEMETRY');
		putenv('DEBUG');
		putenv('LISTEN');
	}

	public function testListenArgProcessOk(): void {
		echo "\nTesting the processing of the `listen` argument\n";
		$refCls = new \ReflectionClass(CliArgsProcessor::class);
		$consts = $refCls->getConstant('DEFAULT_OPTS');
		if (is_array($consts)) {
			$this->assertEquals($consts['listen'], getenv('LISTEN'));
		}
		$this->assertEquals('127.0.0.1:9308', getenv('LISTEN'));
	}
}
