<?php declare(strict_types=1);

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for REPLACE SELECT with field aliases
 *
 * Tests real data scenarios with field aliases in SELECT clauses
 */
class ReplaceSelectAliasTest extends TestCase {

	use TestFunctionalTrait;

	// ========================================================================
	// Simple Alias Tests
	// ========================================================================

	public function testSimpleFieldAlias(): void {
		echo "\n=== TEST: Simple field alias ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'name TEXT STORED' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'Original Name')");

		// Create target table with different field name
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'product_name TEXT STORED' .
			')'
		);

		try {
			// Use alias to rename field
			static::runSqlQuery(
				'REPLACE INTO target (id, product_name) SELECT id, name AS product_name FROM source'
			);

			// Verify data was correctly aliased
			$result = static::runSqlQuery('SELECT product_name FROM target WHERE id = 1');
			$this->assertStringContainsString('Original Name', implode($result));

			echo "✓ Simple field alias successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testMultipleAliases(): void {
		echo "\n=== TEST: Multiple field aliases ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'name TEXT STORED, ' .
			'cost FLOAT' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'Product A', 99.99)");

		// Create target table with different field names
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'product_name TEXT STORED, ' .
			'product_price FLOAT' .
			')'
		);

		try {
			// Use multiple aliases
			static::runSqlQuery(
				'REPLACE INTO target (id, product_name, product_price) ' .
				'SELECT id, name AS product_name, cost AS product_price FROM source'
			);

			// Verify all aliases worked
			$result = static::runSqlQuery('SELECT product_name, product_price FROM target WHERE id = 1');
			$resultStr = implode($result);
			$this->assertStringContainsString('Product A', $resultStr);
			// Float precision: 99.99 may be stored as 99.989998 due to single-precision float
			$this->assertMatchesRegularExpression('/99\.9[89]\d*/', $resultStr, 'Float should be approximately 99.99');

			echo "✓ Multiple aliases successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testFunctionAliasDataPreservation(): void {
		echo "\n=== TEST: Function alias data preservation ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'price FLOAT' .
			')'
		);
		static::runSqlQuery('INSERT INTO source VALUES (1, 99.49)');

		// Create target table
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'rounded_price FLOAT' .
			')'
		);

		try {
			// Use CEIL function with alias
			static::runSqlQuery(
				'REPLACE INTO target (id, rounded_price) ' .
				'SELECT id, CEIL(price) AS rounded_price FROM source'
			);

			// Verify CEIL worked (99.49 → 100)
			$result = static::runSqlQuery('SELECT rounded_price FROM target WHERE id = 1');
			$this->assertStringContainsString('100', implode($result));

			echo "✓ Function alias data preservation successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testMultipleAliasesWithData(): void {
		echo "\n=== TEST: Multiple aliases with real data ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'first_name TEXT STORED, ' .
			'last_name TEXT STORED, ' .
			'age INT' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'John', 'Doe', 30)");
		static::runSqlQuery("INSERT INTO source VALUES (2, 'Jane', 'Smith', 25)");

		// Create target table with concatenated name
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'full_name TEXT STORED, ' .
			'user_age INT' .
			')'
		);

		try {
			// Use aliases to rename and transform
			static::runSqlQuery(
				'REPLACE INTO target (id, full_name, user_age) ' .
				'SELECT id, first_name AS full_name, age AS user_age FROM source'
			);

			// Verify data (note: ORDER BY requires sortable fields, so we check without explicit ordering)
			$result = static::runSqlQuery('SELECT full_name, user_age FROM target');
			$resultStr = implode($result);
			$this->assertStringContainsString('John', $resultStr);
			$this->assertStringContainsString('30', $resultStr);
			$this->assertStringContainsString('Jane', $resultStr);
			$this->assertStringContainsString('25', $resultStr);

			echo "✓ Multiple aliases with data successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testAliasWithWhereClause(): void {
		echo "\n=== TEST: Alias with WHERE clause ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'name TEXT STORED, ' .
			'price FLOAT, ' .
			'active BOOL' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'Active Product', 99.99, 1)");
		static::runSqlQuery("INSERT INTO source VALUES (2, 'Inactive Product', 49.99, 0)");

		// Create target table
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'product_name TEXT STORED, ' .
			'product_price FLOAT' .
			')'
		);

		try {
			// Use aliases with WHERE filter
			static::runSqlQuery(
				'REPLACE INTO target (id, product_name, product_price) ' .
				'SELECT id, name AS product_name, price AS product_price ' .
				'FROM source WHERE active = 1'
			);

			// Verify only active product was copied
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM target');
			$this->assertStringContainsString('1', implode($count));

			$result = static::runSqlQuery('SELECT product_name FROM target WHERE id = 1');
			$this->assertStringContainsString('Active Product', implode($result));

			echo "✓ Alias with WHERE clause successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testAliasWithOrderBy(): void {
		echo "\n=== TEST: Alias with ORDER BY ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'name TEXT STORED, ' .
			'price FLOAT' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'Product C', 149.99)");
		static::runSqlQuery("INSERT INTO source VALUES (2, 'Product A', 99.99)");
		static::runSqlQuery("INSERT INTO source VALUES (3, 'Product B', 49.99)");

		// Create target table
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'product_name TEXT STORED, ' .
			'product_price FLOAT' .
			')'
		);

		try {
			// Use aliases with ORDER BY in source query
			// Note: ORDER BY in SELECT requires source table to have sortable fields
			static::runSqlQuery(
				'REPLACE INTO target (id, product_name, product_price) ' .
				'SELECT id, name AS product_name, price AS product_price ' .
				'FROM source ORDER BY price ASC'
			);

			// Verify all products were inserted
			$result = static::runSqlQuery('SELECT product_name FROM target');
			$resultStr = implode($result);
			// Check that all products exist
			$this->assertStringContainsString('Product B', $resultStr);
			$this->assertStringContainsString('Product A', $resultStr);
			$this->assertStringContainsString('Product C', $resultStr);

			echo "✓ Alias with ORDER BY successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testAliasWithComplexExpression(): void {
		echo "\n=== TEST: Alias with complex expression ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'price FLOAT, ' .
			'tax_rate FLOAT' .
			')'
		);
		static::runSqlQuery('INSERT INTO source VALUES (1, 100.00, 0.10)');

		// Create target table
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'total_price FLOAT' .
			')'
		);

		try {
			// Use complex expression with alias
			static::runSqlQuery(
				'REPLACE INTO target (id, total_price) ' .
				'SELECT id, price * (1 + tax_rate) AS total_price FROM source'
			);

			// Verify calculation (100 * 1.10 = 110)
			$result = static::runSqlQuery('SELECT total_price FROM target WHERE id = 1');
			$resultStr = implode($result);
			$this->assertStringContainsString('110', $resultStr);

			echo "✓ Complex expression alias successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	// ========================================================================
	// Edge Cases
	// ========================================================================

	public function testAliasWithSpecialCharacters(): void {
		echo "\n=== TEST: Alias with special characters ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'description TEXT STORED' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'Product with \"quotes\" and symbols: @#$%')");

		// Create target table
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'product_desc TEXT STORED' .
			')'
		);

		try {
			// Use alias with special characters
			static::runSqlQuery(
				'REPLACE INTO target (id, product_desc) ' .
				'SELECT id, description AS product_desc FROM source'
			);

			// Verify special characters preserved
			$result = static::runSqlQuery('SELECT product_desc FROM target WHERE id = 1');
			$resultStr = implode($result);
			$this->assertStringContainsString('quotes', $resultStr);
			$this->assertStringContainsString('@#$', $resultStr);

			echo "✓ Alias with special characters successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testAliasWithNullValues(): void {
		echo "\n=== TEST: Alias with NULL values ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'name TEXT STORED, ' .
			'description TEXT STORED' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'Product Name', NULL)");

		// Create target table
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'product_name TEXT STORED, ' .
			'product_desc TEXT STORED' .
			')'
		);

		try {
			// Use alias with NULL value
			static::runSqlQuery(
				'REPLACE INTO target (id, product_name, product_desc) ' .
				'SELECT id, name AS product_name, description AS product_desc FROM source'
			);

			// Verify NULL preserved (Manticore may represent NULL as empty string or NULL)
			$result = static::runSqlQuery('SELECT product_desc FROM target WHERE id = 1');
			$resultStr = implode($result);
			// Check that either NULL is represented or the field is empty
			$this->assertTrue(
				strpos($resultStr, 'NULL') !== false || strlen(trim($resultStr)) < 10,
				'NULL value should be represented as NULL or empty'
			);

			echo "✓ Alias with NULL values successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	// ========================================================================
	// Error Cases
	// ========================================================================

	public function testAliasWithoutColumnListSuccess(): void {
		echo "\n=== TEST: Alias without column list (position-based mapping) ===\n";

		// Create source table
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'name TEXT STORED' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'Product Name'), (2, 'Another Product')");

		// Create target table with different field name but compatible types by position
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'product_name TEXT STORED' .
			')'
		);

		try {
			// Should succeed - position-based mapping with compatible types
			static::runSqlQuery('REPLACE INTO target SELECT id, name AS product_name FROM source');

			// Verify successful data transfer
			$count = static::runSqlQuery('SELECT COUNT(*) as cnt FROM target');
			$this->assertStringContainsString('2', implode($count));

			// Verify data integrity - alias mapping worked correctly
			$result = static::runSqlQuery('SELECT product_name FROM target WHERE id = 1');
			$this->assertStringContainsString('Product Name', implode($result));

			// Verify second record
			$result2 = static::runSqlQuery('SELECT product_name FROM target WHERE id = 2');
			$this->assertStringContainsString('Another Product', implode($result2));

			echo "✓ Alias without column list succeeded with position-based mapping\n";
			echo "✓ Data correctly mapped: source.name -> target.product_name\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}
}
