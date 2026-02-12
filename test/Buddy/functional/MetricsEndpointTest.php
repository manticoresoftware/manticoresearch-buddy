<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

/**
 * Test for Metrics endpoint content type and HTTP response verification
 */
final class MetricsEndpointTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * Snapshot test for metric names + TYPE returned by /metrics.
	 *
	 * This is a strict contract: changing metric names or types is a breaking change.
	 */
	public function testMetricsTypesSnapshot(): void {
		$response = static::runHttpQuery('', true, 'metrics');
		$this->assertIsArray($response);
		$this->assertArrayHasKey(0, $response);
		$this->assertIsArray($response[0]);
		$this->assertArrayHasKey('data', $response[0]);

		/** @var array{data:string,error:string} $item */
		$item = $response[0];
		$body = $item['data'];
		$this->assertIsString($body);

		$actual = $this->extractMetricTypes($body);

		$duplicates = $this->findDuplicateSeriesKeys($body);
		$this->assertSame(
			[],
			$duplicates,
			'Duplicate metric series (first 20): ' . implode(', ', array_slice($duplicates, 0, 20))
		);

		$expectedPath = dirname(__DIR__) . '/fixtures/metrics_types.snapshot.txt';
		$this->assertFileExists($expectedPath, "Snapshot file missing: $expectedPath");
		$expected = file($expectedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->assertIsArray($expected);
		sort($expected, SORT_STRING);

		$this->assertSame(
			$expected,
			$actual,
			'Metric TYPE snapshot mismatch'
		);
	}

	/**
	 * @return array<int, string> Each entry is "<metric_name> <type>", sorted.
	 */
	private function extractMetricTypes(string $body): array {
		$types = [];

		$lines = preg_split("/\\r?\\n/", $body);
		if (!is_array($lines)) {
			return [];
		}

		foreach ($lines as $line) {
			if (!is_string($line) || !str_starts_with($line, '# TYPE ')) {
				continue;
			}

			$parts = preg_split('/\\s+/', trim($line));
			if (!is_array($parts) || sizeof($parts) < 4) {
				continue;
			}

			$types[] = $parts[2] . ' ' . $parts[3];
		}

		$types = array_values(array_unique($types));
		sort($types, SORT_STRING);

		return $types;
	}

	/**
	 * @return array<int, string> List of duplicate series keys, sorted.
	 */
	private function findDuplicateSeriesKeys(string $body): array {
		$seen = [];
		$dupes = [];

		foreach ($this->lines($body) as $line) {
			$key = $this->seriesKeyFromSampleLine($line);
			if ($key === null) {
				continue;
			}

			if (isset($seen[$key])) {
				$dupes[$key] = true;
				continue;
			}

			$seen[$key] = true;
		}

		$out = array_keys($dupes);
		sort($out, SORT_STRING);
		return $out;
	}

	/**
	 * @return array<int, string>
	 */
	private function lines(string $body): array {
		$lines = preg_split("/\\r?\\n/", $body);
		return is_array($lines) ? $lines : [];
	}

	private function seriesKeyFromSampleLine(string $line): ?string {
		if ($line === '' || $line[0] === '#') {
			return null;
		}

		$parsed = $this->parseSampleLine($line);
		if ($parsed === null) {
			return null;
		}

		$labels = $this->parseLabelSet($parsed['labels_raw']);
		$labels = $this->formatSortedLabelPairs($labels);

		return $parsed['name'] . '{' . implode(',', $labels) . '}';
	}

	/**
	 * @return array{name:string,labels_raw:string}|null
	 */
	private function parseSampleLine(string $line): ?array {
		$re = '/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\\{([^}]*)\\})?\\s+/';
		if (preg_match($re, $line, $m) !== 1) {
			return null;
		}

		return [
			'name' => $m[1],
			'labels_raw' => (string)($m[3] ?? ''),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private function parseLabelSet(string $labelsRaw): array {
		if ($labelsRaw === '') {
			return [];
		}

		$labels = [];
		$re = '/([a-zA-Z_][a-zA-Z0-9_]*)=\"((?:\\\\.|[^\"\\\\])*)\"/';
		if (preg_match_all($re, $labelsRaw, $pairs, PREG_SET_ORDER) > 0) {
			foreach ($pairs as $pair) {
				$labels[$pair[1]] = $pair[2];
			}
		}

		return $labels;
	}

	/**
	 * @param array<string, string> $labels
	 * @return array<int, string>
	 */
	private function formatSortedLabelPairs(array $labels): array {
		ksort($labels, SORT_STRING);
		$out = [];
		foreach ($labels as $k => $v) {
			$out[] = $k . '="' . $v . '"';
		}
		return $out;
	}

	/**
	 * Test that metrics endpoint returns text/plain content type via direct HTTP
	 *
	 * @return void
	 */
	public function testMetricsEndpointHttpContentType(): void {
		$response = static::runHttpQuery('', true, 'metrics', true);

		$this->assertIsArray($response);
		$this->assertArrayHasKey(0, $response);
		$this->assertIsArray($response[0]);
		$this->assertArrayHasKey('headers', $response[0]);
		$this->assertArrayHasKey('status_code', $response[0]);

		/** @var array{headers:string,status_code:int,data:string,error:string} $item */
		$item = $response[0];

		// Assert that Content-Type header is text/plain
		$this->assertStringContainsString('Content-Type: text/plain', $item['headers']);
		$this->assertEquals(200, $item['status_code']);
	}

	/**
	 * Test that metrics endpoint returns full response with correct content type
	 *
	 * @return void
	 */
	public function testMetricsEndpointFullResponse(): void {
		$response = static::runHttpQuery('', true, 'metrics', true);

		$this->assertIsArray($response);
		$this->assertArrayHasKey(0, $response);
		$this->assertIsArray($response[0]);
		$this->assertArrayHasKey('headers', $response[0]);
		$this->assertArrayHasKey('status_code', $response[0]);
		$this->assertArrayHasKey('data', $response[0]);

		/** @var array{headers:string,status_code:int,data:string,error:string} $item */
		$item = $response[0];

		// Verify content type header
		$this->assertStringContainsString('Content-Type: text/plain', $item['headers']);
		$this->assertEquals(200, $item['status_code']);

		// Verify Prometheus format in body
		$body = $item['data'];
		$this->assertIsString($body);
		$this->assertStringContainsString('# HELP manticore_', $body);
		$this->assertStringContainsString('# TYPE manticore_', $body);
		$this->assertStringContainsString('manticore_uptime_seconds', $body);
	}

	/**
	 * Test metrics endpoint via Buddy protocol
	 *
	 * @return void
	 */
	public function testMetricsViaBuddyRequest(): void {
		$request = [
			'type' => 'unknown json request',
			'error' => ['message' => ''],
			'version' => Buddy::PROTOCOL_VERSION,
			'message' => [
				'path_query' => '/metrics',
				'body' => '',
				'http_method' => 'GET',
			],
		];

		$port = static::$listenBuddyPort;
		$payloadFile = \sys_get_temp_dir() . '/payload-' . uniqid() . '.json';
		file_put_contents($payloadFile, json_encode($request));

		$output = [];
		exec("curl -s 127.0.0.1:$port -H 'Content-type: application/json' -d @$payloadFile 2>&1", $output);
		unlink($payloadFile);

		$response = json_decode($output[0] ?? '{}', true);
		$this->assertNotNull($response, 'Response should be valid JSON');
		$this->assertIsArray($response);

		// Basic Buddy response validation
		$this->assertEquals('json response', $response['type']);
		$this->assertEquals(Buddy::PROTOCOL_VERSION, $response['version']);

		// Check metrics content
		$this->assertIsString($response['message']);
		$metricsContent = $response['message'];

		$this->assertStringContainsString('# HELP manticore_', $metricsContent);
		$this->assertStringContainsString('# TYPE manticore_', $metricsContent);
		$this->assertStringContainsString('manticore_uptime_seconds', $metricsContent);
	}

	/**
	 * Test comparison between different endpoints to verify content types
	 *
	 * @return void
	 */
	public function testContentTypeComparison(): void {
		// Test metrics endpoint
		$metricsResponse = static::runHttpQuery('', true, 'metrics', true);
		$this->assertIsArray($metricsResponse);
		$this->assertArrayHasKey(0, $metricsResponse);
		$this->assertIsArray($metricsResponse[0]);
		$this->assertArrayHasKey('headers', $metricsResponse[0]);
		/** @var array{headers:string,status_code:int,data:string,error:string} $metricsItem */
		$metricsItem = $metricsResponse[0];
		$this->assertStringContainsString('Content-Type: text/plain', $metricsItem['headers']);

		// Test SQL endpoint
		$sqlResponse = static::runHttpQuery('SHOW TABLES', true, 'sql?mode=raw', true);
		$this->assertIsArray($sqlResponse);
		$this->assertArrayHasKey(0, $sqlResponse);
		$this->assertIsArray($sqlResponse[0]);
		$this->assertArrayHasKey('headers', $sqlResponse[0]);
		/** @var array{headers:string,status_code:int,data:array<int,array<string,string>>,error:string} $sqlItem */
		$sqlItem = $sqlResponse[0];
		$this->assertStringContainsString('Content-Type: application/json', $sqlItem['headers']);
	}

	/**
	 * Test that essential metrics are present
	 *
	 * @return void
	 */
	public function testEssentialMetricsPresent(): void {
		$response = static::runHttpQuery('', true, 'metrics');
		$this->assertIsArray($response);
		$this->assertArrayHasKey(0, $response);
		$this->assertIsArray($response[0]);
		$this->assertArrayHasKey('data', $response[0]);
		/** @var array{data:string,error:string} $item */
		$item = $response[0];
		$body = $item['data'];
		$this->assertIsString($body);

		$requiredMetrics = [
			'uptime_seconds',
			'connections_count',
			'workers_total_count',
			'queries_count',
		];

		foreach ($requiredMetrics as $metric) {
			$this->assertStringContainsString("manticore_$metric", $body, "Metric $metric should be present");
		}
	}
}
