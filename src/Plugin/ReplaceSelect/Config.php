<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ReplaceSelect;

/**
 * Configuration management for REPLACE SELECT plugin
 */
final class Config {
	/**
	 * Get the default batch size for processing
	 *
	 * @return int
	 */
	public static function getBatchSize(): int {
		return max(1, min((int)($_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] ?? 1000), self::getMaxBatchSize()));
	}

	/**
	 * Get the maximum allowed batch size
	 *
	 * @return int
	 */
	public static function getMaxBatchSize(): int {
		return (int)($_ENV['BUDDY_REPLACE_SELECT_MAX_BATCH_SIZE'] ?? 10000);
	}

	/**
	 * Get the lock timeout in seconds
	 *
	 * @return int
	 */
	public static function getLockTimeout(): int {
		return (int)($_ENV['BUDDY_REPLACE_SELECT_LOCK_TIMEOUT'] ?? 3600);
	}



	/**
	 * Check if debug logging is enabled
	 *
	 * @return bool
	 */
	public static function isDebugEnabled(): bool {
		return filter_var($_ENV['BUDDY_REPLACE_SELECT_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
	}
}
