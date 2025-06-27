<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

/**
 * This file contains various global functions that are useful in some cases
 */
use Manticoresearch\Buddy\Base\Lib\MetricThread;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;

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
	return ConfigManager::get('TELEMETRY', '1') === '1';
}

/**
 * Little helper to convert config into int
 * @param string $val
 * @return int
 */
function return_bytes(string $val): int {
	$val = trim($val);
	$last = strtolower($val[strlen($val) - 1]);
	return (int)$val * match ($last) {
		'g' => 1024 * 1024 * 1024,
		'm' => 1024 * 1024,
		'k' => 1024,
		default => 1,
	};
}


/**
 * @param int $errno
 * @param string $errstr
 * @param string $errfile
 * @param int $errline
 * @return void
 */
function buddy_error_handler(int $errno, string $errstr, string $errfile, int $errline): void {
	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

/**
 * Crossplatform absolute path to project root of the Buddy sources
 * when running as Phar and not as Phar
 * @return string|bool
 */
function buddy_project_root(): string|bool {
	$projectRoot = class_exists('Phar') ? Phar::running(false) : false;
	if ($projectRoot) {
		$projectRoot = "phar://$projectRoot";
	} else {
		$projectRoot = realpath(
			__DIR__ . DIRECTORY_SEPARATOR
			. '..'
		);
	}

	return $projectRoot;
}
