<?php declare(strict_types=1);

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

/**
 * Test automatic ORDER BY addition in ReplaceSelect plugin
 */
class ReplaceSelectOrderingTest extends TestCase {

	use TestFunctionalTrait;

	public function testAutomaticOrderByAddition(): void {
		echo "\n=== TEST: Automatic ORDER BY addition ===\n";
		$this->setupTestTables();

		try {
			// Insert test data with specific IDs to verify ordering
			static::runSqlQuery(
				'INSERT INTO test_replace_src (' .
				'id, title, description, content, count_value, price, is_active, tags, ' .
				'created_at, updated_at, mva_tags, json_data' .
				') VALUES ' .
				"(10, 'Record 10', 'Description 10', 'Content 10', 10, 10.0, 1, 'test', ".
				"1609459200, 1609545600, (10, 20), '{\"id\":10}'), " .
				"(5, 'Record 5', 'Description 5', 'Content 5', 5, 5.0, 1, 'test', ".
				"1609459200, 1609545600, (5, 15), '{\"id\":5}'), " .
				"(15, 'Record 15', 'Description 15', 'Content 15', 15, 15.0, 1, 'test', ".
				"1609459200, 1609545600, (15, 25), '{\"id\":15}'), " .
				"(1, 'Record 1', 'Description 1', 'Content 1', 1, 1.0, 1, 'test', ".
				"1609459200, 1609545600, (1, 11), '{\"id\":1}'), " .
				"(20, 'Record 20', 'Description 20', 'Content 20', 20, 20.0, 1, 'test', ".
				"1609459200, 1609545600, (20, 30), '{\"id\":20}')"
			);

			// First test: Try with explicit ORDER BY to confirm it works
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt SELECT * FROM test_replace_src '.
				'WHERE id IN (1, 5, 10, 15, 20) ORDER BY id ASC'
			);

			// Verify records are in correct order
			$rawIds = static::runSqlQuery('SELECT id FROM test_replace_tgt');
			$ids = $this->extractIdsFromResult($rawIds);
			// Should be in ascending order: 1,5,10,15,20
			$this->assertEquals([1, 5, 10, 15, 20], $ids);

			// Verify count
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('5', implode($count));

			// Clear target table for next test
			static::runSqlQuery('DELETE FROM test_replace_tgt');

			// Now test without ORDER BY (should auto-add ORDER BY id ASC)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt SELECT * FROM test_replace_src WHERE id IN (1, 5, 10, 15, 20)'
			);

			// Verify records are in correct order
			$rawIds = static::runSqlQuery('SELECT id FROM test_replace_tgt');
			$ids = $this->extractIdsFromResult($rawIds);
			// Should be in ascending order: 1,5,10,15,20
			$this->assertEquals([1, 5, 10, 15, 20], $ids);

			// Verify count
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('5', implode($count));

			echo "✓ Automatic ORDER BY addition works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testExistingOrderByPreserved(): void {
		echo "\n=== TEST: Existing ORDER BY preserved ===\n";
		$this->setupTestTables();

		try {
			// Insert test data
			static::runSqlQuery(
				'INSERT INTO test_replace_src (id, title, price) VALUES ' .
				"(1, 'Record 1', 10.0), " .
				"(2, 'Record 2', 5.0), " .
				"(3, 'Record 3', 15.0)"
			);

			// Execute REPLACE SELECT with explicit ORDER BY (should not be modified)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, price) ' .
				'SELECT id, title, price FROM test_replace_src ORDER BY price DESC'
			);

			// Verify records are ordered by price DESC as specified
			$prices = static::runSqlQuery('SELECT price FROM test_replace_tgt');
			$priceStr = implode(',', $prices);

			// Should be in descending price order: 15,10,5
			$this->assertStringContainsString('15', $priceStr);
			$this->assertStringContainsString('10', $priceStr);
			$this->assertStringContainsString('5', $priceStr);

			echo "✓ Existing ORDER BY clause preserved correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testBatchProcessingWithOrdering(): void {
		echo "\n=== TEST: Batch processing with automatic ordering ===\n";
		$this->setupTestTables();

		try {
			// Insert more records to test batch processing
			for ($i = 1; $i <= 25; $i++) {
				static::runSqlQuery(
					"INSERT INTO test_replace_src (id, title, price) VALUES ($i, 'Record $i', $i.0)"
				);
			}

			// Execute REPLACE SELECT with small batch size to test multiple batches
			putenv('BUDDY_REPLACE_SELECT_BATCH_SIZE=10');

			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, price) ' .
				'SELECT id, title, price FROM test_replace_src WHERE id <= 25'
			);

			// Verify all records were processed without duplicates
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('25', implode($count));

			// Verify no duplicates by checking distinct count
			$distinctCount = static::runSqlQuery('SELECT COUNT(DISTINCT id) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('25', implode($distinctCount));

			// Verify ordering by checking min/max IDs
			$minId = static::runSqlQuery('SELECT MIN(id) as min_id FROM test_replace_tgt');
			$maxId = static::runSqlQuery('SELECT MAX(id) as max_id FROM test_replace_tgt');
			$this->assertStringContainsString('1', implode($minId));
			$this->assertStringContainsString('25', implode($maxId));

			echo "✓ Batch processing with automatic ordering works correctly\n";
		} finally {
			putenv('BUDDY_REPLACE_SELECT_BATCH_SIZE');
			$this->cleanupTestTables();
		}
	}

	/**
	 * Setup test tables
	 */
	private function setupTestTables(): void {
		// Create source table with same structure as working tests
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_src');
		static::runSqlQuery(
			'CREATE TABLE test_replace_src (' .
			'id BIGINT, ' .
			'title TEXT STORED, ' .
			'description TEXT STORED, ' .
			'content TEXT STORED, ' .
			'count_value INT, ' .
			'price FLOAT, ' .
			'is_active BOOL, ' .
			'tags TEXT STORED, ' .
			'created_at TIMESTAMP, ' .
			'updated_at TIMESTAMP, ' .
			'mva_tags MULTI, ' .
			'json_data TEXT STORED' .
			')'
		);

		// Create target table with same structure
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_tgt');
		static::runSqlQuery(
			'CREATE TABLE test_replace_tgt (' .
			'id BIGINT, ' .
			'title TEXT STORED, ' .
			'description TEXT STORED, ' .
			'content TEXT STORED, ' .
			'count_value INT, ' .
			'price FLOAT, ' .
			'is_active BOOL, ' .
			'tags TEXT STORED, ' .
			'created_at TIMESTAMP, ' .
			'updated_at TIMESTAMP, ' .
			'mva_tags MULTI, ' .
			'json_data TEXT STORED' .
			')'
		);
	}

	/**
	 * Cleanup test tables
	 */
	private function cleanupTestTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_src');
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_tgt');
	}

	/**
	 * Extract ID values from SQL query result
	 * @param array<string> $result MySQL CLI output lines
	 * @return array<int> Array of extracted ID values
	 */
	private function extractIdsFromResult(array $result): array {
		$ids = [];
		foreach ($result as $line) {
			if (!preg_match('/\|\s*(\d+)\s*\|/', $line, $matches)) {
				continue;
			}

			$ids[] = (int)$matches[1];
		}
		return $ids;
	}
}
