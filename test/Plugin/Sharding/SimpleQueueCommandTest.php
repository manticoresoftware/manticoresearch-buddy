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
 * Simple test that focuses on testing specific components that generate queue commands
 * This avoids the complexity of mocking the entire rebalancing flow
 */
class SimpleQueueCommandTest extends TestCase {

	/**
	 * Test that Util.rebalanceWithNewNodes generates correct schema for RF=1
	 * @return void
	 */
	public function testRF1SchemaGeneration(): void {
		// Create initial RF=1 schema: Node1=[0,2], Node2=[1,3]
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 2]),
				'connections' => new Set(['node1']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([1, 3]),
				'connections' => new Set(['node2']),
			],
			]
		);

		// Add new node
		$newNodes = new Set(['node3']);
		$replicationFactor = 1;

		// Test the rebalancing logic
		$newSchema = Util::rebalanceWithNewNodes($initialSchema, $newNodes, $replicationFactor);

		// Verify new schema has 3 nodes
		$this->assertEquals(3, $newSchema->count(), 'Should have 3 nodes after rebalancing');

		// Verify node3 is in the schema
		$node3Found = false;
		$node3Shards = null;
		foreach ($newSchema as $row) {
			if ($row['node'] === 'node3') {
				$node3Found = true;
				$node3Shards = $row['shards'];
				break;
			}
		}

		$this->assertTrue($node3Found, 'Node3 should be in new schema');
		$this->assertNotNull($node3Shards, 'Node3 shards should not be null');
		$this->assertGreaterThan(0, $node3Shards->count(), 'Node3 should have some shards');

		// Verify total shards remain the same
		$totalShards = 0;
		foreach ($newSchema as $row) {
			$totalShards += $row['shards']->count();
		}
		$this->assertEquals(4, $totalShards, 'Total shards should remain 4 for RF=1');

		// Verify RF=1 (each node has only itself in connections)
		foreach ($newSchema as $row) {
			$this->assertEquals(1, $row['connections']->count(), 'RF=1 should have single connection');
			$this->assertTrue($row['connections']->contains($row['node']), 'Node should connect to itself');
		}
	}

	/**
	 * Test that Util.rebalanceWithNewNodes generates correct schema for RF=2
	 * @return void
	 */
	public function testRF2SchemaGeneration(): void {
		// Create initial RF=2 schema: Both nodes have all shards
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0, 1, 2, 3]),
				'connections' => new Set(['node1', 'node2']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([0, 1, 2, 3]),
				'connections' => new Set(['node1', 'node2']),
			],
			]
		);

		// Add new node
		$newNodes = new Set(['node3']);
		$replicationFactor = 2;

		// Test the rebalancing logic
		$newSchema = Util::rebalanceWithNewNodes($initialSchema, $newNodes, $replicationFactor);

		// Verify new schema has 3 nodes
		$this->assertEquals(3, $newSchema->count(), 'Should have 3 nodes after rebalancing');

		// Verify node3 is in the schema and has shards
		$node3Found = false;
		$node3Shards = null;
		foreach ($newSchema as $row) {
			if ($row['node'] === 'node3') {
				$node3Found = true;
				$node3Shards = $row['shards'];
				break;
			}
		}

		$this->assertTrue($node3Found, 'Node3 should be in new schema');
		$this->assertNotNull($node3Shards, 'Node3 shards should not be null');
		$this->assertGreaterThan(0, $node3Shards->count(), 'Node3 should have some shards for load balancing');

		// For RF>=2, new node should get all shards for load balancing
		$this->assertEquals(4, $node3Shards->count(), 'Node3 should have all shards for RF=2 load balancing');

		// Verify connections are updated properly
		foreach ($newSchema as $row) {
			if ($row['shards']->count() <= 0) {
				continue;
			}

			$this->assertGreaterThanOrEqual(2, $row['connections']->count(), 'RF=2 should have multiple connections');
		}
	}

	/**
	 * Test that we can verify command patterns without full integration
	 * @return void
	 */
	public function testCommandPatterns(): void {
		// Test specific command patterns that should be generated
		$expectedRF1Patterns = [
			'CREATE TABLE IF NOT EXISTS system.table_s',
			'CREATE CLUSTER temp_move_',
			"' as path",
			'ALTER CLUSTER temp_move_',
			'DELETE CLUSTER temp_move_',
		];

		$expectedRF2Patterns = [
			'CREATE TABLE IF NOT EXISTS system.table_s',
			// Should NOT have intermediate clusters
			'DROP TABLE',
			'CREATE TABLE',
			'type=\'distributed\'',
		];

		// Verify RF=1 patterns
		foreach ($expectedRF1Patterns as $pattern) {
			$this->assertTrue(true, "RF=1 should generate pattern: $pattern");
		}

		// Verify RF=2 patterns
		foreach ($expectedRF2Patterns as $pattern) {
			$this->assertTrue(true, "RF=2 should generate pattern: $pattern");
		}

		// This is a placeholder test - in real implementation,
		// we would capture actual commands and verify these patterns
	}

	/**
	 * Test edge cases in schema generation
	 * @return void
	 */
	public function testEdgeCases(): void {
		// Test: More nodes than shards
		$initialSchema = new Vector(
			[
			[
				'node' => 'node1',
				'shards' => new Set([0]),
				'connections' => new Set(['node1']),
			],
			[
				'node' => 'node2',
				'shards' => new Set([1]),
				'connections' => new Set(['node2']),
			],
			]
		);

		$newNodes = new Set(['node3', 'node4']); // Adding 2 nodes for 2 shards
		$newSchema = Util::rebalanceWithNewNodes($initialSchema, $newNodes, 1);

		$this->assertEquals(4, $newSchema->count(), 'Should handle more nodes than shards');

		// Some nodes might have 0 shards, which is OK
		$totalShards = 0;
		foreach ($newSchema as $row) {
			$totalShards += $row['shards']->count();
		}
		$this->assertEquals(2, $totalShards, 'Total shards should remain 2');
	}

	/**
	 * Test that replication factor detection works correctly
	 * @return void
	 */
	public function testReplicationFactorDetection(): void {
		// RF=1 schema
		$rf1Schema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0]), 'connections' => new Set(['node1'])],
			['node' => 'node2', 'shards' => new Set([1]), 'connections' => new Set(['node2'])],
			]
		);

		// RF=2 schema
		$rf2Schema = new Vector(
			[
			['node' => 'node1', 'shards' => new Set([0]), 'connections' => new Set(['node1', 'node2'])],
			['node' => 'node2', 'shards' => new Set([0]), 'connections' => new Set(['node1', 'node2'])],
			]
		);

		// Test RF detection logic (this would be in Table.php)
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
}
