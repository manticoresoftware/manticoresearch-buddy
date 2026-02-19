<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyTest\Plugin\Auth;

use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Auth plugin Payload class
 */
class PayloadTest extends TestCase {
	use TestProtectedTrait;

	/**
	 * Test hasMatch for all supported commands
	 */
	public function testHasMatch(): void {
		echo "\nTesting hasMatch for Auth plugin commands\n";
		$testCases = [
			[
				'query' => "CREATE USER 'testuser' IDENTIFIED BY 'testpass'",
				'error' => 'P03: syntax error, unexpected tablename, expecting '.
					"CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER",
				'expected' => true,
			],
			[
				'query' => "DROP USER 'testuser'",
				'error' => 'P03: syntax error, unexpected tablename, expecting '.
					"FUNCTION or PLUGIN or TABLE near 'user",
				'expected' => true,
			],
			[
				'query' => "GRANT read ON * TO 'testuser'",
				'error' => "P02: syntax error, unexpected identifier near 'GRANT",
				'expected' => true,
			],
			[
				'query' => "REVOKE read ON * FROM 'testuser'",
				'error' => "P02: syntax error, unexpected identifier near 'REVOKE",
				'expected' => true,
			],
			[
				'query' => 'SHOW MY PERMISSIONS',
				'error' => 'P01: syntax error, unexpected identifier, '.
					"expecting VARIABLES near 'MY PERMISSIONS'",
				'expected' => true,
			],
			[
				'query' => "SET PASSWORD 'abcdef'",
				'error' => "P01: syntax error, unexpected string, expecting '=' near",
				'expected' => true,
			],
			[
				'query' => 'INVALID QUERY',
				'error' => 'P01: syntax error, unexpected identifier',
				'expected' => false,
			],
		];

		foreach ($testCases as $case) {
			$request = Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => $case['error'],
				'payload' => $case['query'],
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
				]
			);
			$this->assertEquals(
				$case['expected'],
				Payload::hasMatch($request),
				"Failed for query: {$case['query']}"
			);
		}
	}

	/**
	 * Test fromRequest for GRANT command
	 */
	public function testFromRequestGrant(): void {
		echo "\nTesting fromRequest for GRANT command\n";
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => 'P02: syntax error, unexpected '.
				"identifier near 'GRANT read ON * TO 'testuser''",
			'payload' => "GRANT read ON * TO 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'all',
			]
		);
		$payload = Payload::fromRequest($request);

		$this->assertEquals('grant', $payload->type);
		$this->assertEquals('read', $payload->action);
		$this->assertEquals('*', $payload->target);
		$this->assertEquals('testuser', $payload->username);
		$this->assertNull($payload->budget);
		$this->assertEquals('all', $payload->actingUser);
		$this->assertEquals(
			'Manticoresearch\Buddy\Base\Plugin\Auth\GrantRevokeHandler',
			$payload->getHandlerClassName()
		);
	}

	/**
	 * Test fromRequest for REVOKE command
	 */
	public function testFromRequestRevoke(): void {
		echo "\nTesting fromRequest for REVOKE command\n";
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => 'P02: syntax error, unexpected '.
				"identifier near 'REVOKE read ON * FROM 'testuser''",
			'payload' => "REVOKE read ON * FROM 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'all',
			]
		);
		$payload = Payload::fromRequest($request);

		$this->assertEquals('revoke', $payload->type);
		$this->assertEquals('read', $payload->action);
		$this->assertEquals('*', $payload->target);
		$this->assertEquals('testuser', $payload->username);
		$this->assertNull($payload->budget);
		$this->assertEquals('all', $payload->actingUser);
		$this->assertEquals(
			'Manticoresearch\Buddy\Base\Plugin\Auth\GrantRevokeHandler',
			$payload->getHandlerClassName()
		);
	}

	/**
	 * Test parseGrantRevokeCommand with invalid action
	 */
	public function testParseGrantRevokeInvalidAction(): void {
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P02: syntax error, unexpected identifier near 'GRANT invalid ON * TO 'testuser''",
			'payload' => "GRANT invalid ON * TO 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);

		try {
			Payload::fromRequest($request);
			$this->fail('Expected GenericError to be thrown');
		} catch (GenericError $e) {
			$this->assertEquals(
				'Invalid action: Must be one of read, write, schema, admin, replication.',
				$e->getResponseError()
			);
		}
	}

	/**
	 * Test fromRequest with invalid query
	 */
	public function testFromRequestInvalidQuery(): void {
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => 'P01: syntax error, unexpected identifier',
			'payload' => 'INVALID QUERY',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'all',
			]
		);

		try {
			Payload::fromRequest($request);
			$this->fail('Expected GenericError to be thrown');
		} catch (GenericError $e) {
			$this->assertEquals('Failed to handle your query', $e->getResponseError());
		}
	}

	public function testFromRequestCreateUser(): void {
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => 'P03: syntax error, unexpected tablename, '.
				"expecting CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER",
			'payload' => "CREATE USER 'newuser' IDENTIFIED BY 'password123'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);
		$payload = Payload::fromRequest($request);

		$this->assertEquals('create', $payload->type);
		$this->assertEquals('newuser', $payload->username);
		$this->assertEquals('password123', $payload->password);
		$this->assertEquals('admin', $payload->actingUser);
		$this->assertEquals(
			'Manticoresearch\Buddy\Base\Plugin\Auth\UserHandler',
			$payload->getHandlerClassName()
		);
	}

	public function testFromRequestDropUser(): void {
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => 'P03: syntax error, unexpected tablename, '.
				"expecting FUNCTION or PLUGIN or TABLE near 'user",
			'payload' => "DROP USER 'olduser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);
		$payload = Payload::fromRequest($request);

		$this->assertEquals('drop', $payload->type);
		$this->assertEquals('olduser', $payload->username);
		$this->assertNull($payload->password);
		$this->assertEquals('admin', $payload->actingUser);
		$this->assertEquals(
			'Manticoresearch\Buddy\Base\Plugin\Auth\UserHandler',
			$payload->getHandlerClassName()
		);
	}

	public function testFromRequestSetPassword(): void {
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P01: syntax error, unexpected string, expecting '=' near",
			'payload' => "SET PASSWORD 'newpass123' FOR 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);
		$payload = Payload::fromRequest($request);

		$this->assertEquals('set_password', $payload->type);
		$this->assertEquals('testuser', $payload->username);
		$this->assertEquals('newpass123', $payload->password);
		$this->assertEquals('admin', $payload->actingUser);
		$this->assertEquals(
			'Manticoresearch\Buddy\Base\Plugin\Auth\PasswordHandler',
			$payload->getHandlerClassName()
		);
	}
}
