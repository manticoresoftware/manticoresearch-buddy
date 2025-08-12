<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Sharding\RollbackCommandGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Test rollback command generation
 */
class RollbackCommandGeneratorTest extends TestCase {

	/**
	 * Test CREATE TABLE rollback generation
	 * @return void
	 */
	public function testCreateTableRollback(): void {
		// Test simple CREATE TABLE
		$forward = "CREATE TABLE test_table (id bigint, name string)";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertEquals("DROP TABLE IF EXISTS test_table", $rollback);

		// Test CREATE TABLE IF NOT EXISTS
		$forward = "CREATE TABLE IF NOT EXISTS test_table (id bigint)";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertEquals("DROP TABLE IF EXISTS test_table", $rollback);

		// Test with backticks
		$forward = "CREATE TABLE `test_table` (id bigint)";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertEquals("DROP TABLE IF EXISTS test_table", $rollback);

		// Test specific method
		$rollback = RollbackCommandGenerator::forCreateTable("test_table");
		$this->assertEquals("DROP TABLE IF EXISTS test_table", $rollback);
	}

	/**
	 * Test CREATE CLUSTER rollback generation
	 * @return void
	 */
	public function testCreateClusterRollback(): void {
		// Test simple CREATE CLUSTER
		$forward = "CREATE CLUSTER test_cluster";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertEquals("DELETE CLUSTER test_cluster", $rollback);

		// Test CREATE CLUSTER IF NOT EXISTS
		$forward = "CREATE CLUSTER IF NOT EXISTS test_cluster 'path'";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertEquals("DELETE CLUSTER test_cluster", $rollback);

		// Test specific method
		$rollback = RollbackCommandGenerator::forCreateCluster("test_cluster");
		$this->assertEquals("DELETE CLUSTER test_cluster", $rollback);
	}

	/**
	 * Test ALTER CLUSTER ADD rollback generation
	 * @return void
	 */
	public function testAlterClusterAddRollback(): void {
		// Test ALTER CLUSTER ADD
		$forward = "ALTER CLUSTER test_cluster ADD test_table";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertEquals("ALTER CLUSTER test_cluster DROP test_table", $rollback);

		// Test with backticks
		$forward = "ALTER CLUSTER `test_cluster` ADD `test_table`";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertEquals("ALTER CLUSTER test_cluster DROP test_table", $rollback);

		// Test specific method
		$rollback = RollbackCommandGenerator::forAlterClusterAdd("test_cluster", "test_table");
		$this->assertEquals("ALTER CLUSTER test_cluster DROP test_table", $rollback);
	}

	/**
	 * Test ALTER CLUSTER DROP rollback generation
	 * @return void
	 */
	public function testAlterClusterDropRollback(): void {
		// Test ALTER CLUSTER DROP
		$forward = "ALTER CLUSTER test_cluster DROP test_table";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertEquals("ALTER CLUSTER test_cluster ADD test_table", $rollback);

		// Test specific method
		$rollback = RollbackCommandGenerator::forAlterClusterDrop("test_cluster", "test_table");
		$this->assertEquals("ALTER CLUSTER test_cluster ADD test_table", $rollback);
	}

	/**
	 * Test JOIN CLUSTER rollback generation
	 * @return void
	 */
	public function testJoinClusterRollback(): void {
		// Test JOIN CLUSTER
		$forward = "JOIN CLUSTER test_cluster AT 'node1' 'path'";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertEquals("DELETE CLUSTER test_cluster", $rollback);

		// Test specific method
		$rollback = RollbackCommandGenerator::forJoinCluster("test_cluster");
		$this->assertEquals("DELETE CLUSTER test_cluster", $rollback);
	}

	/**
	 * Test unsupported commands return null
	 * @return void
	 */
	public function testUnsupportedCommands(): void {
		// DROP TABLE cannot be rolled back (data loss)
		$forward = "DROP TABLE test_table";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertNull($rollback);

		// DELETE CLUSTER cannot be rolled back
		$forward = "DELETE CLUSTER test_cluster";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertNull($rollback);

		// DML operations cannot be rolled back
		$forward = "INSERT INTO test_table VALUES (1, 'test')";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertNull($rollback);

		$forward = "UPDATE test_table SET name = 'new' WHERE id = 1";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertNull($rollback);

		$forward = "DELETE FROM test_table WHERE id = 1";
		$rollback = RollbackCommandGenerator::generate($forward);
		$this->assertNull($rollback);
	}

	/**
	 * Test batch generation
	 * @return void
	 */
	public function testBatchGeneration(): void {
		$commands = [
			"CREATE TABLE test1 (id bigint)",
			"CREATE CLUSTER cluster1",
			"ALTER CLUSTER cluster1 ADD test1",
			"DROP TABLE test2",
		];

		$rollbacks = RollbackCommandGenerator::generateBatch($commands);

		$this->assertCount(4, $rollbacks);
		$this->assertEquals("DROP TABLE IF EXISTS test1", $rollbacks[0]);
		$this->assertEquals("DELETE CLUSTER cluster1", $rollbacks[1]);
		$this->assertEquals("ALTER CLUSTER cluster1 DROP test1", $rollbacks[2]);
		$this->assertNull($rollbacks[3]); // DROP TABLE cannot be rolled back
	}

	/**
	 * Test safety check
	 * @return void
	 */
	public function testSafetyCheck(): void {
		// DDL operations are safe
		$this->assertTrue(RollbackCommandGenerator::isSafeToRollback("CREATE TABLE test (id bigint)"));
		$this->assertTrue(RollbackCommandGenerator::isSafeToRollback("ALTER CLUSTER test ADD table"));
		$this->assertTrue(RollbackCommandGenerator::isSafeToRollback("JOIN CLUSTER test"));

		// DROP operations are not safe
		$this->assertFalse(RollbackCommandGenerator::isSafeToRollback("DROP TABLE test"));
		$this->assertFalse(RollbackCommandGenerator::isSafeToRollback("DELETE CLUSTER test"));

		// DML operations are not safe
		$this->assertFalse(RollbackCommandGenerator::isSafeToRollback("INSERT INTO test VALUES (1)"));
		$this->assertFalse(RollbackCommandGenerator::isSafeToRollback("UPDATE test SET id = 2"));
		$this->assertFalse(RollbackCommandGenerator::isSafeToRollback("DELETE FROM test"));
	}

	/**
	 * Test identifier quoting
	 * @return void
	 */
	public function testIdentifierQuoting(): void {
		// Test with special characters
		$rollback = RollbackCommandGenerator::forCreateTable("test-table");
		$this->assertEquals("DROP TABLE IF EXISTS `test-table`", $rollback);

		// Test with reserved word
		$rollback = RollbackCommandGenerator::forCreateTable("select");
		$this->assertEquals("DROP TABLE IF EXISTS `select`", $rollback);

		// Test normal identifier
		$rollback = RollbackCommandGenerator::forCreateTable("test_table");
		$this->assertEquals("DROP TABLE IF EXISTS test_table", $rollback);
	}
}