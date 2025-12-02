<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ReplaceSelect;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * REPLACE INTO ... SELECT ... FROM payload parser and validator
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	public string $targetTable;
	public string $selectQuery;
	public ?string $cluster = null;
	public string $originalQuery;
	public ?int $selectLimit = null;
	public ?int $selectOffset = null;

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
		// Match pattern: REPLACE INTO table SELECT ... FROM source
		// Support both regular and comment-style batch size syntax
		return preg_match(
			'/^\s*REPLACE\s+INTO\s+\S+\s+SELECT\s+.*?\s+FROM\s+\S+/i',
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

		Buddy::debug('Parsing REPLACE SELECT query: ' . $request->payload);

		try {
			// Use regex parsing for REPLACE INTO ... SELECT
			Buddy::debug('Starting regex parsing');
			$self->parseWithRegex($request->payload);
			Buddy::debug('Regex parsing successful. Target table: ' . $self->targetTable);
		} catch (\Exception $e) {
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
		// Extract target table: REPLACE INTO [cluster:]table
		Buddy::debug('Extracting target table from SQL');
		if (!preg_match('/REPLACE\s+INTO\s+([^\s]+)/i', $sql, $matches)) {
			$errorMsg = 'Cannot extract target table from SQL. Pattern did not match';
			Buddy::debug("Target table extraction failed: $errorMsg");
			throw GenericError::create($errorMsg);
		}

		$tableSpec = $matches[1];
		Buddy::debug("Extracted table specification: $tableSpec");
		$this->parseTargetTableFromString($tableSpec);

		// Extract SELECT query
		// Use 's' flag (DOTALL) to make '.' match newline characters for multi-line queries
		Buddy::debug('Extracting SELECT query from SQL');
		if (!preg_match(
			'/REPLACE\s+INTO\s+\S+\s+(SELECT\s+.+?)(?:\s*\/\*.*?\*\/\s*)?(?:;?\s*)$/is',
			$sql,
			$matches
		)) {
			$errorMsg = 'Cannot extract SELECT query from SQL. Pattern did not match';
			Buddy::debug("SELECT query extraction failed: $errorMsg");
			throw GenericError::create($errorMsg);
		}

		$this->selectQuery = trim($matches[1]);
		Buddy::debug('Extracted SELECT query: ' . substr($this->selectQuery, 0, 100));

		// Extract SELECT limit and offset if present
		$this->selectLimit = $this->extractSelectLimit($this->selectQuery);
		$this->selectOffset = $this->extractSelectOffset($this->selectQuery);
		if ($this->selectLimit !== null) {
			Buddy::debug("Extracted SELECT LIMIT: {$this->selectLimit}");
		}
		if ($this->selectOffset !== null) {
			Buddy::debug("Extracted SELECT OFFSET: {$this->selectOffset}");
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
		Buddy::debug("Parsing target table specification: $tableName");

		if (str_contains($tableName, ':')) {
			Buddy::debug('Table specification contains cluster prefix');
			[$this->cluster, $this->targetTable] = explode(':', $tableName, 2);
			$this->cluster = trim($this->cluster, '`"\'');
			$this->targetTable = trim($this->targetTable, '`"\'');
			Buddy::debug("Parsed cluster: {$this->cluster}, table: {$this->targetTable}");
		} else {
			$this->targetTable = trim($tableName, '`"\'');
			Buddy::debug("Parsed table: {$this->targetTable} (no cluster)");
		}

		if (empty($this->targetTable)) {
			$errorMsg = 'Empty target table name after parsing';
			Buddy::debug("Target table validation failed: $errorMsg");
			throw GenericError::create($errorMsg);
		}
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
			return "`{$this->cluster}`:{$this->targetTable}";
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
		Buddy::debug('Starting payload validation');

		// Check target table
		Buddy::debug('Validating target table name');
		if (empty($this->targetTable)) {
			$errorMsg = 'Target table name cannot be empty';
			Buddy::debug("Validation failed: $errorMsg");
			throw GenericError::create($errorMsg);
		}
		Buddy::debug('Target table validation passed: ' . $this->targetTable);

		// Check SELECT query exists
		Buddy::debug('Validating SELECT query existence');
		if (empty($this->selectQuery)) {
			$errorMsg = 'SELECT query cannot be empty';
			Buddy::debug("Validation failed: $errorMsg");
			throw GenericError::create($errorMsg);
		}
		Buddy::debug('SELECT query exists');

		// Check batch size
		Buddy::debug("Validating batch size: {$this->batchSize}");
		$maxBatchSize = Config::getMaxBatchSize();
		if ($this->batchSize < 1 || $this->batchSize > $maxBatchSize) {
			$errorMsg = sprintf(
				'Batch size must be between 1 and %d, got %d',
				$maxBatchSize,
				$this->batchSize
			);
			Buddy::debug("Batch size validation failed: $errorMsg");
			throw GenericError::create($errorMsg);
		}
		Buddy::debug('Batch size validation passed');

		// Basic SELECT query validation - starts with SELECT
		Buddy::debug('Validating SELECT query format');
		if (!preg_match('/^\s*SELECT\s+/i', $this->selectQuery)) {
			$errorMsg = 'Query must start with SELECT';
			Buddy::debug("SELECT format validation failed: $errorMsg. Query: " . substr($this->selectQuery, 0, 50));
			throw GenericError::create($errorMsg);
		}
		Buddy::debug('SELECT keyword found');

		// Check for FROM clause
		Buddy::debug('Validating FROM clause presence');
		if (!preg_match('/\s+FROM\s+/i', $this->selectQuery)) {
			$errorMsg = 'SELECT query must contain FROM clause';
			Buddy::debug("FROM clause validation failed: $errorMsg. Query: " . substr($this->selectQuery, 0, 50));
			throw GenericError::create($errorMsg);
		}
		Buddy::debug('FROM clause found');

		Buddy::debug('Payload validation completed successfully');
		Buddy::debug(
			'Final payload state: targetTable=' . $this->targetTable .
			', cluster=' . ($this->cluster ?? 'none') .
			', batchSize=' . $this->batchSize .
			', selectQuery=' . substr($this->selectQuery, 0, 50)
		);
	}
}
