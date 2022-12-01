<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

/**
 * This file contains various global functions that are useful in some cases
 */
use Manticoresearch\Buddy\Lib\MetricThread;

/**
 * Emit metric into the separate thread
 *
 * @param string $name
 * @param int|float $value
 * @return void
 */
function buddy_metric(string $name, int|float $value) {
	static $thread;
	if (!isset($thread)) {
		$thread = MetricThread::start();
	}
	$thread->execute('add', [$name, $value]);
}


/**
 * This is helper to display debug info in debug mode
 *
 * @param string $message
 * @param string $eol
 * @return void
 */
function debug(string $message, string $eol = PHP_EOL): void {
	if (!getenv('DEBUG')) {
		return;
	}

	echo $message . $eol;
}

/**
 * Cross-platform function to get parent pid of manticore process
 *
 * @return int
 */
function get_parent_pid(): int {
	if (strncasecmp(PHP_OS, 'win', 3) === 0) {
		$pid = getmypid();  // child process ID
		$parentPid = (string)shell_exec("wmic process where (processid=$pid) get parentprocessid");
		$parentPid = explode("\n", $parentPid);
		$parentPid = (int)$parentPid[1];

		return $parentPid;
	}

	return posix_getppid();
}

/**
 * Check wether process is running or not
 *
 * @param int $pid
 * @return bool
 */
function process_exists(int $pid): bool {
	$isRunning = false;
	if (strncasecmp(PHP_OS, 'win', 3) === 0) {
		$out = [];
		exec("TASKLIST /FO LIST /FI \"PID eq $pid\"", $out);
		if (sizeof($out) > 1) {
			$isRunning = true;
		}
	} elseif (posix_kill($pid, 0)) {
		$isRunning = true;
	}
	return $isRunning;
}
