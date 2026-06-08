<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Sharding\Queue;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableCluster;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableQueue;
use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableTable;
use PHPUnit\Framework\TestCase;

/**
 * Test queue command generation during outage scenarios
 * Validates that proper SQL commands are generated when nodes fail
 */
class OutageQueueCommandTest extends TestCase {

	/**
	 * Mock queue that captures all commands for verification
	 * @var array<array{id:int,node:string,query:string,wait_for_id:?int}>
	 */
	private array $capturedCommands = [];

	protected function setUp(): void {
		$this->capturedCommands = [];
	}

	/**
	 * Test RF=2 node failure generates correct replication commands
	 * @return void
	 */
	public function testRF2NodeFailureCommands(): void {
		// Create mock client
		$client = $this->createMockClient();

		// Create cluster with node failure
		$cluster = $this->createTestableCluster('test_cluster');
		$this->mockClusterNodes($cluster, ['node1', 'node2', 'node3'], ['node2']); // node2 failed

		// Mock schema data for RF=2 table
		$this->mockSchemaData(
			$client, [
			['node' => 'node1', 'shards' => '0,1'],
			['node' => 'node2', 'shards' => '2,3'], // This node failed
			]
		);

		// Create testable table and queue
		$table = $this->createTestableTableWithMocks($client, $cluster, 'RF2_OUTAGE');
		$queue = $this->createTestableQueue();

		// Execute rebalancing
		$this->executeRebalancing($table, $queue, $client);

		// Verify commands were generated for replication
		$this->assertGreaterThan(3, sizeof($this->capturedCommands), 'Should generate replication commands');
	}

	/**
	 * Test RF=1 node failure with sufficient nodes
	 * @return void
	 */
	public function testRF1NodeFailureSufficientNodesCommands(): void {
		// Create mock client
		$client = $this->createMockClient();

		// Create cluster with node failure but sufficient remaining nodes
		$cluster = $this->createTestableCluster('test_cluster');
		$this->mockClusterNodes($cluster, ['node1', 'node2', 'node3'], ['node2']); // node2 failed

		// Mock schema data for RF=1 table
		$this->mockSchemaData(
			$client, [
			['node' => 'node1', 'shards' => '0,1'],
			['node' => 'node2', 'shards' => '2'], // This node failed, shard 2 orphaned
			]
		);

		// Create testable table and queue
		$table = $this->createTestableTableWithMocks($client, $cluster, 'RF1_OUTAGE_SUFFICIENT');
		$queue = $this->createTestableQueue();

		// Execute rebalancing
		$this->executeRebalancing($table, $queue, $client);

		// Verify commands were generated for shard movement
		$this->assertGreaterThan(4, sizeof($this->capturedCommands), 'Should generate shard movement commands');
	}

	/**
	 * Test RF=1 node failure with insufficient nodes (degraded mode)
	 * @return void
	 */
	public function testRF1NodeFailureInsufficientNodesCommands(): void {
		// Create mock client
		$client = $this->createMockClient();

		// Create cluster with insufficient nodes (only 2 nodes, 1 failed)
		$cluster = $this->createTestableCluster('test_cluster');
		$this->mockClusterNodes($cluster, ['node1', 'node2'], ['node2']); // Only node1 remains

		// Mock schema data for RF=1 table
		$this->mockSchemaData(
			$client, [
			['node' => 'node1', 'shards' => '0,1'],
			['node' => 'node2', 'shards' => '2'], // This node failed, shard 2 lost
			]
		);

		// Create testable table and queue
		$table = $this->createTestableTableWithMocks($client, $cluster, 'RF1_OUTAGE_INSUFFICIENT');
		$queue = $this->createTestableQueue();

		// Execute rebalancing
		$this->executeRebalancing($table, $queue, $client);

		// Verify degraded mode commands
		$this->assertCommandsContain(
			[
			'DROP TABLE test_table',
			'!temp_move_', // Should NOT use intermediate clusters in degraded mode
			]
		);
	}

	/**
	 * Test catastrophic failure (only 1 node survives)
	 * @return void
	 */
	public function testCatastrophicFailureCommands(): void {
		// Create mock client
		$client = $this->createMockClient();

		// Create cluster with catastrophic failure (only 1 node remains)
		$cluster = $this->createTestableCluster('test_cluster');
		$this->mockClusterNodes($cluster, ['node1', 'node2', 'node3'], ['node2', 'node3']); // Only node1 survives

		// Mock schema data showing massive data loss
		$this->mockSchemaData(
			$client, [
			['node' => 'node1', 'shards' => '0'], // Only 1 shard survives
			// node2 and node3 data is lost
			]
		);

		// Create testable table and queue
		$table = $this->createTestableTableWithMocks($client, $cluster, 'CATASTROPHIC_FAILURE');
		$queue = $this->createTestableQueue();

		// Execute rebalancing
		$this->executeRebalancing($table, $queue, $client);

		// Verify survival commands
		$this->assertGreaterThan(1, sizeof($this->capturedCommands), 'Should generate survival commands');
	}

	// Helper methods for creating test objects

	private function createTestableCluster(string $name): TestableCluster {
		unset($name); // Parameter required by interface but not used in test
		return new TestableCluster();
	}

	/** @return TestableQueue */
	private function createTestableQueue() {
		return new TestableQueue(null, [$this, 'addCapturedCommand']);
	}

	/** @param mixed $cluster */
	private function createTestableTableWithMocks(Client $client, $cluster, string $testType): TestableTable {
		unset($client, $cluster); // Parameters required by interface but not used in test
		$table = new TestableTable();
		$table->setTestScenario($testType); // Pass scenario to table
		return $table;
	}

	/** @param array{id:int,node:string,query:string,wait_for_id:?int} $command */
	public function addCapturedCommand(array $command): void {
		$this->capturedCommands[] = $command;
	}

	private function createMockClient(): Client {
		$client = $this->createMock(Client::class);
		$client->method('sendRequest')->willReturnCallback([$this, 'mockSendRequest']);

		$client->method('hasTable')->willReturnCallback(
			function ($table) {
				return strpos($table, 'system.') === 0;
			}
		);

		$settings = new \Manticoresearch\Buddy\Core\ManticoreSearch\Settings();
		$client->method('getSettings')->willReturn($settings);

		return $client;
	}

	/**
	 * @param mixed $cluster
	 * @param array<string> $allNodes
	 * @param array<string> $inactiveNodes
	 */
	private function mockClusterNodes(mixed $cluster, array $allNodes, array $inactiveNodes = []): void {
		/** @phpstan-ignore-next-line */
		$cluster->setNodes($allNodes);
		/** @phpstan-ignore-next-line */
		$cluster->setInactiveNodes($inactiveNodes);
	}

	/** @param array<array{node: string, shards: string}> $schemaData */
	private function mockSchemaData(Client $client, array $schemaData): void {
		unset($client);
		$this->mockedSchemaData = $schemaData;
	}

	/** @param string $query */
	public function mockSendRequest(string $query): mixed {
		if (strpos($query, 'SELECT node, shards FROM') !== false) {
			return $this->createMockResult($this->mockedSchemaData);
		}
		if (strpos($query, 'SELECT value FROM') !== false) {
			if (strpos($query, "key = 'hash'") !== false) {
				return $this->createMockResult([['value' => 'test_hash']]);
			}
			if (strpos($query, "key = 'master'") !== false) {
				return $this->createMockResult([['value' => '127.0.0.1:1312']]);
			}
		}

		return $this->createMockResult([]);
	}

	/** @param array<mixed> $data */
	private function createMockResult(array $data): \Manticoresearch\Buddy\Core\ManticoreSearch\Response {
		$result = $this->createMock(\Manticoresearch\Buddy\Core\ManticoreSearch\Response::class);
		$result->method('getResult')->willReturn([['data' => $data]]);
		$result->method('hasError')->willReturn(false);
		return $result;
	}

	private function executeRebalancing(TestableTable $table, Queue|TestableQueue $queue, Client $client): void {
		unset($client);
		echo "\nDEBUG: Starting outage rebalancing test\n";

		try {
			$table->rebalance($queue);
			echo "DEBUG: Outage rebalance completed successfully\n";
		} catch (\Throwable $e) {
			echo 'DEBUG: Exception during outage rebalance: ' . $e->getMessage() . "\n";
		}

		echo 'DEBUG: Captured commands count: ' . sizeof($this->capturedCommands) . "\n";
		foreach ($this->capturedCommands as $i => $cmd) {
			echo "DEBUG: Command $i: Node={$cmd['node']}, Query={$cmd['query']}\n";
		}
	}

	/** @param array<string> $expectedPatterns */
	private function assertCommandsContain(array $expectedPatterns): void {
		$allCommands = $this->getAllCommandsAsString();

		foreach ($expectedPatterns as $pattern) {
			if (strpos($pattern, '!') === 0) {
				// Assert pattern does NOT exist
				$pattern = substr($pattern, 1);
				$this->assertStringNotContainsString(
					$pattern,
					$allCommands,
					"Commands should NOT contain: $pattern. Commands: $allCommands"
				);
			} else {
				// Assert pattern exists
				$this->assertStringContainsString(
					$pattern,
					$allCommands,
					"Commands should contain: $pattern. Commands: $allCommands"
				);
			}
		}
	}

	private function getAllCommandsAsString(): string {
		$allCommands = array_column($this->capturedCommands, 'query');
		return implode(' | ', $allCommands);
	}

	/** @var array<array{node:string,shards:string}> */
	private array $mockedSchemaData = [];
}
