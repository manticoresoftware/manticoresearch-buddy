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

class OnStartOutputTest extends TestCase {

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
	}

	public function testConnectionAddressOutput(): void {
		echo "\nTesting if connection address info is passed from Buddy to daemon correctly\n";
		// Restart buddy with a new connection address
		self::tearDownAfterClass();
		self::setUpBeforeClass();
		// Checking the log part corresponding to the latest searchd start
		$logUpdate = $this->getLogUpdate();
		$matches = [];
		preg_match('/\[BUDDY\] started (.*?) at (.*?)$/', $logUpdate, $matches);
		$this->assertCount(3, $matches);
		$addr1 = $matches[2];

		// Checking if Buddy passes a refreshed connection address on restart
		self::tearDownAfterClass();
		self::setUpBeforeClass();
		$logUpdate = $this->getLogUpdate();
		preg_match('/\[BUDDY\] started (.*) at (.*?)$/', $logUpdate, $matches);
		$this->assertCount(3, $matches);
		$addr2 = $matches[2];

		$this->assertNotEquals($addr1, $addr2);
	}

	public function testTickersOutput(): void {
		echo "\nTesting if the output from ticker functions is passed from Buddy to daemon correctly\n";
		// Setting debug mode in Manticore config
		$conf = preg_replace('/buddy_path(.*?)(\r|\n)/', 'buddy_path$1 --debug$2', self::$manticoreConf);
		self::updateManticoreConf((string)$conf);
		self::tearDownAfterClass();
		self::setUpBeforeClass();
		// Unsetting debug mode in manticore config
		$conf = str_replace(' --debug', '', self::$manticoreConf);
		self::updateManticoreConf($conf);

		ob_flush();
		sleep(70);
		$logUpdate = $this->getLogUpdate();
		$this->assertStringContainsString('[BUDDY] memory usage:', $logUpdate);
		$this->assertEquals(0, preg_match('/running \d+ tasks$/', $logUpdate));
	}

	/**
	 * Helper function to retrieve the updated part of the searchd log
	 *
	 * @return string
	 */
	protected function getLogUpdate(): string {
		$curLog = (string)file_get_contents($this->searchdLogFilepath);
		$logUpdate = str_replace($this->searchdLog, '', $curLog);
		$this->searchdLog = $curLog;
		return $logUpdate;
	}
}
