<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Lib\TelemetrySpyHttpsWrapper;
use Manticoresoftware\Telemetry\Metric as TelemetryMetric;
use PHPUnit\Framework\TestCase;

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
		/** @var array<string,mixed> $http */
		$http = $opts['http'];
		self::assertIsArray($http);
		self::assertArrayHasKey('header', $http);

		// Core regression guard: PHP 8.4 chokes on a string here.
		self::assertIsArray(
			$http['header'],
			'HTTP headers must be an array (telemetry-lib issue #2). '
			. 'A string value reintroduces the PHP 8.4 silent-send-failure bug.'
		);
		/** @var array<int,string> $headers */
		$headers = $http['header'];

		// Defensive: even an array element with an embedded newline retriggers the warning.
		foreach ($headers as $h) {
			self::assertIsString($h);
			self::assertStringNotContainsString(
				"\n",
				$h,
				'Header elements must not contain embedded newlines'
			);
		}

		self::assertContains('Content-Encoding: gzip', $headers);

		$contentTypeHeaders = array_values(
			array_filter(
				$headers,
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

		self::assertArrayHasKey('method', $http);
		self::assertSame('POST', $http['method']);
		self::assertArrayHasKey('content', $http);
		self::assertIsString($http['content']);
		$decoded = @gzdecode($http['content']);
		self::assertIsString($decoded, 'POST body must be gzip-encoded');
		self::assertStringContainsString('test_metric', $decoded);
	}
}
