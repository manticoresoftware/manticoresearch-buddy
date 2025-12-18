<?php declare(strict_types=1);

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

/**
 * Diagnostic test for chunk processing data loss investigation
 */
class ChunkDiagnosticTest extends TestCase
{
	use TestFunctionalTrait;

	/**
	 * Investigate the data loss issue in chunk processing
	 */
	public function testChunkProcessingDataLoss(): void {
		echo "\n=== DIAGNOSTIC: Chunk Processing Data Loss Investigation ===\n";

		$this->setupDiagnosticTables();

		try {
			// Test with different record counts and chunk sizes
			$testCases = [
				['records' => 100, 'chunkSize' => 25, 'expected' => 100],  // 4 chunks
				['records' => 200, 'chunkSize' => 50, 'expected' => 200],  // 4 chunks
				['records' => 500, 'chunkSize' => 50, 'expected' => 500],  // 10 chunks
				['records' => 1000, 'chunkSize' => 50, 'expected' => 1000], // 20 chunks
				['records' => 1000, 'chunkSize' => 100, 'expected' => 1000], // 10 chunks
			];

			foreach ($testCases as $index => $testCase) {
				echo "\n--- Test Case $index: {$testCase['records']} ".
					"records, chunk size {$testCase['chunkSize']} ---\n";

				// Set environment variable
				putenv("BUDDY_REPLACE_SELECT_CHUNK_SIZE={$testCase['chunkSize']}");

				// Clean tables
				static::runSqlQuery('TRUNCATE TABLE test_source_diag');
				static::runSqlQuery("DROP TABLE IF EXISTS test_target_diag_$index");
				static::runSqlQuery("CREATE TABLE test_target_diag_$index (id BIGINT, title TEXT, price FLOAT)");

				// Insert test records using batch insert for better performance
				$values = [];
				for ($i = 1; $i <= $testCase['records']; $i++) {
					$values[] = "($i, 'Product $i', " . ($i * 1.5) . ')';
				}
				$batchInsertQuery = 'INSERT INTO test_source_diag (id, title, price) VALUES ' . implode(', ', $values);
				static::runSqlQuery($batchInsertQuery);

				echo "✓ Inserted {$testCase['records']} records\n";

				// Execute REPLACE SELECT
				static::runSqlQuery("REPLACE INTO test_target_diag_$index SELECT * FROM test_source_diag");

				// Check results
				$sourceCount = static::runSqlQuery('SELECT COUNT(*) as cnt FROM test_source_diag');
				$targetCount = static::runSqlQuery("SELECT COUNT(*) as cnt FROM test_target_diag_$index");

				$sourceCountValue = $this->extractCount($sourceCount);
				$targetCountValue = $this->extractCount($targetCount);

				echo "Source: $sourceCountValue, Target: $targetCountValue\n";

				// Validate results with proper assertions
				$this->assertEquals(
					$testCase['expected'], $sourceCountValue,
					"Source count should match expected records for test case $index"
				);
				$this->assertEquals(
					$testCase['expected'], $targetCountValue,
					"Target count should match expected records for test case $index"
				);
				$this->assertEquals(
					$sourceCountValue, $targetCountValue,
					"Source and target counts should be equal for test case $index"
				);

				// Additional validation for data integrity
				if ($targetCountValue > 0) {
					$minId = static::runSqlQuery("SELECT MIN(id) as min_id FROM test_target_diag_$index");
					$maxId = static::runSqlQuery("SELECT MAX(id) as max_id FROM test_target_diag_$index");

					$minIdValue = $this->extractIdValue($minId);
					$maxIdValue = $this->extractIdValue($maxId);

					$this->assertEquals(
						1, $minIdValue,
						"Minimum ID should be 1 for test case $index"
					);
					$this->assertEquals(
						$testCase['expected'], $maxIdValue,
						"Maximum ID should match expected records for test case $index"
					);

					echo 'ID Range in target: ' . $minIdValue . ' to ' . $maxIdValue . "\n";
				}

				echo "✓ SUCCESS: All records copied correctly\n";
			}
		} finally {
			$this->cleanupDiagnosticTables();
		}
	}

	/**
	 * Extract count value from SQL query result
	 * @param array<string> $result MySQL CLI output lines
	 * @return int
	 */
	private function extractCount(array $result): int {
		$resultString = implode('', $result);
		preg_match('/\|\s*(\d+)\s*\|/', $resultString, $matches);
		return isset($matches[1]) ? (int)$matches[1] : 0;
	}

	/**
	 * Extract ID value from SQL query result
	 * @param array<string> $result MySQL CLI output lines
	 * @return int
	 */
	private function extractIdValue(array $result): int {
		$resultString = implode('', $result);
		preg_match('/\|\s*(\d+)\s*\|/', $resultString, $matches);
		return isset($matches[1]) ? (int)$matches[1] : 0;
	}

	private function setupDiagnosticTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_source_diag');

		static::runSqlQuery(
			'CREATE TABLE test_source_diag (id BIGINT, title TEXT, price FLOAT)'
		);
	}

	private function cleanupDiagnosticTables(): void {
		static::runSqlQuery('DROP TABLE IF EXISTS test_source_diag');

		// Clean up target tables
		for ($i = 0; $i < 10; $i++) {
			static::runSqlQuery("DROP TABLE IF EXISTS test_target_diag_$i");
		}
	}
}
