<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Lib;

use InvalidArgumentException;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class CliArgsProcessor {

	private const LONG_OPTS  = [
		'threads:', 'telemetry-period:', 'disable-telemetry',
		'version', 'help', 'listen:', 'bind:',
		'skip:', 'log-level:',
	];
	private const DEFAULT_OPTS = [
		'listen' => '127.0.0.1:9308',
		'bind' => '127.0.0.1',
		'skip' => [],
	];

	/**
	 * Build help message for cli call
	 *
	 * @return string
	 */
	private static function help(): string {
		$script = $_SERVER['argv'][0];
		// In case we run it manually, and not with built release script, we should add executor
		if (basename($script) === 'main.php') {
			$script = 'manticore-executor src/main.php';
		}

		return "Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)\n\n"
			. "Usage: $script [ARGUMENTS]\n\n"
			. "Arguments are:\n"
			. "--bind                 Which IP to bind. Default is 127.0.0.1\n"
			. '--skip                 Skips the specified plugin by its fully qualified name;'
				. " can be used multiple times\n"
			. "--listen               HTTP endpoint to accept Manticore requests\n"
			. "--version              display the current version of Buddy\n"
			. "--help                 display this help message\n"
			. "--telemetry-period=[N] set period for telemetry when we do snapshots\n"
			. "--disable-telemetry    disables telemetry for Buddy\n"
			. "--threads=[N]          start N threads on launch, default is 4\n"
			.	'--log-level=[N]        set log level for Buddy, default is info, values: info, debug, debugv, debugvv'
			. "Examples:\n"
			. "$script --log-level=debug\n"
			. "$script --disable-telemetry\n\n";
	}

	/**
	 * Build version message for the cli
	 *
	 * @return string
	 */
	private static function version(): string {
		return 'Manticore Buddy v' . Buddy::getVersion() . "\n"
			. "Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)\n"
		;
	}

	/**
	 * Process cli arguments passed
	 *
	 * @param ?array{
	 *  threads?:int,
	 *  telemetry-period?:int,
	 *  disable-telemetry?:bool,
	 *  help?:bool,
	 *  version?:bool,
	 *  listen?:string,
	 *  bind?:string,
	 *  skip?:string[],
	 *  log-level?:string
	 * } $opts
	 * @return array{
	 *  threads?:int,
	 *  telemetry-period?:int,
	 *  disable-telemetry?:bool,
	 *  help?:bool,
	 *  version?:bool,
	 *  listen:string,
	 *  bind:string,
	 *  skip:string[]
	 * }
	 */
	public static function run(?array $opts = null): array {
		if (!isset($opts)) {
			$opts = getopt('', self::LONG_OPTS);
		}
		/** @var array{
		 *  threads?:int,
		 *  telemetry-period?:int,
		 *  disable-telemetry?:bool,
		 *  help?:bool,
		 *  version?:bool,
		 *  listen:string,
		 *  bind:string,
		 *  skip:string[],
		 *  log-level?:string,
		 * } $opts
		 */
		$opts = array_replace(self::DEFAULT_OPTS, $opts); // @phpstan-ignore-line

		if (isset($opts['help'])) {
			echo self::help();
			exit(0);
		}

		if (isset($opts['version'])) {
			echo self::version();
			exit(0);
		}


		static::parseThreads($opts);
		static::parseDisableTelemetry($opts);
		static::parseTelemetryPeriod($opts);
		static::parseListen($opts);
		static::parseBind($opts);
		$opts['skip'] = static::parseSkip($opts);

		if (isset($opts['log-level'])) {
			static::parseLogLevel($opts['log-level']);
		}

		return $opts;
	}

	/**
	 * @param array{threads?:int} $opts
	 * @return void
	 */
	protected static function parseThreads(array $opts): void {
		if (!isset($opts['threads'])) {
			return;
		}

		if ($opts['threads'] < 1 || $opts['threads'] > 256) {
			echo "The --threads value must be in the range of 1 to 256.\n";
			exit(1);
		}

		putenv('THREADS=' . (int)$opts['threads']);
	}

	/**
	 * @param array{disable-telemetry?:bool} $opts
	 * @return void
	 */
	protected static function parseDisableTelemetry(array $opts): void {
		if (isset($opts['disable-telemetry'])) {
			putenv('TELEMETRY=0');
		} else {
			putenv('TELEMETRY=1');
		}
	}

	/**
	 * @param string $level
	 * @return void
	 */
	protected static function parseLogLevel(string $level): void {
		match ($level) {
			'info' => null,
			'debug' => putenv('DEBUG=1'),
			'debugv' => putenv('DEBUG=2'),
			'debugvv' => putenv('DEBUG=3'),
			default => throw new InvalidArgumentException("Invalid log level {$level}"),
		};
	}

	/**
	 * @param array{telemetry-period?:int} $opts
	 * @return void
	 */
	protected static function parseTelemetryPeriod(array $opts): void {
		if (!isset($opts['telemetry-period'])) {
			return;
		}

		if ($opts['telemetry-period'] < 5 || $opts['telemetry-period'] > 1800) {
			echo "The --telemetry-period must be in range of 5 to 1800 secs.\n";
			exit(1);
		}
		putenv('TELEMETRY_PERIOD=' . (int)$opts['telemetry-period']);
	}

	/**
	 * @param array{listen:string} $opts
	 * @return void
	 */
	protected static function parseListen(array $opts): void {
		if (str_starts_with($opts['listen'], 'http://0.0.0.0')) {
			$opts['listen'] = 'http://127.0.0.1' . substr($opts['listen'], 14);
		}
		putenv("LISTEN={$opts['listen']}");
	}

	/**
	 * @param array{bind:string} $opts
	 * @return void
	 */
	protected static function parseBind(array $opts): void {
		$host = $opts['bind'];
		if (false !== strpos($host, ':')) {
			[$host, $port] = explode(':', $host);
			putenv("BIND_PORT={$port}");
		}
		putenv("BIND_HOST={$host}");
	}

	/**
	 * @param array{skip:string|string[]} $opts
	 * @return string[]
	 */
	protected static function parseSkip(array $opts): array {
		return (array)$opts['skip'];
	}
}
