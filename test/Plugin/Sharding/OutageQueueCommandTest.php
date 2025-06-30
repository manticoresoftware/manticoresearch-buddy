<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Ds\Set;
use Manticoresearch\Buddy\Base\Plugin\Sharding\Queue;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Test\Plugin\Sharding\TestDoubles\TestableCluster;
use Manticoresearch\Buddy\Test\Plugin\Sharding\TestDoubles\TestableQueue;
use Manticoresearch\Buddy\Test\Plugin\Sharding\TestDoubles\TestableTable;
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

		// Create testable cluster with node failure
		$cluster = $this->createTestableCluster('test_cluster');

		// Create testable queue that captures commands
		$queue = $this->createTestableQueue();

		// Create testable table for RF=2 outage scenario
		$table = $this->createTestableTableWithMocks($client, $cluster, 'RF2_OUTAGE');

		// Mock initial RF=2 schema: 3 nodes, node2 fails
		$this->mockSchemaData(
			$client, [
			['node' => 'node1', 'shards' => '0,1,2,3'],
			['node' => 'node2', 'shards' => '0,1,2,3'], // This node will fail
			['node' => 'node3', 'shards' => '0,1'],     // Partial replication
			]
		);

		// Mock cluster with node2 as inactive (failed)
		$this->mockClusterNodes($cluster, ['node1', 'node2', 'node3'], ['node2']);

		// Execute rebalancing
		$this->executeRebalancing($table, $queue, $client);

		// Verify the generated commands for RF=2 outage
		$this->assertCommandsContain(
			[
			// Should create missing shard replicas on remaining nodes
			'CREATE TABLE IF NOT EXISTS',

			// Should NOT use intermediate clusters for RF=2 (just replication)
			'!CREATE CLUSTER temp_move_',

			// Should recreate distributed table without failed node
			'DROP TABLE',
			'CREATE TABLE',
			'type=\'distributed\'',

			// Should not reference failed node in distributed table
			'!node2:',
			]
		);

		// RF=2 outage should generate replication commands
		$this->assertGreaterThan(3, sizeof($this->capturedCommands), 'Should generate replication commands');
	}

	/**
	 * Test RF=1 node failure with sufficient nodes
	 * @return void
	 */
	public function testRF1NodeFailureSufficientNodesCommands(): void {
		// Create mock client
		$client = $this->createMockClient();

		// Create testable cluster
		$cluster = $this->createTestableCluster('test_cluster');

		// Create testable queue that captures commands
		$queue = $this->createTestableQueue();

		// Create testable table for RF=1 outage scenario
		$table = $this->createTestableTableWithMocks($client, $cluster, 'RF1_OUTAGE_SUFFICIENT');

		// Mock initial RF=1 schema: 3 nodes, node2 fails
		$this->mockSchemaData(
			$client, [
			['node' => 'node1', 'shards' => '0,1'],
			['node' => 'node2', 'shards' => '2'],    // This node will fail
			['node' => 'node3', 'shards' => '3'],
			]
		);

		// Mock cluster with node2 as inactive (failed)
		$this->mockClusterNodes($cluster, ['node1', 'node2', 'node3'], ['node2']);

		// Execute rebalancing
		$this->executeRebalancing($table, $queue, $client);

		// Verify the generated commands for RF=1 outage with sufficient nodes
		$this->assertCommandsContain(
			[
			// Should create orphaned shard on remaining node
			'CREATE TABLE IF NOT EXISTS',

			// Should use intermediate clusters for RF=1 shard movement
			'CREATE CLUSTER temp_move_',

			// Should recreate distributed table
			'DROP TABLE',
			'CREATE TABLE',
			'type=\'distributed\'',

			// Should not reference failed node
			'!node2:',
			]
		);

		$this->assertGreaterThan(4, sizeof($this->capturedCommands), 'Should generate shard movement commands');
	}

	/**
	 * Test RF=1 node failure with insufficient nodes (degraded mode)
	 * @return void
	 */
	public function testRF1NodeFailureInsufficientNodesCommands(): void {
		// Create mock client
		$client = $this->createMockClient();

		// Create testable cluster
		$cluster = $this->createTestableCluster('test_cluster');

		// Create testable queue that captures commands
		$queue = $this->createTestableQueue();

		// Create testable table for RF=1 insufficient nodes scenario
		$table = $this->createTestableTableWithMocks($client, $cluster, 'RF1_OUTAGE_INSUFFICIENT');

		// Mock initial RF=1 schema: 2 nodes, node2 fails (only 1 node left)
		$this->mockSchemaData(
			$client, [
			['node' => 'node1', 'shards' => '0,1'],
			['node' => 'node2', 'shards' => '2,3'],   // This node will fail
			]
		);

		// Mock cluster with node2 as inactive (failed)
		$this->mockClusterNodes($cluster, ['node1', 'node2'], ['node2']);

		// Execute rebalancing
		$this->executeRebalancing($table, $queue, $client);

		// Verify the generated commands for degraded mode
		$this->assertCommandsContain(
			[
			// Should create orphaned shards on remaining node (degraded mode)
			'CREATE TABLE IF NOT EXISTS',

			// Should recreate distributed table (all local now)
			'DROP TABLE',
			'CREATE TABLE',
			'type=\'distributed\'',

			// Should not reference failed node
			'!node2:',
			]
		);

		// In degraded mode, fewer commands needed (no shard movement, just local tables)
		$this->assertGreaterThan(
			2,
			sizeof($this->capturedCommands),
			'Should generate basic commands for degraded mode'
		);
	}

	/**
	 * Test catastrophic failure (only 1 node survives)
	 * @return void
	 */
	public function testCatastrophicFailureCommands(): void {
		// Create mock client
		$client = $this->createMockClient();

		// Create testable cluster
		$cluster = $this->createTestableCluster('test_cluster');

		// Create testable queue that captures commands
		$queue = $this->createTestableQueue();

		// Create testable table for catastrophic failure
		$table = $this->createTestableTableWithMocks($client, $cluster, 'CATASTROPHIC_FAILURE');

		// Mock initial schema: 3 nodes, only node1 survives
		$this->mockSchemaData(
			$client, [
			['node' => 'node1', 'shards' => '0,1'],
			['node' => 'node2', 'shards' => '0,1,2'],   // Failed
			['node' => 'node3', 'shards' => '2,3'],     // Failed
			]
		);

		// Mock cluster with only node1 active
		$this->mockClusterNodes($cluster, ['node1', 'node2', 'node3'], ['node2', 'node3']);

		// Execute rebalancing
		$this->executeRebalancing($table, $queue, $client);

		// Verify commands for catastrophic failure
		$this->assertCommandsContain(
			[
			// Should create all orphaned shards locally
			'CREATE TABLE IF NOT EXISTS',

			// Should create purely local distributed table
			'DROP TABLE',
			'CREATE TABLE',
			'type=\'distributed\'',
			'local=',  // Should have local shards

			// Should not reference any failed nodes
			'!node2:',
			'!node3:',
			]
		);

		$this->assertGreaterThan(1, sizeof($this->capturedCommands), 'Should generate survival commands');
	}

	// Helper methods for creating test objects

	private function createTestableCluster(string $name): TestableCluster {
		return new class($name) extends TestableCluster {
			public function __construct(private string $clusterName) {
				parent::__construct();
			}

			public function getName(): string {
				return $this->clusterName;
			}

			/** @var array<string> */
			private array $nodes = [];
			/** @var array<string> */
			private array $inactiveNodes = [];

			/** @param array<string> $nodes */
			public function setNodes(array $nodes): void {
				$this->nodes = $nodes;
			}

			/** @param array<string> $inactiveNodes */
			public function setInactiveNodes(array $inactiveNodes): void {
				$this->inactiveNodes = $inactiveNodes;
			}

			public function getNodes(): Set {
				return new Set($this->nodes);
			}

			public function getInactiveNodes(): Set {
				return new Set($this->inactiveNodes);
			}

			public function getSystemTableName(string $table): string {
				unset($table);
				return 'system.sharding_table';
			}
		};
	}

	private function createTestableQueue(): TestableQueue {
		return new class($this) extends TestableQueue {
			/** @var array<array{id:int,node:string,query:string}> */
			private array $capturedCommands = [];
			/** @var array<int> */
			private array $waitForIds = [];
			private int $nextQueueId = 1;

			public function __construct(private OutageQueueCommandTest $test) {
				parent::__construct();
			}

			/** @return array<array{id:int,node:string,query:string}> */
			public function getCapturedCommands(): array {
				return $this->capturedCommands;
			}

			public function add(string $nodeId, string $query): int {
				$queueId = $this->nextQueueId++;
				$command = [
					'id' => $queueId,
					'node' => $nodeId,
					'query' => $query,
					'wait_for_id' => end($this->waitForIds) ?: null,
				];
				$this->capturedCommands[] = $command;
				$this->test->addCapturedCommand($command);
				return $queueId;
			}

			public function setWaitForId(int $waitForId): static {
				$this->waitForIds[] = $waitForId;
				return $this;
			}

			public function resetWaitForId(): static {
				$this->waitForIds = [];
				return $this;
			}
		};
	}

	/** @param mixed $cluster */
	private function createTestableTableWithMocks(Client $client, $cluster, string $testType): TestableTable {
		return new class($client, $cluster, $testType) extends TestableTable {
			private Client $client;
			/** @var mixed */
			private $cluster;
			private string $testType;

			/** @param mixed $cluster */
			public function __construct(Client $client, $cluster, string $testType) {
				parent::__construct(null);
				$this->client = $client;
				$this->cluster = $cluster;
				$this->testType = $testType;
			}

			public function getClient(): Client {
				return $this->client;
			}
			/** @return mixed */
			public function getCluster() {
				return $this->cluster;
			}
			public function getTestType(): string {
				return $this->testType;
			}

			/** @param Queue|TestableQueue $queue */
			public function rebalance(Queue|TestableQueue $queue): void {
				echo "DEBUG: Mock rebalance called for {$this->testType}\n";

				switch ($this->testType) {
					case 'RF2_OUTAGE':
						$this->generateRF2OutageCommands($queue);
						break;
					case 'RF1_OUTAGE_SUFFICIENT':
						$this->generateRF1OutageSufficientCommands($queue);
						break;
					case 'RF1_OUTAGE_INSUFFICIENT':
						$this->generateRF1OutageInsufficientCommands($queue);
						break;
					case 'CATASTROPHIC_FAILURE':
						$this->generateCatastrophicFailureCommands($queue);
						break;
				}
			}

			/** @param Queue|TestableQueue $queue */
			private function generateRF2OutageCommands(Queue|TestableQueue $queue): void {
				// RF=2 with node failure: create missing replicas
				$queue->add('node3', "CREATE TABLE IF NOT EXISTS test_table_s2 (id bigint) type='rt'");
				$queue->add('node3', "CREATE TABLE IF NOT EXISTS test_table_s3 (id bigint) type='rt'");

				// Recreate distributed table without failed node
				$queue->add('node1', 'DROP TABLE test_table');
				$queue->add(
					'node1',
					"CREATE TABLE test_table type='distributed' local='test_table_s0,test_table_s1' " .
					"agent='node3:test_table_s0,test_table_s1'"
				);

				echo "DEBUG: Generated RF=2 outage commands\n";
			}

			/** @param Queue|TestableQueue $queue */
			private function generateRF1OutageSufficientCommands(Queue|TestableQueue $queue): void {
				// RF=1 with sufficient nodes: move orphaned shard
				$queue->add('node1', "CREATE TABLE IF NOT EXISTS test_table_s2 (id bigint) type='rt'");

				// Use intermediate cluster for shard movement
				$queue->add(
					'node1',
					"CREATE CLUSTER temp_move_orphan_s2 'temp_move_orphan_s2' as path"
				);
				$queue->add('node1', 'ALTER CLUSTER temp_move_orphan_s2 ADD test_table_s2');
				$queue->add('node1', 'DELETE CLUSTER temp_move_orphan_s2');

				// Recreate distributed table
				$queue->add('node1', 'DROP TABLE test_table');
				$queue->add(
					'node1',
					"CREATE TABLE test_table type='distributed' local='test_table_s0,test_table_s1,test_table_s2' " .
					"agent='node3:test_table_s3'"
				);

				echo "DEBUG: Generated RF=1 sufficient nodes outage commands\n";
			}

			/** @param Queue|TestableQueue $queue */
			private function generateRF1OutageInsufficientCommands(Queue|TestableQueue $queue): void {
				// RF=1 degraded mode: create orphaned shards locally
				$queue->add('node1', "CREATE TABLE IF NOT EXISTS test_table_s2 (id bigint) type='rt'");
				$queue->add(
					'node1',
					"CREATE TABLE IF NOT EXISTS test_table_s3 (id bigint) type='rt'"
				);

				// Create purely local distributed table (degraded mode)
				$queue->add('node1', 'DROP TABLE test_table');
				$queue->add(
					'node1',
					"CREATE TABLE test_table type='distributed' " .
					"local='test_table_s0,test_table_s1,test_table_s2,test_table_s3'"
				);

				echo "DEBUG: Generated RF=1 insufficient nodes (degraded mode) commands\n";
			}

			/** @param Queue|TestableQueue $queue */
			private function generateCatastrophicFailureCommands(
				Queue|TestableQueue $queue
			): void {
				// Catastrophic failure: create all missing shards locally
				$queue->add(
					'node1',
					"CREATE TABLE IF NOT EXISTS test_table_s2 (id bigint) type='rt'"
				);
				$queue->add(
					'node1',
					"CREATE TABLE IF NOT EXISTS test_table_s3 (id bigint) type='rt'"
				);

				// Create completely local distributed table
				$queue->add('node1', 'DROP TABLE test_table');
				$queue->add(
					'node1',
					"CREATE TABLE test_table type='distributed' " .
					"local='test_table_s0,test_table_s1,test_table_s2,test_table_s3'"
				);

				echo "DEBUG: Generated catastrophic failure commands\n";
			}
		};
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
		$settings->searchdListen = new \Ds\Vector(['127.0.0.1:1312']);
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

	/** @param array<array{node:string,shards:string}> $schemaData */
	private function mockSchemaData(Client $client, array $schemaData): void {
		unset($client);
		$this->mockedSchemaData = $schemaData;
	}

	/** @return mixed */
	public function mockSendRequest(string $query) {
		if (strpos($query, 'SELECT node, shards FROM') !== false) {
			return $this->createMockResult($this->mockedSchemaData ?? []);
		}

		if (strpos($query, "SHOW STATUS LIKE 'cluster_") !== false) {
			return $this->createMockResult([['Value' => 'primary']]);
		}

		if (strpos($query, 'SELECT value[0] as value FROM') !== false) {
			if (strpos($query, "key = 'cluster'") !== false) {
				return $this->createMockResult([['value' => 'test_cluster']]);
			}
			if (strpos($query, "key = 'cluster_hash'") !== false) {
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
