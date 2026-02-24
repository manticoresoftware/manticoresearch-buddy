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
	 * Table names returned by SHOW TABLES.
	 *
	 * @var string[]
	 */
	public array $tableNames = [];

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
