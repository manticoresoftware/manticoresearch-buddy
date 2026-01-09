<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

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
	public bool $hasOrderBy = false;

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
		$matchPattern = '/^\\s*REPLACE\\s+INTO\\s+\\w+(?::\\w+)?\\s*(?:\\([^)]+\\))?\\s+SELECT\\s+.*?\\s+FROM\\s+\\S+/is';
		return preg_match($matchPattern, $request->payload) === 1;
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
		$batchSize = getenv('BUDDY_REPLACE_SELECT_BATCH_SIZE');
		if ($batchSize === false || $batchSize === '') {
			$batchSize = 1000;
		}
		$self->batchSize = (int)$batchSize;
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
		$parsePattern = '/^\\s*REPLACE\\s+INTO\\s+(?<table>\\w+(?::\\w+)?)\\s*(?:\\((?<columns>[^)]*)\\))?\\s+'
			. '(?<select>SELECT\\s+.*?\\s+FROM\\s+\\S.+?)(?:\\s*\\/\\*.*?\\*\\/\\s*)?;?\\s*$/is';

		if (!preg_match($parsePattern, $sql, $matches)) {
			throw GenericError::create('Cannot parse SQL. Pattern did not match');
		}

		$this->parseTargetTableFromString($matches['table']);

		$this->replaceColumnList = isset($matches['columns']) && trim($matches['columns']) !== ''
			? $this->parseColumnList($matches['columns'])
			: null;

		$this->selectQuery = trim($matches['select']);
		$this->hasOrderBy = stripos($this->selectQuery, 'ORDER BY') !== false;

		$this->selectLimit = null;
		$this->selectOffset = null;
		$limitOffsetPattern = '/\\s+LIMIT\\s+(?<limit>\\d+)(?:\\s+OFFSET\\s+(?<offset>\\d+))?\\s*$/i';
		if (preg_match($limitOffsetPattern, $this->selectQuery, $limitMatches)) {
			$this->selectLimit = (int)$limitMatches['limit'];
			if (isset($limitMatches['offset']) && $limitMatches['offset'] !== '') {
				$this->selectOffset = (int)$limitMatches['offset'];
			}
		}
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
