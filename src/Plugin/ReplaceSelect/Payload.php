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
	public int $batchSize;
	public ?string $cluster = null;
	public string $originalQuery;

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
		$self->batchSize = Config::getBatchSize();

		Buddy::debug('Parsing REPLACE SELECT query: ' . $request->payload);

		try {
			// Use regex parsing for REPLACE INTO ... SELECT
			Buddy::debug('Starting regex parsing');
			$self->parseWithRegex($request->payload);
			Buddy::debug('Regex parsing successful. Target table: ' . $self->targetTable);

			// Parse batch size from comment syntax /* BATCH_SIZE 500 */
			Buddy::debug('Parsing batch size');
			$self->parseBatchSize($request->payload);
			Buddy::debug('Batch size: ' . $self->batchSize);
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
		Buddy::debug('Extracting SELECT query from SQL');
		if (!preg_match(
			'/REPLACE\s+INTO\s+\S+\s+(SELECT\s+.*?)(?:\s*\/\*.*?\*\/\s*)?(?:;?\s*)$/i',
			$sql,
			$matches
		)) {
			$errorMsg = 'Cannot extract SELECT query from SQL. Pattern did not match';
			Buddy::debug("SELECT query extraction failed: $errorMsg");
			throw GenericError::create($errorMsg);
		}

		$this->selectQuery = trim($matches[1]);
		Buddy::debug('Extracted SELECT query: ' . substr($this->selectQuery, 0, 100));
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
	 * Parse batch size from SQL comment
	 *
	 * @param string $sql
	 * @return void
	 */
	private function parseBatchSize(string $sql): void {
		// Parse comment-style batch size: /* BATCH_SIZE 500 */
		if (preg_match('/\/\*\s*BATCH_SIZE\s+(\d+)\s*\*\//i', $sql, $matches)) {
			$requestedSize = (int)$matches[1];
			$maxSize = Config::getMaxBatchSize();
			$this->batchSize = max(1, min($requestedSize, $maxSize));
			Buddy::debug("Batch size from comment: requested=$requestedSize, max=$maxSize, final={$this->batchSize}");
			return;
		}

		// Legacy support for space-separated BATCH_SIZE (deprecated)
		if (!preg_match('/\s+BATCH_SIZE\s+(\d+)\s*$/i', $sql, $matches)) {
			Buddy::debug('No custom batch size found, using default: ' . $this->batchSize);
			return;
		}

		$requestedSize = (int)$matches[1];
		$maxSize = Config::getMaxBatchSize();
		$this->batchSize = max(1, min($requestedSize, $maxSize));
		Buddy::debug("Batch size from legacy syntax: requested=$requestedSize, max=$maxSize, final={$this->batchSize}");
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
