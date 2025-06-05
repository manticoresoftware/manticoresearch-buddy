<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Lib\CliArgsProcessor;
use Manticoresearch\Buddy\Base\Lib\ConfigManager;
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
		$this->assertEquals(1, ConfigManager::getInt('TELEMETRY'));
		$this->assertEquals(false, ConfigManager::getBool('DEBUG'));
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
			$this->assertEquals($threads, ConfigManager::getInt('THREADS'));
		}
	}

	public function testDisableTelemetryArgProcessOk(): void {
		CliArgsProcessor::run(['disable-telemetry' => false]);

		echo "\nTesting the processing of the `disable-telemetry` argument\n";
		$this->assertEquals('0', ConfigManager::get('TELEMETRY'));
	}

	public function testTelemetryPeriodArgProcessOk(): void {
		$period = random_int(5, 180);
		CliArgsProcessor::run(['telemetry-period' => $period]);

		echo "\nTesting the processing of the `telemetry-period` argument\n";
		$this->assertEquals($period, ConfigManager::getInt('TELEMETRY_PERIOD'));
	}
}
