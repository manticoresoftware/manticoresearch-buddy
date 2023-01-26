<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class DebugModeTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var string $searchdLog
	 */
	protected string $searchdLog;

	/**
	 * @var string $searchdLogFilepath
	 */
	protected string $searchdLogFilepath;

	public function setUp(): void {
		preg_match('/log = (.*?)[\r\n]/', self::$manticoreConf, $matches);
		$this->searchdLogFilepath = $matches[1];
		$this->searchdLog = (string)file_get_contents($this->searchdLogFilepath);
		self::setUpBeforeClass();
	}

	public function tearDown(): void {
		self::tearDownAfterClass();
	}

	public function testDebugModeOff(): void {
		echo "\nTesting the Buddy log output without debug mode enabled\n";
		ob_flush();
		// Waiting for the possible debug message to come
		sleep(70);
		// Checking the log part corresponding to the latest searchd start
		$logUpdate = str_replace($this->searchdLog, '', (string)file_get_contents($this->searchdLogFilepath));
		$this->assertStringNotContainsString('[BUDDY] memory usage:', $logUpdate);
		//self::$configFileName = 'manticore-debug.conf';
		self::setManticoreConfigFile('manticore-debug.conf');
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
	}

}
