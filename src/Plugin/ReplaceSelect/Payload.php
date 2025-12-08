<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ReplaceSelect;

use Exception;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * REPLACE INTO ... SELECT ... FROM payload parser and validator
 *
 * Supports two syntaxes:
 * 1. REPLACE INTO table SELECT ... FROM source
 * 2. REPLACE INTO table (col1, col2, col3) SELECT ... FROM source
 *
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	public string $targetTable;
	public string $selectQuery;
	public ?string $cluster = null;
	public string $originalQuery;
	public ?int $selectLimit = null;
	public int $batchSize = 1000;
	public ?int $selectOffset = null;
	/** @var array<int,string>|null Column list for REPLACE INTO table (col1, col2, col3) syntax */
	public ?array $replaceColumnList = null;

	/**
	 * Get description for this plugin
	 *
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables REPLACE INTO ... SELECT ... FROM operations with batch processing';
	}

	/**
	 * Check if request matches this plugin
	 *
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		// Match pattern: REPLACE INTO table [(...)] SELECT ... FROM source
		// Supports both syntaxes:
		// 1. REPLACE INTO table SELECT ... FROM source
		// 2. REPLACE INTO table (col1, col2) SELECT ... FROM source
		// Uses word characters for table name to prevent greedy matching of parentheses
		return preg_match(
			'/^\s*REPLACE\s+INTO\s+\w+(?::\w+)?\s*(?:\([^)]+\))?\s+SELECT\s+.*?\s+FROM\s+\S+/is',
			$request->payload
		) === 1;
	}

	/**
	 * Create payload from request
	 *
	 * @param Request $request
	 * @return static
	 * @throws GenericError
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$self->originalQuery = $request->payload;
		$self->batchSize = (int)($_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] ?? 1000);
		try {
			// Use regex parsing for REPLACE INTO ... SELECT
			$self->parseWithRegex($request->payload);
		} catch (Exception $e) {
			Buddy::debug('Payload parsing failed: ' . $e->getMessage());
			throw GenericError::create('Failed to parse REPLACE SELECT query: ' . $e->getMessage());
		}

		return $self;
	}

	/**
	 * Parse using regex fallback
	 *
	 * @param string $sql
	 * @return void
	 * @throws GenericError
	 */
	private function parseWithRegex(string $sql): void {
		// Extract target table and optional column list: REPLACE INTO [cluster:]table [(col1, col2, col3)]
		if (!preg_match('/REPLACE\s+INTO\s+(\w+(?::\w+)?)\s*(?:\(([^)]+)\))?/i', $sql, $matches)) {
			$errorMsg = 'Cannot extract target table from SQL. Pattern did not match';
			throw GenericError::create($errorMsg);
		}

		$tableSpec = $matches[1];
		$this->parseTargetTableFromString($tableSpec);

		// Extract column list if present
		if (!empty($matches[2])) {
			$this->replaceColumnList = $this->parseColumnList($matches[2]);
		} else {
			$this->replaceColumnList = null;
		}

		// Extract SELECT query
		// Use 's' flag (DOTALL) to make '.' match newline characters for multi-line queries
		if (!preg_match(
			'/REPLACE\s+INTO\s+[\w]+(?::[\w]+)?\s*(?:\([^)]+\))?\s+(SELECT\s+.+?)(?:\s*\/\*.*?\*\/\s*)?;?\s*$/is',
			$sql,
			$matches
		)) {
			$errorMsg = 'Cannot extract SELECT query from SQL. Pattern did not match';
			throw GenericError::create($errorMsg);
		}

		$this->selectQuery = trim($matches[1]);

		// Extract SELECT limit and offset if present
		$this->selectLimit = $this->extractSelectLimit($this->selectQuery);
		$this->selectOffset = $this->extractSelectOffset($this->selectQuery);
	}

	/**
	 * Parse target table name and extract cluster if present
	 *
	 * @param string $tableName
	 * @return void
	 * @throws GenericError
	 */
	private function parseTargetTableFromString(string $tableName): void {
		if (str_contains($tableName, ':')) {
			[$this->cluster, $this->targetTable] = explode(':', $tableName, 2);
			$this->cluster = trim($this->cluster, '`"\'');
			$this->targetTable = trim($this->targetTable, '`"\'');
		} else {
			$this->targetTable = trim($tableName, '`"\'');
		}

		if (empty($this->targetTable)) {
			$errorMsg = 'Empty target table name after parsing';
			throw GenericError::create($errorMsg);
		}
	}

	/**
	 * Parse column list from REPLACE INTO table (col1, col2, col3) syntax
	 *
	 * @param string $columnListStr Column list string like "col1, col2, col3"
	 * @return array<int,string> Array of column names
	 * @throws GenericError
	 */
	private function parseColumnList(string $columnListStr): array {
		$columnListStr = trim($columnListStr);
		if (empty($columnListStr)) {
			throw GenericError::create('Empty column list in REPLACE INTO');
		}

		// Split by comma, handling whitespace
		$columns = array_map('trim', explode(',', $columnListStr));

		// Validate and clean column names (remove quotes)
		$cleanedColumns = [];
		foreach ($columns as $col) {
			if (empty($col)) {
				throw GenericError::create('Empty column name in column list');
			}
			// Remove quotes (backticks, single, double)
			$cleanedCol = trim($col, '`"\'');
			if (empty($cleanedCol)) {
				throw GenericError::create('Column name contains only quotes');
			}
			$cleanedColumns[] = $cleanedCol;
		}

		return $cleanedColumns;
	}

	/**
	 * Extract LIMIT value from SELECT query if present
	 *
	 * @param string $selectQuery
	 * @return int|null The LIMIT value or null if not present
	 */
	private function extractSelectLimit(string $selectQuery): ?int {
		// Match LIMIT followed by number, with optional OFFSET clause
		if (preg_match('/\s+LIMIT\s+(\d+)(?:\s+OFFSET\s+\d+)?\s*$/i', $selectQuery, $matches)) {
			return (int)$matches[1];
		}
		return null;
	}

	/**
	 * Extract OFFSET value from SELECT query if present
	 *
	 * @param string $selectQuery
	 * @return int|null The OFFSET value or null if not present
	 */
	private function extractSelectOffset(string $selectQuery): ?int {
		// Match OFFSET after LIMIT
		if (preg_match('/\s+LIMIT\s+\d+\s+OFFSET\s+(\d+)\s*$/i', $selectQuery, $matches)) {
			return (int)$matches[1];
		}
		return null;
	}

	/**
	 * Get target table name with cluster prefix if present
	 *
	 * @return string
	 */
	public function getTargetTableWithCluster(): string {
		if ($this->cluster) {
			return "`$this->cluster`:$this->targetTable";
		}
		return $this->targetTable;
	}

	/**
	 * Validate payload data
	 *
	 * @return void
	 * @throws GenericError
	 */
	public function validate(): void {
		// Check target table
		if (empty($this->targetTable)) {
			$errorMsg = 'Target table name cannot be empty';
			throw GenericError::create($errorMsg);
		}

		// Check SELECT query exists
		if (empty($this->selectQuery)) {
			$errorMsg = 'SELECT query cannot be empty';
			throw GenericError::create($errorMsg);
		}

		// Check batch size (from environment config)
		if ($this->batchSize < 1) {
			throw GenericError::create('Batch size validation failed: Batch size must be greater than 1');
		}

		// Basic SELECT query validation - starts with SELECT
		if (!preg_match('/^\s*SELECT\s+/i', $this->selectQuery)) {
			$errorMsg = 'Query must start with SELECT';
			throw GenericError::create($errorMsg);
		}

		// Check for FROM clause
		if (!preg_match('/\s+FROM\s+/i', $this->selectQuery)) {
			$errorMsg = /** @lang manticore */ 'SELECT query must contain FROM clause';
			throw GenericError::create($errorMsg);
		}
	}
}
