<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Network\Server;
use PHPUnit\Framework\TestCase;
use \ReflectionClass;

class ServerTest extends TestCase {

	public function testServerCreate(): void {
		echo "\nTesting the creation of a Server instance \n";
		$server = Server::create();
		$this->assertInstanceOf(Server::class, $server);
	}

	public function testListenAddr(): void {
		echo "\nTesting the generation of a random listen address\n";
		$server = Server::create();
		$refCls = new ReflectionClass($server);
		$socket = $refCls->getProperty('socket')->getValue($server);
		$addr1 = $socket->getAddress();

		$server = Server::create();
		$refCls = new ReflectionClass($server);
		$socket = $refCls->getProperty('socket')->getValue($server);
		$addr2 = $socket->getAddress();

		$this->assertNotEquals($addr1, $addr2);
	}
}
