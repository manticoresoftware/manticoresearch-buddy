<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

final class CliArgsProcessor {

	private const LONG_OPTS  = ['pid:', 'pid-file:', 'host::', 'port::', 'disable-telemetry', 'help', 'config:'];
	private const DEFAULT_OPTS = ['host' => '127.0.0.1', 'port' => 5000];

	/**
	 * @param array{
	 * host:string,port:string,pid:?string,pid-file:?string,port:?int,disable-telemetry?:bool,help?:bool,config?:string
	 * } $opts
	 * @return string
	 */
	private static function validate($opts): string {
		$errMsg = '';
		if (!isset($opts['pid'])) {
			$errMsg .= "Mandatory argument 'pid' is missing\n";
		} elseif (!is_numeric($opts['pid'])) {
			$errMsg .= "'pid' argument must be a number\n";
		}

		if (!isset($opts['pid-file'])) {
			$errMsg .= "Mandatory argument 'pid-file' is missing\n";
		} elseif (strpos($opts['pid-file'], "\0") !== false) {
			$errMsg .= "'pid-file' argument must be a filepath\n";
		} elseif (!file_exists($opts['pid-file'])) {
			$errMsg .= "File {$opts['pid-file']} from 'pid-file' argument does not exist\n";
		}

		if (isset($opts['port']) && !is_numeric($opts['port'])) {
			$errMsg .= "'port' argument must be a number\n";
		} elseif (isset($opts['port']) && is_numeric($opts['port']) && ($opts['port'] < 1 || $opts['port'] > 65536)) {
			$errMsg .= "'port' argument is not in valid range\n";
		}

		if (!isset($opts['config'])) {
			$errMsg .= "Mandatory argument 'config' is missing\n";
		} elseif (!file_exists($opts['config'])) {
			$errMsg .= "Config file is not readable: {$opts['config']}\n";
		}
		return $errMsg;
	}

	/**
	 * Build help message for cli call
	 *
	 * @return string
	 */
	private static function help(): string {
		$script = $_SERVER['argv'][0];
		// In case we run it manualy, and not with built release script, we should add executor
		if (basename($script) === 'main.php') {
			$script = 'manticore-executor src/main.php';
		}

		return "Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)\n\n"
			. "Usage: $script [ARGUMENTS]\n\n"
			. "Arguments are:\n"
			. "--help   			display this help message\n"
			. "--pid     			PID of a running Manticore instance\n"
			. "--pid-file			path to the Manticore workfile containing its current PID\n"
			. "--config       			path to the manticore searchd config file for handling some commands\n"
			. "--host   			hostname to connect with Manticore searchd\n"
			. "				(default is 127.0.0.1)\n"
			. "--port    			port to connect with Manticore searchd\n"
			. "				(default is 5000)\n"
			. "--disable-telemetry		disables telemetry for Buddy\n\n"
			. "Examples:\n"
			. "$script --pid=100 --pid-file=/var/run/manticore/searchd.pid\n"
			. "$script --pid=100 --pid-file=/var/run/manticore/searchd.pid --host=127.0.0.2 --port=1000\n"
			. "$script --pid=100 --pid-file=/var/run/manticore/searchd.pid --disable-telemetry\n\n"
			. "Detailed info on Manticore executor can be found "
			. "\e]8;;https://github.com/manticoresoftware/executor/\e\\here\e]8;;\e\\ "
			. "(https://github.com/manticoresoftware/executor)\n\n";
	}

	/**
	 * Process cli arguments passed
	 *
	 * @return array{0:int,1:string,2:string,3:int}
	 */
	public static function run(): array {
		/** @var array{host:string,port:string,pid:?string,pid-file:?string,port:?int,disable-telemetry?:bool,help?:bool,config:string} */
		$opts = array_replace(getopt('', self::LONG_OPTS), self::DEFAULT_OPTS);

		if (isset($opts['help'])) {
			echo self::help();
			exit(0);
		}

		$errMsg = self::validate($opts);
		if ($errMsg !== '') {
			echo $errMsg;
			exit(1);
		}

		if (isset($opts['disable-telemetry'])) {
			putenv('TELEMETRY=0');
		}

		putenv("SEARCH_CONFIG={$opts['config']}");

		return [(int)$opts['pid'], (string)$opts['pid-file'], $opts['host'], (int)$opts['port']];
	}
}
