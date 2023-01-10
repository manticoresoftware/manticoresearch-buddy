<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

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

	protected function tearDown():void {
		putenv('TELEMETRY');
		putenv('TELEMETRY_PERIOD');
		putenv('DEBUG');
		putenv('LISTEN');
		putenv('THREADS');
	}

	public function testDefaultArgsProcessOk(): void {
		CliArgsProcessor::run();

		echo "\nTesting the processing of the arguments with default values\n";
		$this->assertEquals(1, getenv('TELEMETRY'));
		$this->assertEquals(false, getenv('DEBUG'));
	}

	public function testListenArgProcessOk(): void {
		CliArgsProcessor::run();

		echo "\nTesting the processing of the `listen` argument\n";
		$refCls = new \ReflectionClass(CliArgsProcessor::class);
		$consts = $refCls->getConstant('DEFAULT_OPTS');
		if (is_array($consts)) {
			$this->assertEquals($consts['listen'], getenv('LISTEN', true));
		}
		$this->assertEquals('127.0.0.1:9308', getenv('LISTEN', true));

		$listen = '10.0.0.1:34445';
		CliArgsProcessor::run(['listen' => $listen]);
		$this->assertEquals($listen, getenv('LISTEN', true));
	}

	public function testVersionArgProcessOk(): void {
		CliArgsProcessor::run();

		echo "\nTesting the processing of the `version` argument\n";
		$version = trim((string)file_get_contents(__DIR__ . '/../../../../APP_VERSION'));
		$res = "Manticore Buddy v$version\n"
			. "Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)\n";
		$this->assertEquals($res, self::invokeMethod(CliArgsProcessor::class, 'version'));
	}

	public function testThreadsArgProcessOk(): void {
		$threads = mt_rand(5, 10);
		CliArgsProcessor::run(['threads' => $threads]);

		echo "\nTesting the processing of the `threads` argument\n";
		$this->assertEquals($threads, (int)getenv('THREADS', true));
	}

	public function testDisableTelemetryArgProcessOk(): void {
		CliArgsProcessor::run(['disable-telemetry' => false]);

		echo "\nTesting the processing of the `disable-telemetry` argument\n";
		$this->assertEquals('0', getenv('TELEMETRY', true));
	}

	public function testTelemetryPeriodArgProcessOk(): void {
		$period = mt_rand(5, 180);
		CliArgsProcessor::run(['telemetry-period' => $period]);

		echo "\nTesting the processing of the `telemetry-period` argument\n";
		$this->assertEquals($period, (int)getenv('TELEMETRY_PERIOD', true));
	}
}
