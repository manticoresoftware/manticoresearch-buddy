<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics;

final class MetricsScrapeContext {

	/**
	 * Manticore settings snapshot for the scrape.
	 *
	 * @var array<string, string>
	 */
	public array $settings = [];

	/**
	 * Tables returned by SHOW TABLES (map: name => type, e.g. rt/plain/distributed).
	 *
	 * @var array<string, string>
	 */
	public array $tables = [];

	/**
	 * Key-value vars extracted from SHOW TABLE <name> STATUS.
	 *
	 * @var array<string, array<string, string>>
	 */
	public array $tableStatuses = [];

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

	/**
	 * Per-scrape set of unknown build-info components already warned about.
	 *
	 * @var array<string, true>
	 */
	public array $warnedBuildInfoComponents = [];
}
