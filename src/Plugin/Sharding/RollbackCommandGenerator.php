<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Generates rollback commands for forward operations
 */
final class RollbackCommandGenerator {

	/**
	 * Generate rollback command for any forward command
	 * @param string $forwardCommand
	 * @return string|null Rollback command or null if not supported
	 */
	public static function generate(string $forwardCommand): ?string {
		$command = trim($forwardCommand);
		$upperCommand = strtoupper($command);

		// CREATE TABLE -> DROP TABLE
		if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([`\w]+)/i', $command, $matches)) {
			$tableName = self::unquoteIdentifier($matches[1]);
			return "DROP TABLE IF EXISTS {$tableName}";
		}

		// CREATE CLUSTER -> DELETE CLUSTER
		if (preg_match('/^CREATE\s+CLUSTER\s+(?:IF\s+NOT\s+EXISTS\s+)?([`\w]+)/i', $command, $matches)) {
			$clusterName = self::unquoteIdentifier($matches[1]);
			return "DELETE CLUSTER {$clusterName}";
		}

		// ALTER CLUSTER ADD -> ALTER CLUSTER DROP
		if (preg_match('/^ALTER\s+CLUSTER\s+([`\w]+)\s+ADD\s+([`\w]+)/i', $command, $matches)) {
			$clusterName = self::unquoteIdentifier($matches[1]);
			$tableName = self::unquoteIdentifier($matches[2]);
			return "ALTER CLUSTER {$clusterName} DROP {$tableName}";
		}

		// ALTER CLUSTER DROP -> ALTER CLUSTER ADD (for rollback of rollback)
		if (preg_match('/^ALTER\s+CLUSTER\s+([`\w]+)\s+DROP\s+([`\w]+)/i', $command, $matches)) {
			$clusterName = self::unquoteIdentifier($matches[1]);
			$tableName = self::unquoteIdentifier($matches[2]);
			return "ALTER CLUSTER {$clusterName} ADD {$tableName}";
		}

		// JOIN CLUSTER -> DELETE CLUSTER (on the node that joined)
		if (preg_match('/^JOIN\s+CLUSTER\s+([`\w]+)/i', $command, $matches)) {
			$clusterName = self::unquoteIdentifier($matches[1]);
			return "DELETE CLUSTER {$clusterName}";
		}

		// DROP TABLE -> Cannot rollback (data loss)
		if (preg_match('/^DROP\s+TABLE/i', $upperCommand)) {
			Buddy::debugvv("Cannot generate rollback for DROP TABLE - would require data restoration");
			return null;
		}

		// DELETE CLUSTER -> Cannot rollback (would need original cluster definition)
		if (preg_match('/^DELETE\s+CLUSTER/i', $upperCommand)) {
			Buddy::debugvv("Cannot generate rollback for DELETE CLUSTER - would require original definition");
			return null;
		}

		// INSERT/UPDATE/DELETE -> Cannot rollback (would need original data)
		if (preg_match('/^(INSERT|UPDATE|DELETE)\s+/i', $upperCommand)) {
			Buddy::debugvv("Cannot generate rollback for DML operations - would require data backup");
			return null;
		}

		// Unknown command type
		Buddy::debugvv("No rollback pattern for command: " . substr($command, 0, 50));
		return null;
	}

	/**
	 * Generate specific rollback commands with context
	 * These are more precise than the generic generate() method
	 */

	/**
	 * Generate rollback for CREATE TABLE
	 * @param string $tableName
	 * @return string
	 */
	public static function forCreateTable(string $tableName): string {
		$tableName = self::quoteIdentifierIfNeeded($tableName);
		return "DROP TABLE IF EXISTS {$tableName}";
	}

	/**
	 * Generate rollback for CREATE CLUSTER
	 * @param string $clusterName
	 * @return string
	 */
	public static function forCreateCluster(string $clusterName): string {
		$clusterName = self::quoteIdentifierIfNeeded($clusterName);
		return "DELETE CLUSTER {$clusterName}";
	}

	/**
	 * Generate rollback for ALTER CLUSTER ADD
	 * @param string $clusterName
	 * @param string $tableName
	 * @return string
	 */
	public static function forAlterClusterAdd(string $clusterName, string $tableName): string {
		$clusterName = self::quoteIdentifierIfNeeded($clusterName);
		$tableName = self::quoteIdentifierIfNeeded($tableName);
		return "ALTER CLUSTER {$clusterName} DROP {$tableName}";
	}

	/**
	 * Generate rollback for ALTER CLUSTER DROP
	 * @param string $clusterName
	 * @param string $tableName
	 * @return string
	 */
	public static function forAlterClusterDrop(string $clusterName, string $tableName): string {
		$clusterName = self::quoteIdentifierIfNeeded($clusterName);
		$tableName = self::quoteIdentifierIfNeeded($tableName);
		return "ALTER CLUSTER {$clusterName} ADD {$tableName}";
	}

	/**
	 * Generate rollback for JOIN CLUSTER
	 * @param string $clusterName
	 * @return string
	 */
	public static function forJoinCluster(string $clusterName): string {
		$clusterName = self::quoteIdentifierIfNeeded($clusterName);
		return "DELETE CLUSTER {$clusterName}";
	}

	/**
	 * Generate rollback for distributed table creation
	 * @param string $tableName
	 * @return string
	 */
	public static function forCreateDistributedTable(string $tableName): string {
		$tableName = self::quoteIdentifierIfNeeded($tableName);
		return "DROP TABLE IF EXISTS {$tableName}";
	}

	/**
	 * Helper to remove quotes from identifiers
	 * @param string $identifier
	 * @return string
	 */
	protected static function unquoteIdentifier(string $identifier): string {
		// Remove backticks if present
		if (str_starts_with($identifier, '`') && str_ends_with($identifier, '`')) {
			return substr($identifier, 1, -1);
		}
		return $identifier;
	}

	/**
	 * Helper to quote identifier if needed
	 * @param string $identifier
	 * @return string
	 */
	protected static function quoteIdentifierIfNeeded(string $identifier): string {
		// Quote if contains special characters or is a reserved word
		if (preg_match('/[^a-zA-Z0-9_]/', $identifier) || self::isReservedWord($identifier)) {
			return '`' . str_replace('`', '``', $identifier) . '`';
		}
		return $identifier;
	}

	/**
	 * Check if word is a ManticoreSearch reserved word
	 * @param string $word
	 * @return bool
	 */
	protected static function isReservedWord(string $word): bool {
		$reserved = [
			'ADD', 'ALTER', 'AND', 'AS', 'ASC', 'ATTACH', 'BETWEEN', 'BY',
			'CLUSTER', 'CREATE', 'DELETE', 'DESC', 'DISTINCT', 'DIV', 'DROP',
			'FALSE', 'FROM', 'GROUP', 'HAVING', 'IF', 'IN', 'INDEX', 'INSERT',
			'INTO', 'IS', 'JOIN', 'LIKE', 'LIMIT', 'MOD', 'NOT', 'NULL',
			'ON', 'OR', 'ORDER', 'REPLACE', 'SELECT', 'SET', 'SHOW', 'TABLE',
			'TO', 'TRUE', 'UPDATE', 'VALUES', 'WHERE', 'WITH',
		];

		return in_array(strtoupper($word), $reserved, true);
	}

	/**
	 * Generate rollback for a batch of commands
	 * @param array $commands Array of forward commands
	 * @return array Array of rollback commands (may contain nulls for unsupported)
	 */
	public static function generateBatch(array $commands): array {
		$rollbacks = [];
		foreach ($commands as $command) {
			$rollbacks[] = self::generate($command);
		}
		return $rollbacks;
	}

	/**
	 * Check if a command is safe to rollback
	 * @param string $command
	 * @return bool
	 */
	public static function isSafeToRollback(string $command): bool {
		$upperCommand = strtoupper(trim($command));

		// DDL operations are generally safe
		if (preg_match('/^(CREATE|ALTER|JOIN)\s+/i', $upperCommand)) {
			return true;
		}

		// DROP operations are not safe (data loss)
		if (preg_match('/^(DROP|DELETE)\s+/i', $upperCommand)) {
			return false;
		}

		// DML operations are not safe (data changes)
		if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\s+/i', $upperCommand)) {
			return false;
		}

		return false;
	}
}