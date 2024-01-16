<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\RecursionContext\InvalidArgumentException;
use Swoole\Process;

final class ProcessKillTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * Launch daemon as as setup stage
	 * @return void
	 */
	public function setUp(): void {
		self::setUpBeforeClass();
	}

	/**
	 *
	 * @return void
	 */
	public function tearDown(): void {
		self::tearDownAfterClass();
	}

	/**
	 *
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testBuddyStopsOnManticoreSigKill(): void {
		$this->assertEquals(true, Process::kill(self::$manticorePid, 0));
		$this->assertEquals(true, Process::kill(self::$buddyPid, 0));

		system('kill -9 ' . self::$manticorePid);
		sleep(1);
		$this->assertEquals(false, Process::kill(self::$manticorePid, 0));
		// We use more, cuz it takes time to shut down all threads
		sleep(10); // We have 5 sec tick when we check that no parrent for buddy
		$this->assertEquals(false, Process::kill(self::$buddyPid, 0));
	}

	/**
	 *
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testBuddyStopsOnManticoreSigInt(): void {
		$this->assertEquals(true, Process::kill(self::$manticorePid, 0));
		$this->assertEquals(true, Process::kill(self::$buddyPid, 0));

		system('kill -s INT ' . self::$manticorePid);
		sleep(6); // Give some delay to finish jobs and flush to disk

		$this->assertEquals(false, Process::kill(self::$manticorePid, 0));
		$this->assertEquals(false, Process::kill(self::$buddyPid, 0));
	}

	/**
	 *
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testBuddyStopsOnManticoreSigTerm(): void {
		$this->assertEquals(true, Process::kill(self::$manticorePid, 0));
		$this->assertEquals(true, Process::kill(self::$buddyPid, 0));

		system('kill -s TERM ' . self::$manticorePid);
		sleep(5); // Give some delay to finish jobs and flush to disk

		$this->assertEquals(false, Process::kill(self::$manticorePid, 0));
		$this->assertEquals(false, Process::kill(self::$buddyPid, 0));
	}

	/**
	 *
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testbuddyRestartedByManticoreOnKill(): void {
		$this->assertEquals(true, Process::kill(self::$manticorePid, 0));
		$this->assertEquals(true, Process::kill(self::$buddyPid, 0));

		// Kill buddy and check that its dead
		system('kill -9 ' . self::$buddyPid);
		sleep(1); // Slight delay
		$this->assertEquals(false, Process::kill(self::$buddyPid, 0));

		// Wait a bit and check that manticore relaunched buddy with new pid
		sleep(2); // Wait a bit again and parse new pids
		$this->loadBuddyPid();
		$this->assertEquals(true, Process::kill(self::$buddyPid, 0));
	}

}
