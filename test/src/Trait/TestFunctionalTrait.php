<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Trait;

use \Exception;

trait TestFunctionalTrait {

	/**
	 * @var int $listenDefaultPort
	 */
	protected static ?int $listenDefaultPort = null;
	/**
	 * @var int $listenSqlPort
	 */
	protected static ?int $listenSqlPort = null;
	/**
	 * @var int $listenHttpPort
	 */
	protected static ?int $listenHttpPort = null;
	/**
	 * @var string $manticoreConfigFile
	 */
	protected static string $manticoreConfigFile = '';
	/**
	 * @var string $manticoreConf
	 */
	protected static string $manticoreConf = '';
	/**
	 * @var int $manticorePid
	 */
	protected static int $manticorePid = 0;
	/**
	 * @var int $buddyPid
	 */
	protected static int $buddyPid = 0;

	/**
	 * Launch daemon as as setup stage
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		// Getting the absolute path to the Manticore config file
		$refCls = new \ReflectionClass(static::class);
		self::$manticoreConfigFile = dirname((string)$refCls->getFileName()) . '/config/manticore.conf';

		self::setConfWithBuddyPath();
		self::checkManticorePathes();
		system('searchd --config ' . self::$manticoreConfigFile);
		self::$manticorePid = (int)trim((string)file_get_contents('/var/run/manticore-test/searchd.pid'));
		sleep(2); // <- give 2 secs to protect from any kind of lags
		self::loadBuddyPid();
	}

	/**
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		system('pkill -9 searchd');
		// To be sure run again kills for each pid
		system('kill -9 ' . self::$manticorePid . ' 2> /dev/null');
		system('kill -9 ' . self::$buddyPid . ' 2> /dev/null');
	}

	/**
	 * Get a default port Manticore daemon is listening at
	 *
	 * @return int
	 */
	public static function getListenDefaultPort(): int {
		return self::getListenPort('listenDefaultPort');
	}

	/**
	 * Get a http port Manticore daemon is listening at
	 *
	 * @return int
	 */
	public static function getListenHttpPort(): int {
		return self::getListenPort('listenHttpPort', ':http');
	}

	/**
	 * Get a mysql port Manticore daemon is listening at
	 *
	 * @return int
	 */
	public static function getListenSqlPort(): int {
		return self::getListenPort('listenSqlPort', ':mysql');
	}

	/**
	 * Sets a default listen port Manticore daemon is listening at
	 *
	 * @param int $port
	 * @return void
	 */
	public static function setListenDefaultPort(int $port): void {
		self::$listenDefaultPort = $port;
		$replRegex = '/(listen = [^:]+:?)(\d+)(\r|\n)/';
		$conf = preg_replace_callback($replRegex, fn($m) => $m[1] . (string)$port . $m[3], self::$manticoreConf);
		self::updateManticoreConf((string)$conf);
	}

	/**
	 * Helper that gets the current value of a Manticore's listen port
	 * and sets it if the port has not yet been defined
	 *
	 * @param string $portType
	 * @param string $portDiscriminator
	 * @return int
	 */
	protected static function getListenPort(string $portType, string $portDiscriminator = ''): int {
		if (!property_exists(self::class, $portType)) {
			throw new Exception("$portType property does not exist");
		}
		if (isset(self::$$portType)) {
			return self::$$portType;
		}
		$matches = [];
		// Getting the port value from the corresponding config line
		preg_match("/listen = ([^:]*?):?([^:]+?)$portDiscriminator(\r|\n)/", self::$manticoreConf, $matches);
		if (!isset($matches[2])) {
			throw new Exception("$portType is not set in Manticore config");
		}
		self::$$portType = (int)$matches[2];
		return self::$$portType;
	}

	/**
	 * Helper that checks if the pathes defined in the manticore config exist
	 * and tries to create them if not
	 *
	 * @return void
	 */
	protected static function checkManticorePathes(): void {
		$propNames = ['log', 'query_log', 'pid_file', 'data_dir'];
		foreach ($propNames as $prop) {
			// Getting the value of the option being checked from the corresponding config line
			preg_match("/$prop = (.*?)(\/[^\/\r\n]*|)(\r|\n)/", self::$manticoreConf, $matches);
			if (!isset($matches[1], $matches[2])) {
				continue;
			}
			$checkDir = ($prop === 'data_dir') ? $matches[1] . $matches[2] : $matches[1];
			if (is_dir($checkDir)) {
				continue;
			}
			// Trying to create a dir needed by Manticore if it does not exist
			system("mkdir $checkDir 2>/dev/null", $res);
			if ($res !== 0) {
				throw new Exception("Cannot create Manticore `$prop` dir at $checkDir");
			}
		}
	}

	/**
	 * Helper that sets the `buddy_path` config option relative to the current Buddy root folder
	 *
	 * @return void
	 */
	protected static function setConfWithBuddyPath(): void {
		$buddyPath = __DIR__ . '/../../..';
		$configFile = self::$manticoreConfigFile;
		$conf = file_get_contents($configFile);
		if ($conf === false) {
			throw new Exception("Unvalid Manticore config found at $configFile");
		}
		$conf = str_replace('%BUDDY%', $buddyPath, $conf);
		self::updateManticoreConf($conf);
	}

	/**
	 * @param string $conf
	 *
	 * @return void
	 * @throws Exception
	 */
	protected static function updateManticoreConf($conf): void {
		$isConfUpdated = file_put_contents(self::$manticoreConfigFile, $conf);
		if ($isConfUpdated === false) {
			throw new Exception('Cannot update Manticore config at ' . self::$manticoreConfigFile);
		}
		self::$manticoreConf = $conf;
	}

	/**
	 * Helper that allows us to reload fresh pid for relaunched
	 * Buddy process by the Manticore
	 * @return void
	 */
	protected static function loadBuddyPid(): void {
		exec('ps --ppid ' . self::$manticorePid, $psOut);
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
			throw new Exception('Failed to find children pids for ' . self::$manticorePid);
		}
		self::$buddyPid = $pids[0];
	}

	/**
	 * Helper that checks if curl is installed on the current machine
	 *
	 * @return bool
	 */
	protected static function hasCurl(): bool {
		$out = [];
		exec('whereis curl', $out);
		return $out[0] !== 'curl:';
	}

	/**
	 * Helper that checks if MySQL is installed on the current machine
	 *
	 * @return bool
	 */
	protected static function hasMySQL(): bool {
		$out = [];
		exec('whereis mysql', $out);
		return $out[0] !== 'mysql:';
	}
}
