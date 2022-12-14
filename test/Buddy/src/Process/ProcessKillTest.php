<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\RecursionContext\InvalidArgumentException;

final class ProcessKillTest extends TestCase {
	/**
	 *
	 * @var int
	 */
	protected int $manticorePid;

	/**
	 *
	 * @var int
	 */
	protected int $buddyPid;

	/**
	 * Launch daemon as as setup stage
	 * @return void
	 */
	public function setUp(): void {
		$configFile = __DIR__ . '/../../../config/manticore.conf';
		system("searchd --config '$configFile'");
		$this->manticorePid = (int)trim((string)file_get_contents('/var/run/manticore-test/searchd.pid'));
		sleep(2); // <- give 2 secs to protect from any kind of lags

		$this->loadBuddyPid();
	}

	/**
	 *
	 * @return void
	 */
	public function tearDown(): void {
		system('pkill -9 searchd');
		// To be sure run again kills for each pid
		system("kill -9 {$this->manticorePid} 2> /dev/null");
		system("kill -9 {$this->buddyPid} 2> /dev/null");
		unset($this->manticorePid, $this->buddyPid);
	}

	/**
	 *
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testBuddyStopsOnManticoreSigKill(): void {
		$this->assertEquals(true, process_exists($this->manticorePid));
		$this->assertEquals(true, process_exists($this->buddyPid));

		system("kill -9 {$this->manticorePid}");
		sleep(1);
		$this->assertEquals(false, process_exists($this->manticorePid));
		sleep(5); // We have 5 sec tick when we check that no parrent for buddy
		$this->assertEquals(false, process_exists($this->buddyPid));
	}

	/**
	 *
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testBuddyStopsOnManticoreSigInt(): void {
		$this->assertEquals(true, process_exists($this->manticorePid));
		$this->assertEquals(true, process_exists($this->buddyPid));

		system("kill -s INT {$this->manticorePid}");
		sleep(6); // Give some delay to finish jobs and flush to disk

		$this->assertEquals(false, process_exists($this->manticorePid));
		$this->assertEquals(false, process_exists($this->buddyPid));
	}

	/**
	 *
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testBuddyStopsOnManticoreSigTerm(): void {
		$this->assertEquals(true, process_exists($this->manticorePid));
		$this->assertEquals(true, process_exists($this->buddyPid));

		system("kill -s TERM {$this->manticorePid}");
		sleep(5); // Give some delay to finish jobs and flush to disk

		$this->assertEquals(false, process_exists($this->manticorePid));
		$this->assertEquals(false, process_exists($this->buddyPid));
	}

	/**
	 *
	 * @return void
	 * @throws InvalidArgumentException
	 * @throws ExpectationFailedException
	 */
	public function testbuddyRestartedByManticoreOnKill(): void {
		$this->assertEquals(true, process_exists($this->manticorePid));
		$this->assertEquals(true, process_exists($this->buddyPid));

		// Kill buddy and check that its dead
		system("kill -9 {$this->buddyPid}");
		sleep(1); // Slight delay
		$this->assertEquals(false, process_exists($this->buddyPid));

		// Wait a bit and check that manticore relaunched buddy with new pid
		sleep(2); // Wait a bit again and parse new pids
		$this->loadBuddyPid();
		$this->assertEquals(true, process_exists($this->buddyPid));
	}

	/**
	 * Helper that allows us to reload fresh pid for relaunched
	 * Buddy process by the Manticore
	 * @return void
	 */
	protected function loadBuddyPid(): void {
		exec('ps --ppid ' . $this->manticorePid, $psOut);
		/** @var array<int,int> $pids */
		$pids = [];
		for ($i = 1, $max = sizeof($psOut); $i < $max; $i++) {
			$split = preg_split('/\s+/', trim($psOut[$i]));
			if (!$split) {
				continue;
			}

			$pids[] = (int)($split[0]);
		}
		if (!$pids) {
			throw new Exception("Failed to find children pids for {$this->manticorePid}");
		}
		$this->buddyPid = $pids[0];
	}
}
