<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Metrics\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for Metrics plugin content type verification
 */
final class MetricsContentTypeTest extends TestCase {

	/**
	 * Test that TaskResult setContentType method works correctly
	 *
	 * @return void
	 */
	public function testTaskResultContentType(): void {
		// Test that TaskResult::raw can be created and content type can be set
		$content = "# HELP manticore_test_metric Test metric\n# ".
			"TYPE manticore_test_metric counter\nmanticore_test_metric 42\n";
		$result = TaskResult::raw($content)->setContentType('text/plain');

		$this->assertEquals('text/plain', $result->getContentType());
		// Just verify we have a TaskResult instance with content type set
		$this->assertInstanceOf(TaskResult::class, $result);
	}

	/**
	 * Test that Metrics payload correctly identifies metrics requests
	 *
	 * @return void
	 */
	public function testPayloadIdentifiesMetricsRequest(): void {
		// Test positive case - metrics endpoint
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => '',
			'payload' => '',
			'format' => RequestFormat::JSON,
			'endpointBundle' => Endpoint::Metrics,
			'path' => '/metrics',
			]
		);

		$this->assertTrue(Payload::hasMatch($request));

		// Test negative case - SQL endpoint
		$request2 = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => '',
			'payload' => 'SHOW TABLES',
			'format' => RequestFormat::SQL,
			'endpointBundle' => Endpoint::Sql,
			'path' => '/sql',
			]
		);

		$this->assertFalse(Payload::hasMatch($request2));
	}

	/**
	 * Test that Metrics payload returns correct info
	 *
	 * @return void
	 */
	public function testPayloadReturnsCorrectInfo(): void {
		$info = Payload::getInfo();
		$this->assertStringContainsString('Prometheus', $info);
		$this->assertStringContainsString('metrics', $info);
	}

	/**
	 * Test that payload is created correctly from request
	 *
	 * @return void
	 */
	public function testPayloadFromRequest(): void {
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => '',
			'payload' => '',
			'format' => RequestFormat::JSON,
			'endpointBundle' => Endpoint::Metrics,
			'path' => '/metrics',
			]
		);

		$payload = Payload::fromRequest($request);

		$this->assertInstanceOf(Payload::class, $payload);
		$this->assertEquals('/metrics', $payload->path);
	}

	/**
	 * Test that Metrics endpoint enum has correct value
	 *
	 * @return void
	 */
	public function testMetricsEndpointEnumValue(): void {
		$this->assertEquals('metrics', Endpoint::Metrics->value);
	}
}
