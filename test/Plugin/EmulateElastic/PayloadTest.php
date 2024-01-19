<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request as NetRequest;
use PHPUnit\Framework\TestCase;

class PayloadTest extends TestCase {

	public function testGetTableNameFromElasticQueryOk():void {
		$request = NetRequest::fromArray(
			[
				'error' => '/test/_mapping - unsupported endpoint',
				'payload' => '{"properties":{"location":{"type":"geo_point"},'.
					'"title":{"type":"text","fields":{"keyword":{"type":"keyword","ignore_above":256}}}}}',
				'version' => 1,
				'format' => RequestFormat::JSON,
				'endpointBundle' => ManticoreEndpoint::Elastic,
				'path' => '/test/_mapping',
			]
		);
		$payload = Payload::fromRequest($request);
		$this->assertEquals('test', $payload->table);
	}

	public function testGetColumnExprFromElasticQueryOk():void {
		$columnInfo = [
			'location' => [
				'type' => 'geo_point',
			],
			'title' => [
				'type' => 'text',
				'fields' => [
					'keyword' => [
						'type' => 'keyword',
						'ignore_above' => 256,
					],
				],
			],
		];
		$request = NetRequest::fromArray(
			[
				'error' => '/test/_mapping - unsupported endpoint',
				'payload' => '{"properties":{"location":{"type":"geo_point"},'.
					'"title":{"type":"text","fields":{"keyword":{"type":"keyword","ignore_above":256}}}}}',
				'version' => 1,
				'format' => RequestFormat::JSON,
				'endpointBundle' => ManticoreEndpoint::Elastic,
				'path' => '/test/_mapping',
			]
		);
		$payload = Payload::fromRequest($request);
		$this->assertEquals($columnInfo, $payload->columnInfo);
	}

	public function testHandleRequestFail():void {
		$request = NetRequest::fromArray(
			[
				'error' => '/test/_unknown - unsupported endpoint',
				'payload' => '{"properties":{"location":{"type":"geo_point"},'.
				'"title":{"type":"text","fields":{"keyword":{"type":"keyword","ignore_above":256}}}}}',
				'version' => 1,
				'format' => RequestFormat::JSON,
				'endpointBundle' => ManticoreEndpoint::Elastic,
				'path' => '/test/_unknown',
			]
		);
		try {
			Payload::fromRequest($request);
		} catch (Exception $e) {
			$this->assertEquals(
				'Unsupported request type in /test/_unknown: _unknown',
				$e->getMessage()
			);
		}
	}
}
