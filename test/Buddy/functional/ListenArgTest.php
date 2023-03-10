<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class ListenArgTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @var int $defaultPort
	 */
	protected int $defaultPort;

	protected function setUp(): void {
		$this->defaultPort = $this->getListenDefaultPort();
	}

	protected function tearDown(): void {
		// Restoring listen default port
		$this->setListenDefaultPort($this->defaultPort);
	}

	public function testListenArgumentChange(): void {
		echo "\nTesting if the `listen` argument is passed from daemon to Buddy correctly\n";
		$this->setListenDefaultPort(8888);
		$httpPort = self::getListenHttpPort();
		exec("curl localhost:$httpPort/sql?mode=raw -d 'query=drop table if exists test' 2>&1");
		$query = 'INSERT into test(col1) VALUES(1) ';
		exec("curl localhost:$httpPort/sql?mode=raw -d 'query=$query' 2>&1", $out);
		$result = '[{"total":1,"error":"","warning":""}]';
		$this->assertEquals($result, $out[3]);
	}

}
