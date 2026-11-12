<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Vector;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Node;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;

final class NodeTest extends \PHPUnit\Framework\TestCase {
	const TEST_MAP = [
		'localhost:9312' => 'localhost:9312',
		'dfsdfsdf' => null,
		'hello:world' => null,
		'domain.local:9312' => 'domain.local:9312',
		'127.0.0.1:9312' => '127.0.0.1:9312',
		'127.0.0.1:9306:mysql' => null,
		'127.0.0.1:9308:http' => '127.0.0.1:9308',
		'9333' => '127.0.0.1:9333',
		'9234:http' => '127.0.0.1:9234',
	];

	/**
	 * Validate we can parse valid lines from config to get Node ID
	 * @return void
	 */
	public function testNodeIdParsing(): void {
		echo "\nTesting the parsing of node id from line\n";
		$map = static::TEST_MAP;
		// Edge case when we should detect hostname
		$hostname = gethostname();
		$host = gethostbyname($hostname ?: '');
		$map['0.0.0.0:9552'] = "$host:9552";
		$map['0.0.0.0:9552:http'] = "$host:9552";
		foreach ($map as $line => $expected) {
			// PHP has a bug and converts string into ints when can
			$nodeId = Node::parseNodeId((string)$line);
			$this->assertEquals($expected, $nodeId);
		}
	}

	/**
	 * The daemon joins a cluster as node_address plus the API port,
	 * so node_address has to replace whatever host the listen line has
	 * @return void
	 */
	public function testNodeAddressReplacesListenHost(): void {
		echo "\nTesting that node_address replaces the listen host in node id\n";
		// A node in a Docker bridge network: listens on all interfaces, announces the host machine
		$bridgeListen = ['0.0.0.0:9312', '0.0.0.0:9360-9370:replication', '0.0.0.0:9306:mysql', '0.0.0.0:9308:http'];
		$this->assertEquals('192.168.111.35:9312', $this->findNodeId($bridgeListen, '192.168.111.35'));
		// Hostname is kept as written because the daemon does not resolve it either
		$this->assertEquals('node-a.example.com:9312', $this->findNodeId($bridgeListen, 'node-a.example.com'));
		$this->assertEquals('10.0.0.5:9312', $this->findNodeId(['127.0.0.1:9312'], '10.0.0.5'));
		$this->assertEquals('10.0.0.5:9312', $this->findNodeId(['9312'], '10.0.0.5'));
	}

	/**
	 * @return void
	 */
	public function testListenHostIsUsedWithoutNodeAddress(): void {
		echo "\nTesting that node id comes from the listen line when node_address is not set\n";
		$this->assertEquals('127.0.0.1:9312', $this->findNodeId(['127.0.0.1:9312'], null));
		// `node_address =` with no value reaches Buddy as an empty string and means not set
		$this->assertEquals('127.0.0.1:9312', $this->findNodeId(['127.0.0.1:9312'], ''));
	}

	/**
	 * Run Node::findId against settings built the way SHOW SETTINGS returns them
	 * @param array<string> $listen
	 * @param ?string $nodeAddress null when the config has no node_address line
	 * @return string
	 */
	private function findNodeId(array $listen, ?string $nodeAddress): string {
		/** @var Vector<array{key:string,value:mixed}> $rows */
		$rows = new Vector();
		foreach ($listen as $line) {
			$rows->push(['key' => 'searchd.listen', 'value' => $line]);
		}
		if ($nodeAddress !== null) {
			$rows->push(['key' => 'searchd.node_address', 'value' => $nodeAddress]);
		}
		$client = $this->createMock(Client::class);
		$client->method('getSettings')->willReturn(Settings::fromVector($rows));

		return Node::findId($client);
	}
}
