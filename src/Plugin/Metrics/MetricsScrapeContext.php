<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics;

final class MetricsScrapeContext {

	/**
	 * Table names returned by SHOW TABLES.
	 *
	 * @var string[]
	 */
	public array $tableNames = [];

	/**
	 * Parsed output of SHOW STATUS (Counter => Value).
	 *
	 * @var array<string, float|int|string>
	 */
	public array $status = [];

	/**
	 * Raw rows from SHOW STATUS (for collectors that need full row data).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $statusRows = [];

	/**
	 * Raw rows from SHOW TABLES.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $tablesRows = [];

	/**
	 * Raw rows from SHOW THREADS.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $threadsRows = [];

	/**
	 * Count of detected searchd crashes (from searchd.log).
	 */
	public ?int $searchdCrashesTotal = null;
}
