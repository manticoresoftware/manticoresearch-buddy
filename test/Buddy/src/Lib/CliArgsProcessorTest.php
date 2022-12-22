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

	public function testDefaultArgsProcessOk(): void {
		echo "\nTesting the processing of the arguments with default values\n";
		$this->assertEquals(1, getenv('TELEMETRY'));
		$this->assertEquals(false, getenv('DEBUG'));
	}

	public function testVersionArgProcessOk(): void {
		echo "\nTesting the processing of the `version` argument\n";
		$version = trim((string)file_get_contents(__DIR__ . '/../../../../APP_VERSION'));
		$res = "Manticore Buddy v$version\n"
			. "Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)\n";
		$this->assertEquals($res, self::invokeMethod(CliArgsProcessor::class, 'version'));
	}
}
