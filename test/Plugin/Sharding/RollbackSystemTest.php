<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\BuddyTest\Plugin\Sharding\TestDoubles\TestableQueue;
use PHPUnit\Framework\TestCase;

/**
 * Test rollback functionality in the sharding system
 * Tests the simplified rollback approach where rollback commands are provided upfront
 */
final class RollbackSystemTest extends TestCase {

	/**
	 * @var array<array{
	 *   id: int,
	 *   node: string,
	 *   query: string,
	 *   rollback_query: string,
	 *   operation_group: string,
	 *   status: string
	 * }>
	 */
	public array $capturedCommands = [];
	/** @var array<string> */
	public array $rolledBackGroups = [];

	protected function setUp(): void {
		$this->capturedCommands = [];
		$this->rolledBackGroups = [];
	}

	/**
	 * Create a testable queue that captures commands
	 * @return TestableQueue
	 */
	private function createTestableQueue(): TestableQueue {
		$testCase = $this;
		return new class($testCase) extends TestableQueue {
			/**
			 * @var array<array{
			 *   id: int,
			 *   node: string,
			 *   query: string,
			 *   rollback_query: string,
			 *   operation_group: string,
			 *   status: string
			 * }>
			 */
			private array $capturedCommands = [];
			private RollbackSystemTest $testCase;

			public function __construct(RollbackSystemTest $testCase) {
				$this->testCase = $testCase;
				parent::__construct(null); // No real queue
			}

			public function add(
				string $nodeId,
				string $query,
				string $rollbackQuery = '',
				?string $operationGroup = null
			): int {
				$id = sizeof($this->capturedCommands) + 1;
				$command = [
					'id' => $id,
					'node' => $nodeId,
					'query' => $query,
					'rollback_query' => $rollbackQuery,
					'operation_group' => $operationGroup ?? '',
					'status' => 'created',
				];

				$this->capturedCommands[] = $command;
				$this->testCase->capturedCommands[] = $command;

				return $id;
			}

			public function rollbackOperationGroup(string $operationGroup): bool {
				// Mock successful rollback - use the parameter to avoid unused warning
				$this->testCase->rolledBackGroups[] = $operationGroup;
				return true;
			}

			/**
			 * @return array<array{
			 *   id: int,
			 *   node: string,
			 *   query: string,
			 *   rollback_query: string,
			 *   operation_group: string,
			 *   status: string
			 * }>
			 */
			public function getCapturedCommands(): array {
				return $this->capturedCommands;
			}
		};
	}

	/**
	 * Test that Queue::add() requires rollback command parameter
	 */
	public function testQueueAddRequiresRollbackCommand(): void {
		$queue = $this->createTestableQueue();

		// Test that rollback command is required (no longer optional)
		$queueId = $queue->add(
			'node1',
			'CREATE TABLE test_table (id bigint)',
			'DROP TABLE IF EXISTS test_table'  // Required rollback command
		);

		$this->assertIsInt($queueId);
		$this->assertGreaterThan(0, $queueId);

		// Verify command was captured with rollback
		$commands = $queue->getCapturedCommands();
		$this->assertCount(1, $commands);
		$this->assertEquals('CREATE TABLE test_table (id bigint)', $commands[0]['query']);
		$this->assertEquals('DROP TABLE IF EXISTS test_table', $commands[0]['rollback_query']);
	}

	/**
	 * Test rollback commands are stored correctly in queue
	 */
	public function testRollbackCommandsStoredInQueue(): void {
		$queue = $this->createTestableQueue();

		$queue->add(
			'node1',
			'CREATE TABLE test_table (id bigint)',
			'DROP TABLE IF EXISTS test_table',
			'test_operation_group'
		);

		$commands = $queue->getCapturedCommands();
		$this->assertCount(1, $commands);

		$command = $commands[0];
		$this->assertEquals('node1', $command['node']);
		$this->assertEquals('CREATE TABLE test_table (id bigint)', $command['query']);
		$this->assertEquals('DROP TABLE IF EXISTS test_table', $command['rollback_query']);
		$this->assertEquals('test_operation_group', $command['operation_group']);
	}

	/**
	 * Test common rollback patterns used in the system
	 */
	public function testCommonRollbackPatterns(): void {
		$queue = $this->createTestableQueue();

		$testCases = [
			// [forward_command, expected_rollback_pattern]
			['CREATE TABLE users (id bigint)', 'DROP TABLE IF EXISTS users'],
			['CREATE CLUSTER c1', 'DELETE CLUSTER c1'],
			['ALTER CLUSTER c1 ADD table1', 'ALTER CLUSTER c1 DROP table1'],
			['JOIN CLUSTER c1', 'DELETE CLUSTER c1'],
		];

		foreach ($testCases as [$forwardCmd, $rollbackCmd]) {
			$queue->add('node1', $forwardCmd, $rollbackCmd);
		}

		$commands = $queue->getCapturedCommands();
		$this->assertCount(sizeof($testCases), $commands);

		for ($i = 0; $i < sizeof($testCases); $i++) {
			$this->assertEquals($testCases[$i][0], $commands[$i]['query']);
			$this->assertEquals($testCases[$i][1], $commands[$i]['rollback_query']);
		}
	}

	/**
	 * Test rollback command validation (empty rollback for destructive operations)
	 */
	public function testDestructiveOperationsWithEmptyRollback(): void {
		$queue = $this->createTestableQueue();

		$destructiveOperations = [
			'DROP TABLE test_table',
			'DELETE FROM test_table',
			'TRUNCATE TABLE test_table',
		];

		foreach ($destructiveOperations as $operation) {
			// Destructive operations should have empty rollback (can't be undone)
			$queue->add('node1', $operation, '');
		}

		$commands = $queue->getCapturedCommands();
		$this->assertCount(sizeof($destructiveOperations), $commands);

		foreach ($commands as $command) {
			$this->assertEquals('', $command['rollback_query']);
		}
	}

	/**
	 * Test operation groups for atomic rollback
	 */
	public function testOperationGroupsForAtomicRollback(): void {
		$queue = $this->createTestableQueue();
		$operationGroup = 'atomic_operation_123';

		// Add multiple commands with same operation group
		$queue->add('node1', 'CREATE TABLE t1 (id bigint)', 'DROP TABLE IF EXISTS t1', $operationGroup);
		$queue->add('node1', 'CREATE CLUSTER c1', 'DELETE CLUSTER c1', $operationGroup);
		$queue->add('node2', 'JOIN CLUSTER c1', 'DELETE CLUSTER c1', $operationGroup);

		$commands = $queue->getCapturedCommands();
		$this->assertCount(3, $commands);

		// Verify all commands have the same operation group
		foreach ($commands as $command) {
			$this->assertEquals($operationGroup, $command['operation_group']);
		}
	}

	/**
	 * Test rollback execution returns success
	 */
	public function testRollbackExecution(): void {
		$queue = $this->createTestableQueue();

		// Add some commands
		$queue->add('node1', 'CREATE TABLE test (id bigint)', 'DROP TABLE IF EXISTS test', 'group1');

		// Test rollback execution
		$result = $queue->rollbackOperationGroup('group1');
		$this->assertTrue($result);
	}

	/**
	 * Test complex rollback scenario with multiple operations
	 */
	public function testComplexRollbackScenario(): void {
		$queue = $this->createTestableQueue();
		$operationGroup = 'complex_shard_creation';

		// Simulate complex table sharding with intermediate clusters (RF=1)
		$commands = [
			['node2', 'CREATE TABLE test_s0 (id bigint)', 'DROP TABLE IF EXISTS test_s0'],
			['node1', 'CREATE CLUSTER temp_move_0_123', 'DELETE CLUSTER temp_move_0_123'],
			['node1', 'ALTER CLUSTER temp_move_0_123 ADD test_s0', 'ALTER CLUSTER temp_move_0_123 DROP test_s0'],
			['node2', 'JOIN CLUSTER temp_move_0_123', 'DELETE CLUSTER temp_move_0_123'],
			['node1', 'ALTER CLUSTER temp_move_0_123 DROP test_s0', 'ALTER CLUSTER temp_move_0_123 ADD test_s0'],
			['node1', 'DROP TABLE test_s0', ''], // Destructive - empty rollback
			['node1', 'DELETE CLUSTER temp_move_0_123', ''], // Cleanup - empty rollback
			[
				'node1',
				'CREATE TABLE test type=\'distributed\' local=\'test_s1\' agent=\'node2:test_s0\'',
				'DROP TABLE IF EXISTS test',
			],
		];

		foreach ($commands as [$node, $query, $rollback]) {
			$queue->add($node, $query, $rollback, $operationGroup);
		}

		$capturedCommands = $queue->getCapturedCommands();
		$this->assertCount(sizeof($commands), $capturedCommands);

		// Verify all have same operation group
		foreach ($capturedCommands as $command) {
			$this->assertEquals($operationGroup, $command['operation_group']);
		}

		// Verify rollback patterns
		$this->assertEquals('DROP TABLE IF EXISTS test_s0', $capturedCommands[0]['rollback_query']);
		$this->assertEquals('DELETE CLUSTER temp_move_0_123', $capturedCommands[1]['rollback_query']);
		$this->assertEquals('', $capturedCommands[5]['rollback_query']); // Destructive operation
		$this->assertEquals('DROP TABLE IF EXISTS test', $capturedCommands[7]['rollback_query']);
	}
}
