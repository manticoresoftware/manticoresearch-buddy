<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\Show\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request as NetRequest;

use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Trait\TestHTTPServerTrait;
use PHPUnit\Framework\TestCase;

class ShowVersionHandlerTest extends TestCase {

	use TestHTTPServerTrait;


	public function testShowVersionRejectedOnSqlEndpoint(): void {
		echo "\nTesting that 'SHOW VERSION' is rejected on /sql endpoint\n";

		$request = NetRequest::fromArray(
			[
			'payload' => 'SHOW VERSION',
			'version' => Buddy::PROTOCOL_VERSION,
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql', // SQL endpoint
			]
		);

		// Verify that Payload::hasMatch returns false for /sql endpoint
		$this->assertFalse(Payload::hasMatch($request));
	}

	public function testShowVersionAcceptedOnRootEndpoint(): void {
		echo "\nTesting that 'SHOW VERSION' is accepted on root endpoint\n";

		$request = NetRequest::fromArray(
			[
			'payload' => 'SHOW VERSION',
			'version' => Buddy::PROTOCOL_VERSION,
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => '', // Root endpoint
			]
		);

		// Verify that Payload::hasMatch returns true for root endpoint
		$this->assertTrue(Payload::hasMatch($request));

		// Verify that the type is set to 'version'
		$this->assertEquals('version', Payload::$type);
	}
}
