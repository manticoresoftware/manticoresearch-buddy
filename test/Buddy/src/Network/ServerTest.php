<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Network\EventHandler;
use Manticoresearch\Buddy\Base\Network\Server;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Socket\SocketServer;
use parallel\Runtime;

class ServerTest extends TestCase {

	/**
	 * @var ?Server $server
	 */
	protected static $server = null;

	/**
	 * @var ?callable $onTearDown
	 */
	protected static $onTearDown = null;

	/**
	 * @return SocketServer
	 */
	protected function getSocket() {
		if (self::$server !== null) {
			$this->tearDown();
		}
		self::$server = Server::create();
		$refCls = new ReflectionClass(self::$server);
		$socket = $refCls->getProperty('socket')->getValue(self::$server);
		if (!is_object($socket) || !is_a($socket, SocketServer::class)) {
			$this->fail();
		}
		return $socket;
	}

	public function tearDown(): void {
		if (self::$onTearDown !== null) {
			$fn = self::$onTearDown;
			$fn();
		}
		if (self::$server === null) {
			return;
		}
		// Closing an open Server socket to let unitest finish properly
		$refCls = new ReflectionClass(self::$server);
		$socket = $refCls->getProperty('socket')->getValue(self::$server);
		if (!is_object($socket) || !is_a($socket, SocketServer::class)) {
			$this->fail();
		}
		$socket->close();
	}

	public function testServerCreate(): void {
		echo "\nTesting the creation of a Server instance \n";
		self::$server = Server::create();
		$this->assertInstanceOf(Server::class, self::$server);
	}

	public function testListenAddr(): void {
		echo "\nTesting the generation of a random listen address\n";
		$socket = $this->getSocket();
		$addr1 = $socket->getAddress();
		$socket = $this->getSocket();
		$addr2 = $socket->getAddress();
		$this->assertNotEquals($addr1, $addr2);
	}

	public function testServerOutput(): void {
		echo "\nTesting the starting output from Server\n";
		// Using flush here to not add unit test output to server output
		ob_flush();
		$socket = $this->getSocket();
		$addr = $socket->getAddress();
		if (self::$server === null || $addr === null) {
			$this->fail();
		}
		$addr = trim($addr, 'tcp://');
		$version = Buddy::getVersion();
		$this->expectOutputString("Buddy v{$version} started {$addr}\n");
		try {
			self::$server->start();
		} catch (Exception $e) {
		}
	}

	public function testServerTicker(): void {
		echo "\nTesting the execution of server tickers\n";
		$testFilepath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test.log';
		$task = Task::create(
			static function () use ($testFilepath) {
				$server = Server::create();
				$server->addTicker(
					static function () use ($testFilepath) {
						file_put_contents($testFilepath, 'Ok');
						Loop::stop();
					}, 1
				);

				try {
					$server->start();
				} catch (Exception) {
				}
			}
		);
		$task->run();
		sleep(2);

		$refCls = new ReflectionClass($task);
		$runtime = $refCls->getProperty('runtime')->getValue($task);
		if (!is_object($runtime)  || !is_a($runtime, Runtime::class)) {
			$this->fail();
		}
		$runtime->kill();

		$this->assertEquals('Ok', file_get_contents($testFilepath));
		unlink($testFilepath);
	}

	public function testClientTicker(): void {
		echo "\nTesting the execution of client tickers\n";
		$this->serverTestHelper('ticker');
	}

	public function testClientHandler(): void {
		echo "\nTesting the execution of server handlers\n";
		$this->serverTestHelper('handler');
	}

	/**
	 * Helper function for the testing of Server connection
	 *
	 * @param string $testTarget
	 * @return void
	 */
	protected function serverTestHelper(string $testTarget): void {
		$testFilepath = getcwd() . DIRECTORY_SEPARATOR . 'test.log';
		$fn = function () use ($testFilepath) {
			file_put_contents($testFilepath, 'Ok');
			Loop::stop();
		};

		$task = Task::create(
			function () use ($fn, $testTarget): TaskResult {
				$server = Server::create();
				$server->addHandler('request', EventHandler::request(...));
				switch ($testTarget) {
					case 'ticker':
						$server->addTicker($fn, 1, 'client');
						break;
					case 'handler':
						$server->addHandler('close', $fn);
						break;
					default:
						break;
				}
				$server->start();

				$refCls = new ReflectionClass($server);
				$socket = $refCls->getProperty('socket')->getValue($server);
				if (!is_object($socket) || !is_a($socket, SocketServer::class)) {
					return TaskResult::raw('error');
				}
				$addr = $socket->getAddress();
				if ($addr === null) {
					return TaskResult::raw('error');
				}
				$addr = trim($addr, 'tcp://');
				[$host, $port] = explode(':', $addr);
				$clientSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
				if ($clientSocket === false) {
					return TaskResult::raw('error');
				}
				socket_connect($clientSocket, $host, (int)$port);
				socket_close($clientSocket);
				return TaskResult::raw('ok');
			}
		);
		$task->run();
		sleep(1);
		$this->assertEquals(true, $task->isSucceed());
		$this->assertEquals('ok', $task->getResult()->getStruct());

		// Workaround to get response from server before the current unit test finishes
		self::$onTearDown = function () use ($testFilepath) {
			$this->assertEquals('Ok', file_get_contents($testFilepath));
			unlink($testFilepath);
		};
	}

}
