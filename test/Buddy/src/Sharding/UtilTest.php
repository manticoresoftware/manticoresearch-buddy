<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Set;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Util;
use PHPUnit\Framework\TestCase;

class UtilTest extends TestCase {

	/**
	 * Check and make sure that we able to consistently distribute on the same set of data
	 * @return void
	 */
	public function testCreateShardingSchemaConsistent(): void {
		$nodes = new Set(['1', '2', '3', '4', '5']);
		$shardCount = 60;
		$replicationFactor = 3;
		$schema = Util::createShardingSchema(
			$nodes,
			$shardCount,
			$replicationFactor
		);
		// phpcs:ignore Generic.Files.LineLength.MaxExceeded
		$this->assertEquals('[{"node":"1","shards":[0,1,3,5,8,9,10,12,13,15,16,19,20,22,23,25,28,29,30,31,34,35,38,39,40,42,43,45,46,49,50,52,54,55,58,59],"connections":["4","1","3"]},{"node":"2","shards":[1,2,4,6,7,9,10,11,14,15,17,19,21,23,24,25,26,28,31,32,34,35,37,39,40,41,43,46,47,48,51,52,53,55,56,58],"connections":["1","5","2"]},{"node":"3","shards":[0,2,4,5,7,8,11,12,13,16,17,18,20,22,24,26,27,28,31,32,33,36,37,38,40,42,44,46,48,49,50,53,54,55,57,59],"connections":["4","1","3"]},{"node":"4","shards":[1,2,3,5,6,8,11,12,14,16,18,19,20,21,23,25,27,29,30,32,33,35,36,39,41,42,44,45,47,49,51,52,53,56,57,59],"connections":["4","1","3"]},{"node":"5","shards":[0,3,4,6,7,9,10,13,14,15,17,18,21,22,24,26,27,29,30,33,34,36,37,38,41,43,44,45,47,48,50,51,54,56,57,58],"connections":["1","5","2"]}]', json_encode($schema));
	}

	/**
	 * Check the we distribute in balanced way and there is no maximum or minimum in nodes
	 * @return void
	 */
	public function testCreateShardingSchemaBalanced(): void {
		$nodes = new Set(['1', '2', '3', '4', '5']);
		$shardCount = 60;
		$replicationFactor = 3;
		$schema = Util::createShardingSchema(
			$nodes,
			$shardCount,
			$replicationFactor
		);
		$this->assertEquals($nodes->count(), sizeof($schema));
		foreach ($schema as $node) {
			$this->assertEquals(36, sizeof($node['shards']));
			$this->assertEquals(3, sizeof($node['connections']));
		}
	}
}
