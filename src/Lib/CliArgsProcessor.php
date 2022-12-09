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

	private const LONG_OPTS  = [
		'disable-telemetry', 'debug', 'version', 'help', 'listen:',
	];
	private const DEFAULT_OPTS = ['listen' => '127.0.0.1:9308'];

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
			. "--listen             HTTP endpoint to send requestes to manticore\n"
			. "--version            display the current version of buddy\n"
			. "--help               display this help message\n"
			. "--disable-telemetry  disables telemetry for Buddy\n"
			. "--debug              enable debug mode for testing\n"
			. "Examples:\n"
			. "$script --debug\n"
			. "$script --disable-telemetry\n\n";
	}

	/**
	 * Build version message for the cli
	 *
	 * @return string
	 */
	private static function version(): string {
		return 'Manticore Buddy v' . buddy_version() . "\n"
			. "Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)\n"
		;
	}

	/**
	 * Process cli arguments passed
	 *
	 * @return array{disable-telemetry?:bool,debug?:bool,help?:bool,version?:bool,listen:string}
	 */
	public static function run(): array {
		/** @var array{disable-telemetry?:bool,debug?:bool,help?:bool,version?:bool,listen:string} */
		$opts = array_replace(self::DEFAULT_OPTS, getopt('', self::LONG_OPTS));
		if (isset($opts['help'])) {
			echo self::help();
			exit(0);
		}

		if (isset($opts['version'])) {
			echo self::version();
			exit(0);
		}

		if (isset($opts['disable-telemetry'])) {
			putenv('TELEMETRY=0');
		} else {
			putenv('TELEMETRY=1');
		}

		if (isset($opts['debug'])) {
			putenv('DEBUG=1');
		}

		if (str_starts_with($opts['listen'], 'http://0.0.0.0')) {
			$opts['listen'] = 'http://127.0.0.1' . substr($opts['listen'], 14);
		}
		putenv("LISTEN={$opts['listen']}");
		return $opts;
	}
}
