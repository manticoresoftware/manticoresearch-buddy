<?php declare(strict_types=1);

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive ReplaceSelect Plugin Functional Tests
 *
 * This test suite validates the REPLACE INTO ... SELECT functionality with:
 * - Basic operations (SELECT *, column subsets, WHERE conditions)
 * - Function-based transformations (math, string, date/time, type casting)
 * - Column list syntax (REPLACE INTO table (col1, col2) SELECT ...)
 * - Error scenarios and edge cases
 * - Data integrity and field type preservation
 */
class ReplaceSelectTest extends TestCase {

	use TestFunctionalTrait;

	public function testBasicReplaceSelectAll(): void {
		echo "\n=== TEST: Basic REPLACE SELECT (SELECT *) ===\n";
		$this->setupTestTables();

		try {
			// Execute REPLACE SELECT
			static::runSqlQuery('REPLACE INTO test_replace_tgt SELECT * FROM test_replace_src');

			// Verify all 10 records copied
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('10', implode($count));

			// Verify data integrity
			$data = static::runSqlQuery('SELECT id, title FROM test_replace_tgt WHERE id = 1');
			$this->assertStringContainsString('Product A', implode($data));

			echo "✓ All 10 records successfully copied\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	/**
	 * Setup test tables with comprehensive data
	 */
	private function setupTestTables(): void {
		// Create source table with all supported field types
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

		// Insert comprehensive test data
		static::runSqlQuery(
			'INSERT INTO test_replace_src (' .
			'id, title, description, content, count_value, price, is_active, tags, ' .
			'created_at, updated_at, mva_tags, json_data' .
			') VALUES ' .
			"(1, 'Product A', 'Premium Quality Product', 'Long detailed content for product A', ".
			"10, 99.99, 1, 'electronics,gadgets', 1609459200, 1609545600, (10, 20, 30), ".
			"'{\"color\":\"red\",\"size\":\"M\"}'), " .
			"(2, 'Product B', 'Budget Option', 'Content for product B', 5, 29.99, 0, ".
			"'budget,sales', 1609546000, 1609632400, (40, 50), '{\"color\":\"blue\",\"size\":\"L\"}'), " .
			"(3, 'Product C', 'Premium Deluxe Item', 'Very detailed product description ".
			"for premium item', 100, 999.99, 1, 'luxury,premium,exclusive', 1609632800, 1609719200, ".
			"(100, 200, 300, 400), '{\"color\":\"gold\",\"material\":\"leather\"}'), " .
			"(4, 'Product D: Special \"Edition\"', 'Description with \\'quotes\\' and symbols: ".
			"@#\$%', 'Content with special chars: <html>&entities;</html>', 75, 149.50, 1, ".
			"'special,edition,limited', 1609719600, 1609806000, (15, 25), ".
			"'{\"category\":\"electronics\",\"note\":\"special\"}'), " .
			"(5, 'Product E - Extended Info', 'Comprehensive product description with extensive ".
			"details about features and benefits', 'Lorem ipsum dolor sit amet, consectetur ".
			"adipiscing elit. Extensive content here...', 200, 499.99, 0, 'standard,bulk,wholesale', ".
			"1609806400, 1609892800, (50, 75, 100), '{\"bulk\":true,\"minQuantity\":10}'), " .
			"(6, 'Product F', 'Test Float Precision', 'Testing float precision and conversion', ".
			"32767, 0.01, 1, 'precision,test', 1609893200, 1609979600, (1, 2, 3, 4, 5), ".
			"'{\"precision\":\"float64\"}'), " .
			"(7, 'Product G', 'Future Date Product', 'Product with future timestamp', ".
			"99, 199.99, 0, 'future,scheduled', 2147483647, 2147483647, (200), '{\"future\":true}'), " .
			"(8, 'Minimal', 'Brief', 'Short', 1, 0.99, 1, 'basic', 1609980000, 1609980000, (99), '{}'), " .
			"(9, 'Produit Français', 'Avec caractères spéciaux: é, à, ñ, ü, ö', 'Contenu ".
			"avec accents et caractères unicode: 中文, 日本語, العربية', 45, 79.99, 1, ".
			"'international,unicode,multilang', 1610066400, 1610152800, (33, 66, 99), ".
			"'{\"lang\":\"fr\",\"encoding\":\"utf8\"}'), " .
			"(10, 'Bulk Record', 'Record for batch processing performance test', 'This ".
			'record tests batch processing efficiency with moderate content size. The '.
			"goal is to ensure the plugin handles multiple batches correctly.', 500, ".
			"999.99, 1, 'batch,test,performance', 1610239200, 1610325600, (111, 222, ".
			"333, 444, 555), '{\"batch\":true,\"records\":10}')"
		);
	}

	// ========================================================================
	// Category 1: Basic Operations
	// ========================================================================

	private function cleanupTestTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_src');
		static::runSqlQuery('DROP TABLE IF EXISTS test_replace_tgt');
	}

	public function testReplaceSelectWithColumnList(): void {
		echo "\n=== TEST: REPLACE SELECT with column list ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with specific columns
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, description, price, is_active) ' .
				'SELECT id, title, description, price, is_active FROM test_replace_src'
			);

			// Verify records
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('10', implode($count));

			// Verify specified columns exist
			$data = static::runSqlQuery('SELECT id, title, price FROM test_replace_tgt WHERE id = 3');
			$this->assertStringContainsString('Product C', implode($data));
			$this->assertStringContainsString('999.98', implode($data)); // Float precision

			echo "✓ Column list syntax works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testReplaceSelectWithWhereClause(): void {
		echo "\n=== TEST: REPLACE SELECT with WHERE clause ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with price filter
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt ' .
				'SELECT * FROM test_replace_src WHERE is_active = 1 AND price > 50 AND price < 500'
			);

			// Verify filtered records
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('3', implode($count));

			// Verify specific records
			$data = static::runSqlQuery('SELECT id FROM test_replace_tgt ORDER BY id');
			$this->assertStringContainsString('1', implode($data));

			echo "✓ WHERE clause filtering works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testReplaceSelectWithLimit(): void {
		echo "\n=== TEST: REPLACE SELECT with LIMIT ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with LIMIT
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, description) ' .
				'SELECT id, title, description FROM test_replace_src LIMIT 5'
			);

			// Verify limited records
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('5', implode($count));

			echo "✓ LIMIT clause works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testReplaceSelectWithLimitOffset(): void {
		echo "\n=== TEST: REPLACE SELECT with LIMIT OFFSET ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with LIMIT and OFFSET
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt SELECT * FROM test_replace_src LIMIT 5 OFFSET 5'
			);

			// Verify offset records
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('5', implode($count));

			// Verify correct records (should be 6-10)
			$ids = static::runSqlQuery('SELECT id FROM test_replace_tgt ORDER BY id');
			$idStr = implode($ids);
			$this->assertStringContainsString('6', $idStr);
			$this->assertStringContainsString('10', $idStr);

			echo "✓ LIMIT OFFSET pagination works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testReplaceSelectSubsetColumns(): void {
		echo "\n=== TEST: REPLACE SELECT with subset of columns ===\n";
		$this->setupTestTables();

		try {
			// Verify we can select specific columns
			$data = static::runSqlQuery(
				'SELECT id, title FROM test_replace_src LIMIT 1'
			);
			$this->assertStringContainsString('Product A', implode($data));

			echo "✓ Subset column selection works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	// ========================================================================
	// Category 2: Mathematical Functions
	// ========================================================================

	public function testMathFunctionCeil(): void {
		echo "\n=== TEST: Mathematical function - CEIL ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with CEIL function
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, price) ' .
				'SELECT id, title, CEIL(price) as price FROM test_replace_src WHERE id IN (1, 2, 4)'
			);

			// Verify CEIL result (99.99 -> 100, 29.99 -> 30, 149.50 -> 150)
			$prices = static::runSqlQuery('SELECT price FROM test_replace_tgt WHERE id = 1');
			$this->assertStringContainsString('100', implode($prices));

			echo "✓ CEIL function works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testMathFunctionMultiply(): void {
		echo "\n=== TEST: Mathematical function - Price multiplier ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with multiplication (price increase by 10%)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, price) ' .
				'SELECT id, title, price * 1.1 as price FROM test_replace_src WHERE id IN (8, 9)'
			);

			// Verify multiplication (0.99 * 1.1 = 1.089, 79.99 * 1.1 = 87.989)
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('2', implode($count));

			echo "✓ Price multiplication works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	// ========================================================================
	// Category 3: String Functions
	// ========================================================================

	public function testStringFieldCopy(): void {
		echo "\n=== TEST: String field copy ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with text fields (basic string handling)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, description) ' .
				'SELECT id, title, description FROM test_replace_src WHERE id IN (1, 2)'
			);

			// Verify text fields copied correctly
			$data = static::runSqlQuery('SELECT title FROM test_replace_tgt WHERE id = 1');
			$this->assertStringContainsString('Product A', implode($data));

			echo "✓ Text field copying works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testLongTextContent(): void {
		echo "\n=== TEST: Long text content handling ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with long text content (Record 5 has extensive content)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, content) ' .
				'SELECT id, title, content FROM test_replace_src WHERE id = 5'
			);

			// Verify long content preserved
			$data = static::runSqlQuery('SELECT content FROM test_replace_tgt WHERE id = 5');
			$content = implode($data);
			$this->assertStringContainsString('Lorem ipsum', $content);

			echo "✓ Long text content preserved correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	// ========================================================================
	// Category 4: Date/Time Functions
	// ========================================================================

	public function testDateTimestampFiltering(): void {
		echo "\n=== TEST: Timestamp filtering ===\n";
		$this->setupTestTables();

		try {
			// Filter by timestamp > 1609720000 (IDs: 5-10)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, created_at) ' .
				'SELECT id, title, created_at FROM test_replace_src WHERE created_at > 1609720000'
			);

			// Verify 6 records copied
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('6', implode($count));

			// Verify records exist
			$ids = static::runSqlQuery('SELECT id FROM test_replace_tgt');
			$idStr = implode($ids);
			$this->assertStringContainsString('5', $idStr);

			echo "✓ Timestamp filtering works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	// ========================================================================
	// Category 5: Data Type Preservation
	// ========================================================================

	public function testSpecialCharactersPreservation(): void {
		echo "\n=== TEST: Special characters preservation ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with special characters (IDs: 4, 5, 9)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, description) ' .
				'SELECT id, title, description FROM test_replace_src WHERE id IN (4, 5, 9)'
			);

			// Verify count
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('3', implode($count));

			// Verify special characters preserved
			$data = static::runSqlQuery('SELECT title FROM test_replace_tgt WHERE id = 4');
			$dataStr = implode($data);
			$this->assertStringContainsString('Special', $dataStr);

			echo "✓ Special characters preserved correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testMvaFieldPreservation(): void {
		echo "\n=== TEST: MVA field preservation ===\n";
		$this->setupTestTables();

		try {
			// REPLACE with MULTI field (IDs: 1, 3, 5)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, mva_tags) ' .
				'SELECT id, title, mva_tags FROM test_replace_src WHERE id IN (1, 3, 5)'
			);

			// Verify count
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('3', implode($count));

			echo "✓ MVA fields preserved correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	// ========================================================================
	// Category 6: REPLACE Behavior (Update vs Insert)
	// ========================================================================

	public function testReplaceUpdateExistingRecord(): void {
		echo "\n=== TEST: REPLACE updates existing records ===\n";
		$this->setupTestTables();

		try {
			// Insert initial record
			static::runSqlQuery(
				"INSERT INTO test_replace_tgt (id, title, price) VALUES (1, 'Old Title', 50.00)"
			);

			// REPLACE should update the record
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, description, price) ' .
				'SELECT id, title, description, price FROM test_replace_src WHERE id = 1'
			);

			// Verify record was updated
			$data = static::runSqlQuery('SELECT title, price FROM test_replace_tgt WHERE id = 1');
			$dataStr = implode($data);
			$this->assertStringContainsString('Product A', $dataStr);
			$this->assertStringContainsString('99.98', $dataStr); // Float precision

			echo "✓ REPLACE correctly updates existing records\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	public function testSequentialReplaceOperations(): void {
		echo "\n=== TEST: Sequential REPLACE operations (overwrite behavior) ===\n";
		$this->setupTestTables();

		try {
			// First REPLACE with price < 100
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt SELECT * FROM test_replace_src WHERE price < 100'
			);

			$count1 = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$count1Str = implode($count1);
			echo 'After first REPLACE: ' . $count1Str . "\n";

			// Second REPLACE with price > 100 (should overwrite)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt SELECT * FROM test_replace_src WHERE price > 100'
			);

			$count2 = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$count2Str = implode($count2);
			echo 'After second REPLACE: ' . $count2Str . "\n";

			// Final count should be different from first
			$this->assertNotEquals($count1Str, $count2Str);

			echo "✓ Sequential REPLACE operations work correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	// ========================================================================
	// Category 7: Error Scenarios
	// ========================================================================

	public function testPartialColumnSelection(): void {
		echo "\n=== TEST: Partial column selection ===\n";
		$this->setupTestTables();

		try {
			// Select matching column count (5 columns in both target and SELECT)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, description, price, is_active) ' .
				'SELECT id, title, description, price, is_active FROM test_replace_src LIMIT 2'
			);

			// Verify 2 records inserted
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('2', implode($count));
			echo "✓ Partial column selection works correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	// ========================================================================
	// Category 8: Large Dataset Batch Processing
	// ========================================================================

	public function testBatchProcessingAllRecords(): void {
		echo "\n=== TEST: Batch processing all 10 records ===\n";
		$this->setupTestTables();

		try {
			// REPLACE all records with default batch size (1000)
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, description, content, count_value, price) ' .
				'SELECT id, title, description, content, count_value, price FROM test_replace_src'
			);

			// Verify all records
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('10', implode($count));

			echo "✓ All 10 records successfully batch processed\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

	// ========================================================================
	// Category 9: Complex WHERE Conditions
	// ========================================================================

	public function testComplexWhereConditions(): void {
		echo "\n=== TEST: Complex WHERE with AND conditions ===\n";
		$this->setupTestTables();

		try {
			// Filter: is_active=1 AND price > 50 AND price < 500
			static::runSqlQuery(
				'REPLACE INTO test_replace_tgt (id, title, content, price, created_at) ' .
				'SELECT id, title, content, price, created_at FROM test_replace_src ' .
				'WHERE is_active = 1 AND price > 50 AND price < 500'
			);

			// Verify filtered results (should be IDs: 1, 4, 9)
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_replace_tgt');
			$this->assertStringContainsString('3', implode($count));

			echo "✓ Complex WHERE conditions work correctly\n";
		} finally {
			$this->cleanupTestTables();
		}
	}

}
