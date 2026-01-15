<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Trait;

use Exception;
use Manticoresearch\Buddy\Core\Tool\Buddy;

trait TestFunctionalTrait {
	protected static string $authUser = 'root';
	protected static string $authPassword = 'root';

	protected static function isAuthEnabled(): bool {
		return str_contains(static::$manticoreConf, 'auth = 1');
	}

	/**
	 * @var ?int $listenDefaultPort
	 */
	protected static ?int $listenDefaultPort = null;
	/**
	 * @var ?int $listenSqlPort
	 */
	protected static ?int $listenSqlPort = null;
	/**
	 * @var ?int $listenHttpPort
	 */
	protected static ?int $listenHttpPort = null;

	/**
	 * @var ?int $listenBuddyPort
	 */
	protected static ?int $listenBuddyPort = null;

	/**
	 * @var string $manticoreConfigFilePath
	 */
	protected static string $manticoreConfigFilePath = '';
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

	/** @var string $configFileName */
	protected static string $configFileName = 'manticore.conf';

	/**
	 * Override in a functional test class to use a different config template.
	 *
	 * @return string
	 */
	protected static function getConfigFileName(): string {
		return static::$configFileName;
	}

	/** @var array<string> $searchdArgs Additional arguments to pass to searchd via buddy_path */
	protected static array $searchdArgs = [];

	/**
	 * Launch daemon as as setup stage
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		// Setting the absolute path to the Manticore config file
		if (static::$manticoreConf === '' || static::$manticoreConfigFilePath === '') {
			self::setManticoreConfigFile(static::getConfigFileName());
		}

		self::setConfWithBuddyPath();
		self::applySearchdArgs();
		self::checkManticorePathes();
		if (self::isAuthEnabled()) {
			self::ensureAuthJson();
		}
		system('rm -f /var/run/manticore-test/searchd.pid');
		system('rm -f /var/log/manticore-test/searchd.pid');
		system('rm -f /var/log/manticore-test/searchd.log');
		self::startSearchdOrFail();
		self::$manticorePid = (int)trim((string)file_get_contents('/var/run/manticore-test/searchd.pid'));
		sleep(5); // <- give 5 secs to protect from any kind of lags

		static::$listenBuddyPort = (int)system("ss -nlp | grep 'manticore-execu' | cut -d: -f2 | cut -d' ' -f1");
		static::loadBuddyPid();

		// Clean up all tables and run fresh instance
		$output = static::runSqlQuery('show tables');
		if (sizeof($output) > 1) {
			array_shift($output);
			foreach ($output as $line) {
				$table = trim(str_replace(['rt','plain', 'distributed', 'percolate'], '', $line), ' |+-');
				if (!$table || strpos($table, '|')) {
					continue;
				}

				static::runSqlQuery("DROP TABLE $table");
			}
		}
		sleep(1);
	}

	/**
	 * @return void
	 * @throws Exception
	 */
	private static function startSearchdOrFail(): void {
		$cmd = 'searchd --config ' . static::$manticoreConfigFilePath;
		$output = [];
		exec($cmd . ' 2>&1', $output, $exitCode);

		if ($exitCode === 0) {
			return;
		}

		$all = implode("\n", $output);
		throw new Exception("Failed to start searchd:\n" . $all);
	}

	/**
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void {
		system('pgrep -f searchd | xargs kill -9');
		system('pgrep -f manticore-executor | xargs kill -9 2> /dev/null');
		// system('kill -9 ' . self::$manticorePid . ' 2> /dev/null');
		// system('kill -9 ' . self::$buddyPid . ' 2> /dev/null');
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
		$conf = preg_replace_callback($replRegex, fn($m) => $m[1] . (string)$port . $m[3], static::$manticoreConf);
		self::updateManticoreConf((string)$conf);
	}
	/**
	 * Sets additional arguments to pass to searchd via buddy_path
	 *
	 * @param array<string> $args
	 * @return void
	 */
	public static function setSearchdArgs(array $args): void {
		static::$searchdArgs = $args;
	}

	/**
	 * Helper that creates a config file from specified config template file
	 * to avoid the possible modifications of the original config file
	 *
	 * @param string $configTplFileName
	 * @return void
	 */
	protected static function setManticoreConfigFile(string $configTplFileName): void {
		$refCls = new \ReflectionClass(static::class);
		$random = bin2hex(random_bytes(8));
		$configTplFilePath = dirname((string)$refCls->getFileName()) . "/config/$configTplFileName";
		static::$manticoreConfigFilePath = \sys_get_temp_dir() . "/config-$random-$configTplFileName";
		system("cp -r $configTplFilePath " . static::$manticoreConfigFilePath, $res);
		if ($res !== 0) {
			throw new Exception('Cannot create Manticore config file at `' . static::$manticoreConfigFilePath . '`');
		}
		static::$manticoreConf = (string)file_get_contents(static::$manticoreConfigFilePath);
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
		preg_match("/listen = ([^:]*?):?([^:]+?)$portDiscriminator(\r|\n)/", static::$manticoreConf, $matches);
		if (!isset($matches[2])) {
			throw new Exception("$portType is not set in Manticore config");
		}
		self::$$portType = (int)$matches[2];
		return self::$$portType;
	}

	/**
	 * This is helper to validate error in response by running both sql and cli interfaces
	 *
	 * @param string $query
	 * @param string $error
	 * @return void
	 * @throws Exception
	 */
	protected function assertQueryResultContainsError(string $query, string $error): void {
		$result1 = static::runSqlQuery($query);
		// TODO: dirty hack for backup but until we fix it
		if (str_starts_with($query, 'backup')) {
			sleep(1);
		}
		$result2 = static::runHttpQuery($query);

		$this->assertStringContainsString($error, implode(PHP_EOL, $result1));
		$this->assertEquals($error, $result2['error']);
	}

	/**
	 * Same as error checker but for asserts OK results
	 *
	 * @param string $query
	 * @param string|string[] $contains
	 * @param string|string[] $excludes
	 * @return void
	 * @throws Exception
	 */
	protected function assertQueryResult(
		string $query,
		string|array $contains = [],
		string|array $excludes = []
	): void {
		if (is_string($contains)) {
			$contains = [$contains];
		}

		if (is_string($excludes)) {
			$excludes = [$excludes];
		}

		$result1 = static::runSqlQuery($query, false, '\G');
		// TODO: dirty hack for backup but until we fix it
		if (str_starts_with($query, 'backup')) {
			sleep(1);
		}
		foreach ($contains as $string) {
			$this->assertStringContainsString($string, implode(PHP_EOL, $result1));
		}

		foreach ($excludes as $string) {
			$this->assertStringNotContainsString($string, implode(PHP_EOL, $result1));
		}

		$result2 = static::runHttpQuery($query);
		$output = '';
		if (isset($result2[0]['data'])) {
			$i = 1;
			foreach ($result2[0]['data'] as &$v) {
				$output .= "*************************** $i. row ***************************\n";
				foreach ($v as $key => $val) {
					$output .= "$key: $val\n";
				}
				++$i;
			}
		}

		foreach ($contains as $string) {
			$this->assertStringContainsString($string, trim($output));
		}

		foreach ($excludes as $string) {
			$this->assertStringNotContainsString($string, trim($output));
		}
	}

	/**
	 * Run sql query on test manticore instance
	 *
	 * @param string $query
	 * @param bool $redirectOutput
	 * @param string $delimeter
	 * @return array<string>
	 * @throws Exception
	 */
	protected static function runSqlQuery(string $query, bool $redirectOutput = true, string $delimeter = ';'): array {
		$port = static::getListenSqlPort();
		// We use temporarely file just to skip issues with escaping post data in command line arg
		$payloadFile = \sys_get_temp_dir() . '/payload-' . uniqid() . '.data';
		file_put_contents($payloadFile, $query . $delimeter);

		$redirect = $redirectOutput ? '2>&1' : '';
		$auth = static::isAuthEnabled() ? '-u' . static::$authUser . ' -p' . static::$authPassword : '';
		exec("mysql -P$port -h127.0.0.1 $auth < $payloadFile $redirect", $output);
		return $output;
	}

	/**
	 * Run HTTP query to manticore search
	 *
	 * @param string $query
	 * @param bool $redirectOutput
	 * @param string $path
	 * @param bool $includeHeaders Include HTTP response headers in result
	 * @return array{error:string}|array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string,headers?:string}>
	 * @throws Exception
	 */
	protected static function runHttpQuery(
		string $query,
		bool $redirectOutput = true,
		string $path = 'sql?mode=raw',
		bool $includeHeaders = false
	): array {
		$port = static::getListenHttpPort();
		$isSql = str_starts_with(ltrim($path, '/'), 'sql');
		// We use temporarely file just to skip issues with escaping post data in command line arg
		$payloadFile = \sys_get_temp_dir() . '/payload-' . uniqid() . '.data';
		file_put_contents($payloadFile, $isSql ? 'query=' . rawurlencode($query) : $query);
		$redirect = $redirectOutput ? '2>&1' : '';
		$header = ($path === 'bulk' || $path === '_bulk')
			? 'Content-type: application/x-ndjson'
			: ($isSql
				? 'Content-type: application/x-www-form-urlencoded'
				: 'Content-type: application/json'
			);

		$curlFlags = $includeHeaders ? '-is' : '-s';
		$auth = static::isAuthEnabled() ? '-u ' . static::$authUser . ':' . static::$authPassword : '';
		$command = "curl $curlFlags $auth 127.0.0.1:$port/$path -H '$header' --data-binary @$payloadFile $redirect";
		echo 'Commmand: ' . $command . PHP_EOL;
		exec($command, $output);

		$headers = '';
		$statusCode = 200;
		if ($includeHeaders) {
			$response = implode("\n", $output);
			$parts = explode("\n\n", $response, 2);
			$headers = $parts[0] ?? '';
			$output = explode("\n", $parts[1] ?? '');

			// Extract status code from first line of headers
			$headerLines = explode("\n", $headers);
			if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $headerLines[0] ?? '', $matches)) {
				$statusCode = (int)$matches[1];
			}
		}

		/** @var array{error:string}|array<int,array{error:string,data:array<int,array<string,string>>,total?:string,columns?:string,headers?:string}> $result */
		$result = match ($path) {
			'cli_json', 'sql', 'sql?mode=raw' => (array)simdjson_decode(implode(PHP_EOL, $output), true),
			'cli' => [
				['columns' => implode(PHP_EOL, $output), 'data' => [], 'error' => ''],
			],
			'metrics' => [
				['data' => implode(PHP_EOL, $output), 'error' => ''],
			],
			// assuming Elastic-like endpoint is passed
			default => [
				['data' => [(array)simdjson_decode($output[0] ?? '{}', true)], 'error' => ''],
			],
		};

		if ($includeHeaders && isset($result[0])) {
			/** @phpstan-ignore-next-line */
			$result[0]['headers'] = $headers;
			/** @phpstan-ignore-next-line */
			$result[0]['status_code'] = $statusCode;
		}

		print_r($output);
		unlink($payloadFile);
		return $result;
	}


	/**
	 * Run direct HTTP request to the Buddy
	 *
	 * @param string $query
	 * @param array{message:string} $error
	 * @param bool $redirectOutput
	 * @return array{version:int,type:string,log?:array<int,array{type:string,severity:string,message:string}>,
	 *   message:array<int,array{columns:array<string>,data:array<int,array<string,string>>}>}
	 * @throws Exception
	 */
	protected static function runHttpBuddyRequest(
		string $query,
		array $error = ['message' => ''],
		bool $redirectOutput = true
	): array {
		$port = static::$listenBuddyPort;
		$request = [
			'type' => 'unknown json request',
			'error' => $error,
			'version' => Buddy::PROTOCOL_VERSION,
			'message' => [
				'path_query' => '/sql?mode=raw',
				'body' => $query,
			],
		];
		$payloadFile = \sys_get_temp_dir() . '/payload-' . uniqid() . '.json';
		file_put_contents($payloadFile, json_encode($request));
		$redirect = $redirectOutput ? '2>&1' : '';
		exec("curl -s 127.0.0.1:$port -H 'Content-type: application/json' -d @$payloadFile $redirect", $output);
		/** @var array{version:int,type:string,message:array<int,array{columns:array<string>,data:array<int,array<string,string>>}>} $result */
		$result = (array)simdjson_decode($output[0] ?? '{}', true);
		return $result;
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
			preg_match("/$prop = (.*?)(\/[^\/\r\n]*|)(\r|\n)/", static::$manticoreConf, $matches);
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
	 * Ensure auth.json exists for auth=1 mode in functional tests.
	 *
	 * Manticore expects `/var/lib/manticore/auth.json` to exist (generated normally from CREATE USER),
	 * but functional tests run a fresh daemon instance, so we seed it from the existing container file.
	 *
	 * @return void
	 */
	protected static function ensureAuthJson(): void {
		$source = '/var/lib/manticore/auth.json';
		$targetDir = '/var/lib/manticore';
		$target = $targetDir . '/auth.json';
		$testTargetDir = '/var/lib/manticore-test';
		$testTarget = $testTargetDir . '/auth.json';

		if (!is_file($source)) {
			if (!is_dir($targetDir)) {
				system("mkdir $targetDir 2>/dev/null", $res);
				if ($res !== 0) {
					throw new Exception("Cannot create $targetDir");
				}
			}

			$salt = bin2hex(random_bytes(20));
			$password = static::$authPassword;
			$token = bin2hex(random_bytes(16));
			$saltBytes = hex2bin($salt);
			if ($saltBytes === false) {
				throw new Exception('Failed to generate auth salt.');
			}
			$hashes = [
				'password_sha1_no_salt' => sha1($password),
				'password_sha256' => hash('sha256', $saltBytes . $password),
				'bearer_sha256' => hash('sha256', $saltBytes . hash('sha256', $token)),
			];

			$authJson = json_encode(
				[
					'users' => [
						[
							'username' => static::$authUser,
							'salt' => $salt,
							'hashes' => $hashes,
						],
					],
				],
				JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE
			);
			file_put_contents($source, $authJson);
			chmod($source, 0600);
		}

		if (!is_dir($targetDir)) {
			system("mkdir $targetDir 2>/dev/null", $res);
			if ($res !== 0) {
				throw new Exception("Cannot create $targetDir");
			}
		}

		copy($source, $target);
		chmod($target, 0600);

		if (!is_dir($testTargetDir)) {
			system("mkdir $testTargetDir 2>/dev/null", $res);
			if ($res !== 0) {
				throw new Exception("Cannot create $testTargetDir");
			}
		}

		copy($source, $testTarget);
		chmod($testTarget, 0600);
	}

	/**
	 * Helper that sets the `buddy_path` config option relative to the current Buddy root folder
	 *
	 * @return void
	 */
	protected static function setConfWithBuddyPath(): void {
		$buddyPath = __DIR__ . '/../../..';
		$configFile = static::$manticoreConfigFilePath;
		$conf = file_get_contents($configFile);
		if ($conf === false) {
			throw new Exception("Invalid Manticore config found at $configFile");
		}
		$conf = str_replace('%BUDDY%', $buddyPath, $conf);
		self::updateManticoreConf((string)$conf);
	}

	/**
	 * Helper that applies additional searchd arguments to the buddy_path config option
	 *
	 * @return void
	 */
	protected static function applySearchdArgs(): void {
		if (empty(static::$searchdArgs)) {
			return;
		}

		$additionalArgs = implode(' ', static::$searchdArgs);
		$conf = static::$manticoreConf;

		// Find the buddy_path line and append additional arguments
		$conf = preg_replace(
			'/(buddy_path = manticore-executor [^\r\n]+)(\r|\n)/',
			'$1 ' . $additionalArgs . '$2',
			$conf
		);

		self::updateManticoreConf((string)$conf);
	}

	/**
	 * @param string $conf
	 *
	 * @return void
	 * @throws Exception
	 */
	protected static function updateManticoreConf($conf): void {
		$isConfUpdated = file_put_contents(static::$manticoreConfigFilePath, $conf);
		if ($isConfUpdated === false) {
			throw new Exception('Cannot update Manticore config at ' . static::$manticoreConfigFilePath);
		}
		static::$manticoreConf = $conf;
	}

	/**
	 * Helper to load Buddy pid
	 * @return void
	 */
	protected static function loadBuddyPid(): void {
		static::$buddyPid = (int)shell_exec('pgrep -f manticoresearch-buddy-manager');
	}
}
