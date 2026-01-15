<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Test\Plugin\EmulateElastic\OpenSearchDashboards;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\OpenSearchDashboards\Payload;
use Manticoresearch\Buddy\Core\Network\Request;
use PHPUnit\Framework\TestCase;

/**
 * Test class for OpenSearch Dashboards plugin
 */
class OpenSearchDashboardsTest extends TestCase {

	/**
	 * Test that the plugin correctly identifies OpenSearch Dashboards requests
	 * @return void
	 */
	public function testHasMatch(): void {
		// Test search requests
		$searchRequest = new Request();
		$searchRequest->path = '/test-index/_search';
		$searchRequest->payload = '{"query":{"match_all":{}}}';
		$this->assertTrue(Payload::hasMatch($searchRequest));

		// Test cat requests
		$catRequest = new Request();
		$catRequest->path = '/_cat/indices';
		$this->assertTrue(Payload::hasMatch($catRequest));

		// Test count requests
		$countRequest = new Request();
		$countRequest->path = '/test-index/_count';
		$this->assertTrue(Payload::hasMatch($countRequest));

		// Test license requests
		$licenseRequest = new Request();
		$licenseRequest->path = '/_license';
		$this->assertTrue(Payload::hasMatch($licenseRequest));

		// Test nodes requests
		$nodesRequest = new Request();
		$nodesRequest->path = '/_nodes';
		$this->assertTrue(Payload::hasMatch($nodesRequest));

		// Test xpack requests
		$xpackRequest = new Request();
		$xpackRequest->path = '/_xpack';
		$this->assertTrue(Payload::hasMatch($xpackRequest));

		// Test OpenSearch Dashboards requests
		$dashboardsRequest = new Request();
		$dashboardsRequest->path = '/.opensearch_dashboards/_doc/config:1.0.0';
		$this->assertTrue(Payload::hasMatch($dashboardsRequest));

		// Test non-matching requests
		$nonMatchingRequest = new Request();
		$nonMatchingRequest->path = '/some/other/path';
		$this->assertFalse(Payload::hasMatch($nonMatchingRequest));
	}

	/**
	 * Test payload creation from request
	 * @return void
	 */
	public function testFromRequest(): void {
		$request = new Request();
		$request->path = '/test-index/_search';
		$request->payload = '{"query":{"match_all":{}},"size":10}';

		$payload = Payload::fromRequest($request);

		$this->assertEquals('/test-index/_search', $payload->path);
		$this->assertEquals('test-index', $payload->table);
		$this->assertEquals('{"query":{"match_all":{}},"size":10}', $payload->body);
		$this->assertEquals('_search', Payload::$requestTarget);
	}

	/**
	 * Test plugin info
	 * @return void
	 */
	public function testGetInfo(): void {
		$info = Payload::getInfo();
		$this->assertStringContainsString('OpenSearch Dashboards', $info);
		$this->assertStringContainsString('OpenSearch', $info);
	}
}
