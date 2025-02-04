<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Sharding\Node;

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
}
