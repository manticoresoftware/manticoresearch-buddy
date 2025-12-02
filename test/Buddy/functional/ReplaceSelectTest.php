<?php declare(strict_types=1);

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class ReplaceSelectTest extends TestCase {

	use TestFunctionalTrait;

	public function testReplaceSelectBasic(): void {
		echo "\nTesting basic REPLACE SELECT\n";

		// Setup source table
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_select_src');
		static::runSqlQuery('CREATE TABLE test_replace_select_src (title text stored, content text stored)');
		static::runSqlQuery(
			'INSERT INTO test_replace_select_src (id, title, content) VALUES ' .
			"(1, 'Title 1', 'Content 1'), " .
			"(2, 'Title 2', 'Content 2')"
		);

		// Verify source data
		$sourceCount = static::runSqlQuery('SELECT COUNT(*) FROM test_replace_select_src');
		echo 'Source table count: ' . implode(PHP_EOL, $sourceCount) . "\n";
		$this->assertStringContainsString('2', implode(PHP_EOL, $sourceCount));

		// Setup target table
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_select_tgt');
		static::runSqlQuery('CREATE TABLE test_replace_select_tgt (title text stored, content text stored)');

		// Test REPLACE SELECT
		echo "Executing REPLACE SELECT...\n";
		$query = 'REPLACE INTO test_replace_select_tgt SELECT id, title, content FROM ' .
			'test_replace_select_src';
		$result = static::runSqlQuery($query);
		echo 'REPLACE SELECT result: ' . implode(PHP_EOL, $result) . "\n";
		$this->assertIsArray($result, 'REPLACE SELECT should return a result');

		// Verify data was inserted
		$count = static::runSqlQuery('SELECT COUNT(*) FROM test_replace_select_tgt');
		echo 'Target table count: ' . implode(PHP_EOL, $count) . "\n";
		$this->assertStringContainsString('2', implode(PHP_EOL, $count), 'Target table should have 2 rows');

		// Verify actual data
		$data = static::runSqlQuery('SELECT * FROM test_replace_select_tgt');
		echo "Target table data:\n" . implode(PHP_EOL, $data) . "\n";

		// Cleanup
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_select_src');
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_select_tgt');
	}
}
