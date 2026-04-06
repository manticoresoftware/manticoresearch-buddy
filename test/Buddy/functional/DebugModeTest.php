<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\RerunSearchdPerTestTrait;
use PHPUnit\Framework\TestCase;

class DebugModeTest extends TestCase {

	use RerunSearchdPerTestTrait;

	/**
	 * @var string $searchdLog
	 */
	protected string $searchdLog;

	/**
	 * @var string $searchdLogFilepath
	 */
	protected string $searchdLogFilepath;

	protected function beforeSearchdStart(): void {
		preg_match('/log = (.*?)[\r\n]/', self::$manticoreConf, $matches);
		if (!$matches) {
			throw new Exception('Cannot find searchd log path in manticore config');
		}
		$this->searchdLogFilepath = $matches[1];
		$this->searchdLog = (string)file_get_contents($this->searchdLogFilepath);
	}

	public function testDebugModeOff(): void {
		echo "\nTesting the Buddy log output without debug mode enabled\n";
		ob_flush();
		// Waiting for the possible debug message to come
		sleep(70);
		// Checking the log part corresponding to the latest searchd start
		$logUpdate = str_replace($this->searchdLog, '', (string)file_get_contents($this->searchdLogFilepath));
		$this->assertStringNotContainsString('[BUDDY] memory usage:', $logUpdate);
		static::setSearchdArgs(['--log-level=debugv']);
	}

	/**
	 * @depends testDebugModeOff
	 */
	public function testDebugModeOn(): void {
		echo "\nTesting the Buddy log output with debug mode enabled\n";
		ob_flush();
		// Waiting for the possible debug message to come
		sleep(70);
		// Checking the log part corresponding to the latest searchd start
		$logUpdate = str_replace($this->searchdLog, '', (string)file_get_contents($this->searchdLogFilepath));
		$this->assertStringContainsString('[BUDDY] memory usage:', $logUpdate);
		static::setSearchdArgs([]);
	}

}
