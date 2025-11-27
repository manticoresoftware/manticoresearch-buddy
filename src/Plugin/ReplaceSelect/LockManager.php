<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ReplaceSelect;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

/**
 * Concurrency control manager using built-in Manticore table locking
 */
final class LockManager {
	private Client $client;
	private string $targetTable;
	private bool $hasLock = false;
	private float $lockAcquiredAt;

	/**
	 * Constructor
	 *
	 * @param Client $client
	 * @param string $targetTable
	 */
	public function __construct(Client $client, string $targetTable) {
		$this->client = $client;
		$this->targetTable = $targetTable;
	}

	/**
	 * Acquire read lock using built-in LOCK TABLES
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	public function acquireLock(): void {
		if ($this->hasLock) {
			return;
		}

		$lockSql = "LOCK TABLES {$this->targetTable} READ";
		$result = $this->client->sendRequest($lockSql);

		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				"Failed to acquire lock on table '{$this->targetTable}': " . $result->getError()
			);
		}

		$this->hasLock = true;
		$this->lockAcquiredAt = microtime(true);

		if (!Config::isDebugEnabled()) {
			return;
		}

		error_log("Acquired read lock on table '{$this->targetTable}'");
	}

	/**
	 * Release table lock
	 *
	 * @return void
	 */
	public function releaseLock(): void {
		if (!$this->hasLock) {
			return;
		}

		$result = $this->client->sendRequest('UNLOCK TABLES');

		if ($result->hasError()) {
			error_log('Warning: Failed to unlock tables: ' . $result->getError());
		} elseif (Config::isDebugEnabled()) {
			error_log('Released table locks');
		}

		$this->hasLock = false;
	}

	/**
	 * Get duration of lock ownership
	 *
	 * @return float
	 */
	public function getLockDuration(): float {
		return $this->hasLock ? microtime(true) - $this->lockAcquiredAt : 0.0;
	}

	/**
	 * Check if currently holding lock
	 *
	 * @return bool
	 */
	public function hasLock(): bool {
		return $this->hasLock;
	}

	/**
	 * Destructor - ensure lock is released
	 */
	public function __destruct() {
		$this->releaseLock();
	}
}
