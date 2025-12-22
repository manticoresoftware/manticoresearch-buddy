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
		$this->assertStringContainsString('Content-Type: text/html', $sqlItem['headers']);
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
