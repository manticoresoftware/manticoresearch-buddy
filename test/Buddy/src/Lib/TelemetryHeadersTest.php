<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresoftware\Telemetry\Metric as TelemetryMetric;
use PHPUnit\Framework\TestCase;

/**
 * Spy stream wrapper for https:// that captures the stream context options
 * passed to file_get_contents() by Manticoresoftware\Telemetry\Metric::process().
 * Returns an empty body so the library treats the call as a successful POST.
 */
final class TelemetrySpyHttpsWrapper { // phpcs:ignore
	/** @var array<mixed> */
	public static array $contextOptions = [];
	public static string $lastUrl = '';

	/** @var resource|null */
	public $context;
	private bool $consumed = false;

	/**
	 * @param string $path
	 * @param string $mode
	 * @param int $options
	 * @param string|null $opened_path
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool {
		self::$lastUrl = $path;
		self::$contextOptions = is_resource($this->context) ? stream_context_get_options($this->context) : [];
		return true;
	}

	public function stream_read(int $count): string {
		if ($this->consumed) {
			return '';
		}
		$this->consumed = true;
		return '';
	}

	public function stream_eof(): bool {
		return $this->consumed;
	}

	/** @return array<int|string,int> */
	public function stream_stat(): array {
		return [];
	}

	public function stream_close(): void {
	}
}

/**
 * Regression guard for telemetry-lib issue #2 (PHP 8.4 silent telemetry failure).
 *
 * Bug: vendor/manticoresoftware/telemetry/src/Metric.php::process() built the
 * 'header' option as a single "Content-Encoding: gzip\nContent-Type: ...\n..."
 * string. PHP 8.4 emits a Content-type warning for this form, the library
 * catches the resulting ErrorException, and send() silently returns false.
 *
 * Fix (telemetry-lib commit 39522a13, v0.1.25): pass headers as an array.
 *
 * Buddy depends on telemetry-lib, so guarding here protects against a future
 * composer update pulling in a regressed version.
 */
final class TelemetryHeadersTest extends TestCase {

	protected function setUp(): void {
		TelemetrySpyHttpsWrapper::$contextOptions = [];
		TelemetrySpyHttpsWrapper::$lastUrl = '';
		stream_wrapper_unregister('https');
		stream_wrapper_register('https', TelemetrySpyHttpsWrapper::class);
	}

	protected function tearDown(): void {
		stream_wrapper_restore('https');
	}

	public function testSendPassesHeadersAsArrayForPhp84Compatibility(): void {
		// Mirror buddy's invocation in src/Lib/Metric.php:71,121,132
		$metric = new TelemetryMetric(['collector' => 'buddy']);
		$metric->add('test_metric', 1);
		$result = $metric->send();

		self::assertTrue($result, 'TelemetryMetric::send() must return true when transport succeeds');
		self::assertStringContainsString(
			'telemetry.manticoresearch.com',
			TelemetrySpyHttpsWrapper::$lastUrl,
			'send() should target the telemetry endpoint'
		);

		$opts = TelemetrySpyHttpsWrapper::$contextOptions;
		self::assertArrayHasKey('http', $opts);
		self::assertArrayHasKey('header', $opts['http']);

		// Core regression guard: PHP 8.4 chokes on a string here.
		self::assertIsArray(
			$opts['http']['header'],
			'HTTP headers must be an array (telemetry-lib issue #2). '
			. 'A string value reintroduces the PHP 8.4 silent-send-failure bug.'
		);

		// Defensive: even an array element with an embedded newline retriggers the warning.
		foreach ($opts['http']['header'] as $h) {
			self::assertIsString($h);
			self::assertStringNotContainsString(
				"\n",
				$h,
				'Header elements must not contain embedded newlines'
			);
		}

		self::assertContains('Content-Encoding: gzip', $opts['http']['header']);

		$contentTypeHeaders = array_values(
			array_filter(
				$opts['http']['header'],
				static fn(string $h): bool => stripos($h, 'Content-Type:') === 0
			)
		);
		self::assertCount(1, $contentTypeHeaders, 'Exactly one Content-Type header expected');
		self::assertStringNotContainsString(
			'application/x-www-form-urlencoded',
			$contentTypeHeaders[0],
			'Content-Type must not be application/x-www-form-urlencoded — '
			. 'this is the value PHP 8.4 specifically warns about'
		);

		self::assertSame('POST', $opts['http']['method']);
		self::assertArrayHasKey('content', $opts['http']);
		$decoded = @gzdecode($opts['http']['content']);
		self::assertIsString($decoded, 'POST body must be gzip-encoded');
		self::assertStringContainsString('test_metric', $decoded);
	}
}
