<?php declare(strict_types=1);

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

/**
 * Manual test for chunk processing functionality in BatchProcessor
 *
 * This test validates that the new sendMultiRequest chunk processing
 * works correctly with different data sizes and chunk configurations.
 */
class ChunkProcessingTest extends TestCase
{
	use TestFunctionalTrait;

	/**
	 * Test 1: Small batches that should NOT trigger chunking
	 */
	public function testSmallBatchNoChunking(): void {
		echo "\n=== TEST 1: Small Batch (No Chunking Expected) ===\n";

		// Set chunk size to 200, create 100 records (should use single request)
		putenv('BUDDY_REPLACE_SELECT_CHUNK_SIZE=200');

		$this->setupSmallTestTables();

		try {
			// Insert 100 records into source table using batch insert
			$values = [];
			for ($i = 1; $i <= 100; $i++) {
				$values[] = "($i, 'Product $i', " . ($i * 10.99) . ')';
			}
			$batchInsertQuery = 'INSERT INTO test_source_small (id, title, price) VALUES ' . implode(', ', $values);
			static::runSqlQuery($batchInsertQuery);

			echo "✓ Created 100 source records\n";

			// Execute REPLACE SELECT (should use single request, no chunking)
			static::runSqlQuery('REPLACE INTO test_target_small SELECT * FROM test_source_small');

			// Verify all records copied
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_target_small');
			$this->assertStringContainsString('100', implode($count));

			// Verify data integrity
			$data = static::runSqlQuery('SELECT id, title FROM test_target_small WHERE id = 50');
			$this->assertStringContainsString('Product 50', implode($data));

			echo "✓ All 100 records successfully copied (single request expected)\n";
		} finally {
			$this->cleanupSmallTestTables();
		}
	}

	/**
	 * Test 2: Large batches that SHOULD trigger chunking
	 */
	public function testLargeBatchWithChunking(): void {
		echo "\n=== TEST 2: Large Batch (Chunking Expected) ===\n";

		// Set small chunk size to force chunking
		putenv('BUDDY_REPLACE_SELECT_CHUNK_SIZE=50');

		$this->setupLargeTestTables();

		try {
			// Insert 1000 records into source table using batch insert
			$values = [];
			for ($i = 1; $i <= 1000; $i++) {
				$category = 'Category ' . ($i % 5);
				$price = round($i * 0.99, 2);
				$values[] = "($i, 'Product $i', $price, '$category')";
			}
			$batchInsertQuery = 'INSERT INTO test_source_large (id, title, price, category) VALUES ' .
				implode(', ', $values);

			static::runSqlQuery($batchInsertQuery);

			echo "✓ Created 1000 source records\n";

			// Execute REPLACE SELECT (should use multiple chunks)
			// With chunk size 50, this should create 20 chunks
			$r = static::runSqlQuery('REPLACE INTO test_target_large SELECT * FROM test_source_large');
			echo '----'.json_encode($r);

			// Verify all records copied
			$sourceCount = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_source_large');
			$targetCount = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_target_large');

			$this->assertStringContainsString('1000', implode($sourceCount));
			$this->assertStringContainsString('1000', implode($targetCount));

			// Verify data integrity with random samples
			$data1 = static::runSqlQuery('SELECT id, title FROM test_target_large WHERE id = 100');
			$this->assertStringContainsString('Product 100', implode($data1));

			$data2 = static::runSqlQuery('SELECT id, category FROM test_target_large WHERE id = 500');
			$this->assertStringContainsString('Category 0', implode($data2));

			echo "✓ All 1000 records successfully copied (chunked processing expected)\n";
		} finally {
			$this->cleanupLargeTestTables();
		}
	}

	/**
	 * Test 3: Environment variable configuration
	 */
	public function testEnvironmentVariableConfiguration(): void {
		echo "\n=== TEST 3: Environment Variable Configuration ===\n";

		$this->setupConfigTestTables();

		try {
			// Create 500 test records using batch insert
			$values = [];
			for ($i = 1; $i <= 500; $i++) {
				$values[] = "($i, 'Product $i', " . ($i * 2.50) . ')';
			}
			$batchInsertQuery = 'INSERT INTO test_source_config (id, title, price) VALUES ' . implode(', ', $values);
			static::runSqlQuery($batchInsertQuery);

			echo "✓ Created 500 source records\n";

			// Test 1: No environment variable (should use default 200)
			putenv('BUDDY_REPLACE_SELECT_CHUNK_SIZE');  // Unset
			static::runSqlQuery('DROP TABLE IF EXISTS test_target_default');
			static::runSqlQuery('CREATE TABLE test_target_default (id BIGINT, title TEXT, price FLOAT)');
			static::runSqlQuery('REPLACE INTO test_target_default SELECT * FROM test_source_config');
			$count1 = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_target_default');
			$this->assertStringContainsString('500', implode($count1));
			echo "✓ Default configuration (chunk size 200): Works\n";

			// Test 2: Custom environment variable
			putenv('BUDDY_REPLACE_SELECT_CHUNK_SIZE=75');
			static::runSqlQuery('DROP TABLE IF EXISTS test_target_custom');
			static::runSqlQuery('CREATE TABLE test_target_custom (id BIGINT, title TEXT, price FLOAT)');
			static::runSqlQuery('REPLACE INTO test_target_custom SELECT * FROM test_source_config');
			$count2 = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_target_custom');
			$this->assertStringContainsString('500', implode($count2));
			echo "✓ Custom configuration (chunk size 75): Works\n";

			// Test 3: Very small chunks
			putenv('BUDDY_REPLACE_SELECT_CHUNK_SIZE=10');
			static::runSqlQuery('DROP TABLE IF EXISTS test_target_tiny');
			static::runSqlQuery('CREATE TABLE test_target_tiny (id BIGINT, title TEXT, price FLOAT)');
			static::runSqlQuery('REPLACE INTO test_target_tiny SELECT * FROM test_source_config');
			$count3 = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_target_tiny');
			$this->assertStringContainsString('500', implode($count3));
			echo "✓ Tiny chunks (chunk size 10): Works (expected 50 chunks)\n";
		} finally {
			$this->cleanupConfigTestTables();
		}
	}

	private function setupSmallTestTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_source_small');
		static::runSqlQuery('DROP TABLE IF EXISTS test_target_small');

		static::runSqlQuery(
			'CREATE TABLE test_source_small (id BIGINT, title TEXT, price FLOAT)'
		);
		static::runSqlQuery(
			'CREATE TABLE test_target_small (id BIGINT, title TEXT, price FLOAT)'
		);
	}

	private function cleanupSmallTestTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_source_small');
		static::runSqlQuery('DROP TABLE IF EXISTS test_target_small');
	}

	private function setupLargeTestTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_source_large');
		static::runSqlQuery('DROP TABLE IF EXISTS test_target_large');

		static::runSqlQuery(
			'CREATE TABLE test_source_large (id BIGINT, title TEXT, price FLOAT, category TEXT)'
		);
		static::runSqlQuery(
			'CREATE TABLE test_target_large (id BIGINT, title TEXT, price FLOAT, category TEXT)'
		);
	}

	private function cleanupLargeTestTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_source_large');
		static::runSqlQuery('DROP TABLE IF EXISTS test_target_large');
	}

	private function setupConfigTestTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_source_config');

		static::runSqlQuery(
			'CREATE TABLE test_source_config (id BIGINT, title TEXT, price FLOAT)'
		);
	}

	private function cleanupConfigTestTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_source_config');
		static::runSqlQuery('DROP TABLE IF EXISTS test_target_default');
		static::runSqlQuery('DROP TABLE IF EXISTS test_target_custom');
		static::runSqlQuery('DROP TABLE IF EXISTS test_target_tiny');
	}
}
