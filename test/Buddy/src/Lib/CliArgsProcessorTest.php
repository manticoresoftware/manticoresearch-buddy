<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Lib\CliArgsProcessor;
use Manticoresearch\Buddy\Core\Tool\Buddy;
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
		$version = Buddy::getVersion();
		$res = "Manticore Buddy v$version\n"
			. "Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)\n";
		$this->assertEquals($res, self::invokeMethod(CliArgsProcessor::class, 'version'));
	}

	public function testThreadsArgProcessOk(): void {
		echo "\nTesting the processing of the `threads` argument\n";
		for ($threads = 1; $threads < 12; $threads++) {
			CliArgsProcessor::run(['threads' => $threads]);
			$this->assertEquals($threads, (int)getenv('THREADS', true));
		}
	}

	public function testMultipleThreadsArgProcessOk(): void {
		echo "\nTesting the processing of multiple `threads` arguments (first value should be used)\n";

		// Test case: --threads=1 --threads=2 should use 1
		CliArgsProcessor::run(['threads' => [1, 2]]);
		$this->assertEquals(1, (int)getenv('THREADS', true));

		// Test case: --threads=5 --threads=10 --threads=3 should use 5
		CliArgsProcessor::run(['threads' => [5, 10, 3]]);
		$this->assertEquals(5, (int)getenv('THREADS', true));

		// Test case: --threads=8 --threads=1 should use 8
		CliArgsProcessor::run(['threads' => [8, 1]]);
		$this->assertEquals(8, (int)getenv('THREADS', true));
	}

	public function testDisableTelemetryArgProcessOk(): void {
		CliArgsProcessor::run(['disable-telemetry' => false]);

		echo "\nTesting the processing of the `disable-telemetry` argument\n";
		$this->assertEquals('0', getenv('TELEMETRY', true));
	}

	public function testTelemetryPeriodArgProcessOk(): void {
		$period = random_int(5, 180);
		CliArgsProcessor::run(['telemetry-period' => $period]);

		echo "\nTesting the processing of the `telemetry-period` argument\n";
		$this->assertEquals($period, (int)getenv('TELEMETRY_PERIOD', true));
	}
}
