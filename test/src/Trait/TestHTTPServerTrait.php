<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Trait;

use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Exception\SocketError;
use Manticoresearch\BuddyTest\Lib\MockManticoreServer;
use ReflectionClass;
use RuntimeException;

trait TestHTTPServerTrait {

	/**
	 * @var mixed $mockServerProc
	 */
	protected static mixed $mockServerProc = false;

	/**
	 * @var string $mockServerUrl
	 */
	protected static string $mockServerUrl = '';

	/**
	 * @var bool $isMockServerInErrorMode
	 */
	protected static bool $isMockServerInErrorMode = false;

	/**
	 * @param string $addr
	 * @return int
	 */
	protected static function checkServerPortAvailable(string $addr): int {
		$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($socket === false) {
			throw new RuntimeException('Cannot create socket');
		}
		$port = 0;
		if (!socket_bind($socket, $addr, $port)	|| !socket_getsockname($socket, $addr, $port)) {
			throw new RuntimeException('Cannot bind to the socket');
		}
		socket_close($socket);

		return $port;
	}

	/**
	 * Makes php script to run mock server in a separate process
	 * @return string
	 */
	protected static function makeStartupScript(): string {
		$requiredClasses = [MntEndpoint::class, SocketError::class, MockManticoreServer::class];
		$requireCode = implode(
			PHP_EOL,
			array_map(
				function ($cls) {
					$reflCls = new ReflectionClass($cls);
					$fileName = $reflCls->getFileName();
					return 'require "' . $fileName . '";';
				},
				$requiredClasses
			)
		);
		$argExpr = '"' . self::$mockServerUrl . '",' . self::$isMockServerInErrorMode;
		$scriptCode = "$requireCode\n";
		$scriptCode .= '$server = new Manticoresearch\BuddyTest\Lib\MockManticoreServer(' . $argExpr . ');' . "\n";
		$scriptCode .= '$server->start();';

		return $scriptCode;
	}

	/**
	 * @return void
	 */
	protected static function createMockMntServer(): void {
		self::finishMockMntServer();

		$addr = '127.0.0.1';
		$port = self::checkServerPortAvailable($addr);
		if ($port === 0) {
			self::$mockServerUrl = '';
		} else {
			self::$mockServerUrl = "$addr:$port";
			$scriptCode = self::makeStartupScript();
			$descrs = $pipes = [];
			self::$mockServerProc = proc_open("php -r '$scriptCode'", $descrs, $pipes);
			if (self::$mockServerProc === false) {
				self::$mockServerUrl = '';
			}
			sleep(1);
		}
	}

	/**
	 * @return void
	 */
	public static function finishMockMntServer(): void {
		if (self::$mockServerProc === false || !is_resource(self::$mockServerProc)) {
			return;
		}
		proc_terminate(self::$mockServerProc);
		self::$mockServerProc = false;
	}

	/**
	 * * Sets if mock server should return incorrect response
	 *
	 * @param bool $isMockServerInErrorMode
	 * @return string
	 */
	public static function setUpMockMntServer(
		bool $isMockServerInErrorMode = false
		//string $testFailMsg = 'Mock Manticore server cannot be created'
	): string {
		self::$isMockServerInErrorMode = $isMockServerInErrorMode;
		self::createMockMntServer();
// 		if ($mockServerUrl === '') {
// 			return $this->fail($testFailMsg);
// 		}
		return self::$mockServerUrl;
	}

}
