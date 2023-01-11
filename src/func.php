<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

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
	// Do nothing when no telemetry enabled
	if (!is_telemetry_enabled()) {
		return;
	}
	MetricThread::instance()->execute('add', [$name, $value]);
}

/**
 * Little helper to check if  we have telemetry enabled
 *
 * @return bool
 */
function is_telemetry_enabled(): bool {
	return getenv('TELEMETRY', true) === '1';
}

/**
 * Little helper to get current version of buddy
 *
 * @return string
 */
function buddy_version(): string {
	return trim((string)file_get_contents(__DIR__ . '/../APP_VERSION'));
}

/**
 * Single iteration implementation of camelcase to underscore
 *
 * @param string $string
 * @return string
 */
function camelcase_to_underscore(string $string): string {
	$result = '';
	$prevHigh = false;
	for ($i = 0, $max = strlen($string); $i < $max; $i++) {
		$curHigh = $string[$i] >= 'A' && $string[$i] <= 'Z';
		if ($result && !$prevHigh && $curHigh) {
			$result .= '_';
		}

		$result .= $curHigh ? strtolower($string[$i]) : $string[$i];
		$prevHigh = $curHigh;
	}

	return $result;
}

/**
 * Single iteration implementation of camelcase to underscore
 *
 * @param string $string
 * @return string
 */
function underscore_to_camelcase(string $string): string {
	return lcfirst(str_replace('_', '', ucwords($string, '_')));
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

/**
 * @param int $errno
 * @param string $errstr
 * @param string $errfile
 * @param int $errline
 * @return void
 */
function buddy_error_handler(int $errno, string $errstr, string $errfile, int $errline): void {
	if (!(error_reporting() & $errno)) {
	  // This error code is not included in error_reporting
		return;
	}

	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
