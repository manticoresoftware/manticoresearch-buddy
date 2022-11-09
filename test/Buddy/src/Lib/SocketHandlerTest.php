<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Exception\SocketError;
use Manticoresearch\Buddy\Lib\SocketHandler;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class SocketHandlerTest extends TestCase {

	use TestProtectedTrait;

	protected static SocketHandler $socketHandler;
	//private static object $mockSocketClient;

	/**
	 * @param string $msg
	 * @return void
	 */
	protected static function mockSocketClient(string $msg = ''): void {
		$clSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($clSocket === false) {
			return;
		}
		socket_set_nonblock($clSocket);
		socket_connect($clSocket, self::$socketHandler->addr, self::$socketHandler->port);
		socket_write($clSocket, $msg, strlen($msg));
		socket_close($clSocket);
	}

	public function testSocketHandlerCreateOk(): void {
		echo "\nCreating SocketHandler instance\n";
		self::$socketHandler = new SocketHandler('127.0.0.1', 0);
		$this->assertInstanceOf(SocketHandler::class, self::$socketHandler);
	}

	public function testSocketHandlerCreateFail(): void {
		echo "\nTesting the instantiating of SocketHandler instance with unvalid address\n";
		$this->expectException(SocketError::class);
		$this->expectExceptionMessage('Cannot bind to the random port 0 at addr 777.0.0.1');
		new SocketHandler('777.0.0.1', 0);
	}

	public function testSocketHandlerReceive(): void {
		echo "\nTesting SocketHandler instance receiving messages\n";
		echo "\n<Receiving no message from socket...>\n";
		$hasMsg = self::$socketHandler->hasMsg();
		$this->assertEquals(false, $hasMsg);

		echo "\n<Receiving message from socket...>\n";
		$msg = 'Test message';
		self::mockSocketClient($msg);
		$hasMsg = self::$socketHandler->hasMsg();
		$this->assertEquals(true, $hasMsg);

		echo "\n<Reading mock data from socket...>\n";
		$this->assertEquals($msg, self::$socketHandler->read());
	}

	public function testSocketHandlerReceiveJSON(): void {
		echo "\nReading mock client message in JSON format from socket\n";
		$msg = "\n\n{\n\"index\":\"products\",\n\"id\":1,\n\"doc\":\n\t{\n\"title\" : "
			. "\"Crossbody Bag with Tassel\",\n\"price\" : 19.85\n\t}\n}";
		$msgJSON = json_decode($msg, true);
		$msg = "testHeader$msg";
		self::mockSocketClient($msg);

		$hasMsg = self::$socketHandler->hasMsg();
		$this->assertEquals(true, $hasMsg);
		$this->assertEquals($msgJSON, self::$socketHandler->readMsg());
	}

}
