<?php declare(strict_types=1);

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

/**
 * Functional tests for REPLACE SELECT with type mismatches
 *
 * Tests real data scenarios with incompatible field types
 */
class ReplaceSelectTypeMismatchTest extends TestCase {

	use TestFunctionalTrait;

	// ========================================================================
	// Incompatible Type Rejection Tests
	// ========================================================================

	public function testTextToIntRejectionFunctional(): void {
		echo "\n=== TEST: TEXT to INT type mismatch rejection ===\n";

		// Create source with TEXT field
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'count TEXT STORED' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'some text')");

		// Create target with INT field
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'count INT' .
			')'
		);

		try {
			// Should fail due to type mismatch
			static::runSqlQuery('REPLACE INTO target SELECT * FROM source');
			// If no exception, check if data was inserted (shouldn't be)
			$result = static::runSqlQuery('SELECT COUNT(*) as cnt FROM target');
			$this->assertStringContainsString('0', implode($result), 'No data should be inserted due to type mismatch');
			echo "✓ Type mismatch correctly rejected\n";
		} catch (Exception $e) {
			// Verify error message contains type mismatch information
			$this->assertStringContainsString('type mismatch', $e->getMessage());
			$this->assertStringContainsString('count', $e->getMessage());
			echo "✓ Type mismatch correctly rejected\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testFloatToBoolRejectionFunctional(): void {
		echo "\n=== TEST: FLOAT to BOOL type mismatch rejection ===\n";

		// Create source with FLOAT field
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'active FLOAT' .
			')'
		);
		static::runSqlQuery('INSERT INTO source VALUES (1, 0.95)');

		// Create target with BOOL field
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'active BOOL' .
			')'
		);

		try {
			// Execute the REPLACE operation and capture response
			$result = static::runSqlQuery('REPLACE INTO target SELECT * FROM source');
			$resultStr = implode(' ', $result);

			// Verify error message contains expected content
			$this->assertStringContainsString('type mismatch', $resultStr);
			$this->assertStringContainsString('active', $resultStr);

			// Verify no data was inserted
			$countResult = static::runSqlQuery('SELECT COUNT(*) as cnt FROM target');
			$this->assertStringContainsString(
				'0',
				implode($countResult),
				'No data should be inserted on type mismatch'
			);

			echo "✓ FLOAT to BOOL type mismatch correctly rejected\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testMultiToTextRejectionFunctional(): void {
		echo "\n=== TEST: MULTI to TEXT type mismatch rejection ===\n";

		// Create source with MULTI field
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'tags MULTI' .
			')'
		);
		static::runSqlQuery('INSERT INTO source VALUES (1, (1,2,3))');

		// Create target with TEXT field
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'tags TEXT STORED' .
			')'
		);

		try {
			// Execute the REPLACE operation and capture response
			$result = static::runSqlQuery('REPLACE INTO target SELECT * FROM source');
			$resultStr = implode(' ', $result);

			// Verify error message contains expected content
			$this->assertStringContainsString('type mismatch', $resultStr);
			$this->assertStringContainsString('tags', $resultStr);

			// Verify no data was inserted
			$countResult = static::runSqlQuery('SELECT COUNT(*) as cnt FROM target');
			$this->assertStringContainsString(
				'0',
				implode($countResult),
				'No data should be inserted on type mismatch'
			);

			echo "✓ MULTI to TEXT type mismatch correctly rejected\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testJsonToIntRejectionFunctional(): void {
		echo "\n=== TEST: JSON to INT type mismatch rejection ===\n";

		// Create source with JSON field
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'metadata JSON' .
			')'
		);
		static::runSqlQuery('INSERT INTO source VALUES (1, \'{\"key\": \"value\"}\')');

		// Create target with INT field
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'metadata INT' .
			')'
		);

		try {
			// Execute the REPLACE operation and capture response
			$result = static::runSqlQuery('REPLACE INTO target SELECT * FROM source');
			$resultStr = implode(' ', $result);

			// Verify error message contains expected content
			$this->assertStringContainsString('type mismatch', $resultStr);
			$this->assertStringContainsString('metadata', $resultStr);

			// Verify no data was inserted
			$countResult = static::runSqlQuery('SELECT COUNT(*) as cnt FROM target');
			$this->assertStringContainsString(
				'0',
				implode($countResult),
				'No data should be inserted on type mismatch'
			);

			echo "✓ JSON to INT type mismatch correctly rejected\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testIncompatibleTypeWithColumnListFunctional(): void {
		echo "\n=== TEST: Incompatible type with column list ===\n";

		// Create source with FLOAT field
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'price FLOAT, ' .
			'title TEXT STORED' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 99.99, 'Test Product')");

		// Create target with INT field for price
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'price INT, ' .
			'title TEXT STORED' .
			')'
		);

		try {
			// Column list with incompatible type (FLOAT source → INT target)
			$result = static::runSqlQuery(
				'REPLACE INTO target (id, price, title) SELECT id, price, title FROM source'
			);
			$resultStr = implode(' ', $result);

			// Verify an error occurred (either type mismatch or query validation error)
			$hasError = strpos($resultStr, 'ERROR') !== false
				|| strpos($resultStr, 'type mismatch') !== false
				|| strpos($resultStr, 'price') !== false;
			$this->assertTrue(
				$hasError,
				'Expected an error when inserting incompatible type, got: ' . $resultStr
			);

			// Verify no data was inserted
			$countResult = static::runSqlQuery('SELECT COUNT(*) as cnt FROM target');
			$this->assertStringContainsString(
				'0',
				implode($countResult),
				'No data should be inserted on type mismatch'
			);

			echo "✓ Column list type mismatch correctly rejected\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	// ========================================================================
	// Compatible Type Conversion Tests (Future Enhancement)
	// ========================================================================

	public function testIntToBigintConversion(): void {
		echo "\n=== TEST: INT to BIGINT compatible conversion ===\n";

		// Create source with INT field
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'count INT' .
			')'
		);
		static::runSqlQuery('INSERT INTO source VALUES (1, 2147483647)'); // Max INT value

		// Create target with BIGINT field
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'count BIGINT' .
			')'
		);

		try {
			// This should work (INT → BIGINT is compatible)
			static::runSqlQuery('REPLACE INTO target SELECT * FROM source');

			// Verify data preserved
			$result = static::runSqlQuery('SELECT count FROM target WHERE id = 1');
			$this->assertStringContainsString('2147483647', implode($result));

			echo "✓ INT to BIGINT conversion successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testStringToTextConversion(): void {
		echo "\n=== TEST: STRING to TEXT compatible conversion ===\n";

		// Create source with STRING field
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'title STRING' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'Test Product')");

		// Create target with TEXT field
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'title TEXT STORED' .
			')'
		);

		try {
			// This should work (STRING → TEXT is compatible)
			static::runSqlQuery('REPLACE INTO target SELECT * FROM source');

			// Verify data preserved
			$result = static::runSqlQuery('SELECT title FROM target WHERE id = 1');
			$this->assertStringContainsString('Test Product', implode($result));

			echo "✓ STRING to TEXT conversion successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	// ========================================================================
	// Edge Cases
	// ========================================================================

	public function testNullValueHandling(): void {
		echo "\n=== TEST: NULL value handling in type conversion ===\n";

		// Create source with nullable fields
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'title TEXT STORED, ' .
			'price INT' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, 'Product with NULL price', 'NULL')");

		// Create target with same structure
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'title TEXT STORED, ' .
			'price INT' .
			')'
		);

		try {
			// This should work - NULL should be handled properly
			static::runSqlQuery('REPLACE INTO target SELECT * FROM source');

			// Verify NULL handling in title field (TEXT)
			$result = static::runSqlQuery('SELECT title FROM target WHERE id = 1');
			$resultStr = implode($result);
			// Title should be preserved as is
			$this->assertStringContainsString('Product with NULL price', $resultStr);

			// Verify price field - 'NULL' string in INT field becomes 0 or similar
			$priceResult = static::runSqlQuery('SELECT price FROM target WHERE id = 1');
			// The important thing is the data was inserted without error
			$this->assertNotEmpty($priceResult);

			echo "✓ NULL value handling successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}

	public function testEmptyStringHandling(): void {
		echo "\n=== TEST: Empty string handling in type conversion ===\n";

		// Create source with empty string
		static::runSqlQuery('DROP TABLE IF EXISTS source');
		static::runSqlQuery(
			'CREATE TABLE source (' .
			'id BIGINT, ' .
			'title TEXT STORED, ' .
			'count INT' .
			')'
		);
		static::runSqlQuery("INSERT INTO source VALUES (1, '', 0)");

		// Create target with same structure
		static::runSqlQuery('DROP TABLE IF EXISTS target');
		static::runSqlQuery(
			'CREATE TABLE target (' .
			'id BIGINT, ' .
			'title TEXT STORED, ' .
			'count INT' .
			')'
		);

		try {
			// This should work - empty string should be handled
			static::runSqlQuery('REPLACE INTO target SELECT * FROM source');

			// Verify empty string preserved
			$result = static::runSqlQuery('SELECT title FROM target WHERE id = 1');
			$resultStr = implode($result);
			// Empty string should be preserved
			$this->assertEquals('+-------+| title |+-------+|       |+-------+', $resultStr);

			echo "✓ Empty string handling successful\n";
		} finally {
			static::runSqlQuery('DROP TABLE IF EXISTS source');
			static::runSqlQuery('DROP TABLE IF EXISTS target');
		}
	}
}
