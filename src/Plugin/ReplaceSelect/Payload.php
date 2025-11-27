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

		try {
			// Try to parse with SQL parser first
			if (isset(static::$sqlQueryParser)) {
				$self->parseWithSqlParser();
			} else {
				// Fallback to regex parsing
				$self->parseWithRegex($request->payload);
			}

			// Parse batch size from comment syntax /* BATCH_SIZE 500 */
			$self->parseBatchSize($request->payload);
		} catch (\Exception $e) {
			throw GenericError::create('Failed to parse REPLACE SELECT query: ' . $e->getMessage());
		}

		return $self;
	}

	/**
	 * Parse using SQL parser
	 *
	 * @return void
	 * @throws \InvalidArgumentException
	 */
	private function parseWithSqlParser(): void {
		$payload = static::$sqlQueryParser::getParsedPayload();

		if (!isset($payload['REPLACE'])) {
			throw new \InvalidArgumentException('Invalid REPLACE statement structure');
		}

		$this->parseTargetTable($payload['REPLACE']);
		$this->selectQuery = $this->reconstructSelectFromParsed($payload);
	}

	/**
	 * Parse using regex fallback
	 *
	 * @param string $sql
	 * @return void
	 * @throws \InvalidArgumentException
	 */
	private function parseWithRegex(string $sql): void {
		// Extract target table: REPLACE INTO [cluster:]table
		if (!preg_match('/REPLACE\s+INTO\s+([^\s]+)/i', $sql, $matches)) {
			throw new \InvalidArgumentException('Cannot extract target table');
		}

		$this->parseTargetTableFromString($matches[1]);

		// Extract SELECT query
		if (!preg_match('/REPLACE\s+INTO\s+\S+\s+(SELECT\s+.*?)(?:\s*\/\*.*?\*\/\s*)?(?:;?\s*)$/i', $sql, $matches)) {
			throw new \InvalidArgumentException('Cannot extract SELECT query');
		}

		$this->selectQuery = trim($matches[1]);
	}

	/**
	 * Parse target table from SQL parser array
	 *
	 * @param array<int,array<string,mixed>> $replaceClause
	 * @return void
	 * @throws \InvalidArgumentException
	 */
	private function parseTargetTable(array $replaceClause): void {
		foreach ($replaceClause as $item) {
			if (!is_array($item) || !isset($item['expr_type']) || $item['expr_type'] !== 'table') {
				continue;
			}

			$tableName = '';
			if (isset($item['no_quotes']) && is_array($item['no_quotes']) && isset($item['no_quotes']['parts'][0])) {
				$tableName = (string)$item['no_quotes']['parts'][0];
			} elseif (isset($item['table'])) {
				$tableName = (string)($item['table'] ?? '');
			}
			$this->parseTargetTableFromString($tableName);
			return;
		}
		throw new \InvalidArgumentException('Cannot parse target table name');
	}

	/**
	 * Parse target table name and extract cluster if present
	 *
	 * @param string $tableName
	 * @return void
	 * @throws \InvalidArgumentException
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
			throw new \InvalidArgumentException('Empty target table name');
		}
	}

	/**
	 * Reconstruct SELECT query from parsed payload
	 *
	 * @param array<string,mixed> $payload
	 * @return string
	 */
	private function reconstructSelectFromParsed(array $payload): string {
		// This is a simplified reconstruction - for complex queries,
		// we might need to use PHPSQLCreator or fallback to regex parsing
		$selectParts = [];

		if (isset($payload['SELECT']) && is_array($payload['SELECT'])) {
			$fields = [];
			foreach ($payload['SELECT'] as $field) {
				if (!is_array($field) || !isset($field['base_expr'])) {
					continue;
				}

				$fields[] = (string)$field['base_expr'];
			}
			$selectParts[] = 'SELECT ' . implode(', ', $fields);
		}

		if (isset($payload['FROM']) && is_array($payload['FROM'])) {
			$tables = [];
			foreach ($payload['FROM'] as $table) {
				if (!is_array($table) || !isset($table['base_expr'])) {
					continue;
				}

				$tables[] = (string)$table['base_expr'];
			}
			$selectParts[] = 'FROM ' . implode(', ', $tables);
		}

		if (isset($payload['WHERE']) && is_array($payload['WHERE'])) {
			$conditions = [];
			foreach ($payload['WHERE'] as $condition) {
				if (!is_array($condition) || !isset($condition['base_expr'])) {
					continue;
				}

				$conditions[] = (string)$condition['base_expr'];
			}
			$selectParts[] = 'WHERE ' . implode(' ', $conditions);
		}

		// Add other clauses as needed (ORDER BY, GROUP BY, HAVING, etc.)
		foreach (['ORDER', 'GROUP', 'HAVING', 'LIMIT'] as $clause) {
			if (!isset($payload[$clause]) || !is_array($payload[$clause])) {
				continue;
			}

			$parts = [];
			foreach ($payload[$clause] as $part) {
				if (!is_array($part) || !isset($part['base_expr'])) {
					continue;
				}

				$parts[] = (string)$part['base_expr'];
			}
			$selectParts[] = $clause . ' ' . implode(' ', $parts);
		}

		return implode(' ', $selectParts);
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
			$this->batchSize = max(1, min($requestedSize, Config::getMaxBatchSize()));
		}

		// Legacy support for space-separated BATCH_SIZE (deprecated)
		if (!preg_match('/\s+BATCH_SIZE\s+(\d+)\s*$/i', $sql, $matches)) {
			return;
		}

		$requestedSize = (int)$matches[1];
		$this->batchSize = max(1, min($requestedSize, Config::getMaxBatchSize()));
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
		if (empty($this->targetTable)) {
			throw GenericError::create('Target table name cannot be empty');
		}

		if (empty($this->selectQuery)) {
			throw GenericError::create('SELECT query cannot be empty');
		}

		if ($this->batchSize < 1 || $this->batchSize > Config::getMaxBatchSize()) {
			throw GenericError::create(
				sprintf(
					'Batch size must be between 1 and %d, got %d',
					Config::getMaxBatchSize(),
					$this->batchSize
				)
			);
		}

		// Basic SELECT query validation
		if (!preg_match('/^\s*SELECT\s+/i', $this->selectQuery)) {
			throw GenericError::create('Query must start with SELECT');
		}

		if (!preg_match('/\s+FROM\s+/i', $this->selectQuery)) {
			throw GenericError::create('SELECT query must contain FROM clause');
		}
	}
}
