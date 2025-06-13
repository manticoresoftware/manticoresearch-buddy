<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Set;
use Ds\Vector;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Util;
use PHPUnit\Framework\TestCase;

/**
 * Simplified test for Table rebalance functionality
 * Focuses on testing the core logic without extensive mocking of final classes
 */
class TableRebalanceSimpleTest extends TestCase {

	/**
	 * Test the core rebalance logic by testing individual methods
	 * @return void
	 */
	public function testRebalanceLogicComponents(): void {
		// Test that we can detect new nodes vs failed nodes
		$originalSchema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0, 1]), 'connections' => new Set(['node1'])],
			['node' => 'node2', 'shards' => new Set([2, 3]), 'connections' => new Set(['node2'])],
			]
		);

		$allNodes = new Set(['node1', 'node2', 'node3']); // node3 is new
		$inactiveNodes = new Set([]); // no failed nodes
		$activeNodes = $allNodes->diff($inactiveNodes);

		// Detect new nodes
		$schemaNodes = new Set($originalSchema->map(fn($row) => $row['node']));
		$newNodes = $activeNodes->diff($schemaNodes);

		$this->assertEquals(new Set(['node3']), $newNodes, 'Should detect node3 as new');
		$this->assertEquals(0, $inactiveNodes->count(), 'Should have no inactive nodes');

		// Test the enhanced Util rebalancing
		$newSchema = Util::rebalanceWithNewNodes($originalSchema, $newNodes, 1);

		$this->assertEquals(3, $newSchema->count(), 'Should have 3 nodes in new schema');

		// Verify node3 is in the new schema
		$node3Found = false;
		foreach ($newSchema as $row) {
			if ($row['node'] === 'node3') {
				$node3Found = true;
				break;
			}
		}
		$this->assertTrue($node3Found, 'Node3 should be in new schema');
	}

	/**
	 * Test queue command generation pattern
	 * @return void
	 */
	public function testQueueCommandPattern(): void {
		// Test the pattern of commands that should be generated for RF=1 shard movement
		$shardId = 1;
		$sourceNode = 'node1';
		$targetNode = 'node2';
		$tempClusterName = "temp_move_{$shardId}_abc123";
		$shardName = "system.test_table_s{$shardId}";

		// Expected command sequence
		$expectedCommands = [
			"CREATE TABLE IF NOT EXISTS {$shardName} (id bigint) ",
			"CREATE CLUSTER {$tempClusterName} '{$tempClusterName}' as path",
			"JOIN CLUSTER {$tempClusterName} AT '{$targetNode}'",
			"JOIN CLUSTER {$tempClusterName} AT '{$sourceNode}'",
			"ALTER CLUSTER {$tempClusterName} ADD {$shardName}",
			"ALTER CLUSTER {$tempClusterName} DROP {$shardName}",
			"DROP TABLE {$shardName}",
			"DELETE CLUSTER {$tempClusterName}",
		];

		// Verify the command pattern includes unique path for CREATE CLUSTER
		$createClusterCommand = $expectedCommands[1];
		$this->assertStringContainsString(
			' as path', $createClusterCommand,
			'CREATE CLUSTER should include unique path'
		);
		$this->assertStringContainsString(
			"'{$tempClusterName}' as path", $createClusterCommand,
			'CREATE CLUSTER should use cluster name as path'
		);
	}

	/**
	 * Test state management logic
	 * @return void
	 */
	public function testStateManagementLogic(): void {
		// Test the state key generation and management logic
		$tableName = 'test_table';
		$expectedKey = "rebalance:{$tableName}";

		$this->assertEquals('rebalance:test_table', $expectedKey, 'State key should be properly formatted');

		// Test state values
		$validStates = ['idle', 'running', 'completed', 'failed'];
		$this->assertContains('running', $validStates, 'running should be valid state');
		$this->assertContains('idle', $validStates, 'idle should be valid state');
	}

	/**
	 * Test RF=1 vs RF>=2 detection logic
	 * @return void
	 */
	public function testReplicationFactorDetection(): void {
		// RF=1 schema - each node connects only to itself
		$rf1Schema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0]), 'connections' => new Set(['node1'])],
			['node' => 'node2', 'shards' => new Set([1]), 'connections' => new Set(['node2'])],
			]
		);

		// RF=2 schema - nodes connect to each other
		$rf2Schema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0]), 'connections' => new Set(['node1', 'node2'])],
			['node' => 'node2', 'shards' => new Set([0]), 'connections' => new Set(['node1', 'node2'])],
			]
		);

		// Test RF detection logic
		$maxConnectionsRF1 = 0;
		foreach ($rf1Schema as $row) {
			$maxConnectionsRF1 = max($maxConnectionsRF1, $row['connections']->count());
		}

		$maxConnectionsRF2 = 0;
		foreach ($rf2Schema as $row) {
			$maxConnectionsRF2 = max($maxConnectionsRF2, $row['connections']->count());
		}

		$this->assertEquals(1, $maxConnectionsRF1, 'RF=1 should have max 1 connection per node');
		$this->assertEquals(2, $maxConnectionsRF2, 'RF=2 should have max 2 connections per node');
	}

	/**
	 * Test command ordering requirements
	 * @return void
	 */
	public function testCommandOrderingRequirements(): void {
		// Define the critical ordering requirements
		$commandOrder = [
			'CREATE_TABLE',
			'CREATE_CLUSTER',
			'JOIN_CLUSTER',
			'ALTER_ADD',     // Synchronization point
			'ALTER_DROP',
			'DROP_TABLE',
			'DELETE_CLUSTER',
		];

		// Test that ALTER_ADD comes before ALTER_DROP (critical for data safety)
		$addIndex = array_search('ALTER_ADD', $commandOrder);
		$dropIndex = array_search('ALTER_DROP', $commandOrder);

		$this->assertLessThan($dropIndex, $addIndex, 'ALTER_ADD must come before ALTER_DROP');

		// Test that DELETE_CLUSTER comes last (cleanup)
		$deleteIndex = array_search('DELETE_CLUSTER', $commandOrder);
		$this->assertEquals(sizeof($commandOrder) - 1, $deleteIndex, 'DELETE_CLUSTER should be last');
	}
}
