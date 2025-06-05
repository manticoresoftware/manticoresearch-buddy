<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Lib;

use RuntimeException;
use Swoole\Table;

/**
 * Shared configuration manager using Swoole Table for multi-worker environments
 * Provides thread-safe configuration storage accessible across all Swoole workers
 */
final class ConfigManager {
	/** @var ?Table */
	private static ?Table $table = null;

	/** @var bool */
	private static bool $initialized = false;

	/**
	 * Initialize the configuration table with current environment values
	 * Must be called before Swoole server starts
	 *
	 * @return void
	 * @throws RuntimeException
	 */
	public static function init(): void {
		if (self::$initialized) {
			return;
		}

		self::$table = new Table(64);
		self::$table->column('value', Table::TYPE_STRING, 256);

		if (!self::$table->create()) {
			throw new RuntimeException('Failed to create configuration table');
		}

		// Initialize with current environment values
		self::initializeFromEnvironment();
		self::$initialized = true;
	}

	/**
	 * Set a configuration value
	 *
	 * @param string $key
	 * @param string $value
	 * @return void
	 * @throws RuntimeException
	 */
	public static function set(string $key, string $value): void {
		self::ensureInitialized();
		self::$table->set($key, ['value' => $value]);
	}

	/**
	 * Get a configuration value
	 *
	 * @param string $key
	 * @param string $default
	 * @return string
	 * @throws RuntimeException
	 */
	public static function get(string $key, string $default = ''): string {
		self::ensureInitialized();
		$row = self::$table->get($key);
		return $row ? $row['value'] : $default;
	}

	/**
	 * Get a configuration value as integer
	 *
	 * @param string $key
	 * @param int $default
	 * @return int
	 * @throws RuntimeException
	 */
	public static function getInt(string $key, int $default = 0): int {
		return (int)self::get($key, (string)$default);
	}

	/**
	 * Get a configuration value as boolean
	 *
	 * @param string $key
	 * @param bool $default
	 * @return bool
	 * @throws RuntimeException
	 */
	public static function getBool(string $key, bool $default = false): bool {
		return (bool)self::get($key, (string)$default);
	}


	/**
	 * Check if a configuration key exists
	 *
	 * @param string $key
	 * @return bool
	 * @throws RuntimeException
	 */
	public static function has(string $key): bool {
		self::ensureInitialized();
		return self::$table->exist($key);
	}

	/**
	 * Get all configuration values
	 *
	 * @return array<string, string>
	 * @throws RuntimeException
	 */
	public static function getAll(): array {
		self::ensureInitialized();
		$config = [];
		foreach (self::$table as $key => $row) {
			$config[$key] = $row['value'];
		}
		return $config;
	}

	/**
	 * Initialize configuration with default values
	 * Values will be set by CliArgsProcessor and other components
	 *
	 * @return void
	 */
	private static function initializeFromEnvironment(): void {
		// Initialize with default values - these will be overridden by CliArgsProcessor
		$defaultVars = [
			'DEBUG' => '0',
			'THREADS' => (string)swoole_cpu_num(),
			'TELEMETRY' => '1',
			'TELEMETRY_PERIOD' => '300',
			'LISTEN' => '127.0.0.1:9308',
			'BIND_HOST' => '127.0.0.1',
			'BIND_PORT' => '',
			'PLUGIN_DIR' => '',
		];

		foreach ($defaultVars as $key => $value) {
			// Only set if not already set (allows CliArgsProcessor to override)
			if (!self::$table->exist($key)) {
				self::$table->set($key, ['value' => $value]);
			}
		}
	}

	/**
	 * Ensure the configuration manager is initialized
	 *
	 * @return void
	 * @throws RuntimeException
	 */
	private static function ensureInitialized(): void {
		if (!self::$initialized || self::$table === null) {
			throw new RuntimeException('ConfigManager not initialized. Call ConfigManager::init() first.');
		}
	}

	/**
	 * Reset the configuration manager (mainly for testing)
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$table = null;
		self::$initialized = false;
	}
}
