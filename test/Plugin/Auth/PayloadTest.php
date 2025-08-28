<?php declare(strict_types=1);

/*
 Copyright (c) 2024-2025, Manticore Software LTD (https://manticoresearch.com)
*/

namespace Manticoresearch\BuddyTest\Plugin\Auth;

use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Lib\BuddyRequestError;
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
				'error' => "P03: syntax error, unexpected tablename, expecting CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER",
				'expected' => true,
			],
			[
				'query' => "DROP USER 'testuser'",
				'error' => "P03: syntax error, unexpected tablename, expecting FUNCTION or PLUGIN or TABLE near 'user",
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
				'query' => "SHOW MY PERMISSIONS",
				'error' => "P01: syntax error, unexpected identifier, expecting VARIABLES near 'MY PERMISSIONS'",
				'expected' => true,
			],
			[
				'query' => "SET PASSWORD 'abcdef'",
				'error' => "P01: syntax error, unexpected string, expecting '=' near",
				'expected' => true,
			],
			[
				'query' => "INVALID QUERY",
				'error' => "P01: syntax error, unexpected identifier",
				'expected' => false,
			],
		];

		foreach ($testCases as $case) {
			$request = Request::fromArray([
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => $case['error'],
				'payload' => $case['query'],
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]);
			$this->assertEquals($case['expected'], Payload::hasMatch($request), "Failed for query: {$case['query']}");
		}
	}

	/**
	 * Test fromRequest for GRANT command
	 */
	public function testFromRequestGrant(): void {
		echo "\nTesting fromRequest for GRANT command\n";
		$request = Request::fromArray([
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P02: syntax error, unexpected identifier near 'GRANT read ON * TO 'testuser''",
			'payload' => "GRANT read ON * TO 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'all',
		]);
		$payload = Payload::fromRequest($request);

		$this->assertEquals('grant', $payload->type);
		$this->assertEquals('read', $payload->action);
		$this->assertEquals('*', $payload->target);
		$this->assertEquals('testuser', $payload->username);
		$this->assertNull($payload->budget);
		$this->assertEquals('all', $payload->actingUser);
		$this->assertEquals('Manticoresearch\Buddy\Base\Plugin\Auth\GrantRevokeHandler', $payload->getHandlerClassName());
	}

	/**
	 * Test fromRequest for REVOKE command
	 */
	public function testFromRequestRevoke(): void {
		echo "\nTesting fromRequest for REVOKE command\n";
		$request = Request::fromArray([
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P02: syntax error, unexpected identifier near 'REVOKE read ON * FROM 'testuser''",
			'payload' => "REVOKE read ON * FROM 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'all',
		]);
		$payload = Payload::fromRequest($request);

		$this->assertEquals('revoke', $payload->type);
		$this->assertEquals('read', $payload->action);
		$this->assertEquals('*', $payload->target);
		$this->assertEquals('testuser', $payload->username);
		$this->assertNull($payload->budget);
		$this->assertEquals('all', $payload->actingUser);
		$this->assertEquals('Manticoresearch\Buddy\Base\Plugin\Auth\GrantRevokeHandler', $payload->getHandlerClassName());
	}

	/**
	 * Test parseGrantRevokeCommand with invalid action
	 */
	public function testParseGrantRevokeInvalidAction(): void {
		echo "\nTesting parseGrantRevokeCommand with invalid action\n";
		[$exCls, $exMsg] = self::getExceptionInfo(
			Payload::class,
			'parseGrantRevokeCommand',
			["GRANT invalid ON * TO 'testuser'", new Payload()]
		);
		$this->assertEquals(BuddyRequestError::class, $exCls);
		$this->assertEquals('Invalid action: Must be one of read, write, schema, admin, replication.', $exMsg);
	}

	/**
	 * Test fromRequest with invalid query
	 */
	public function testFromRequestInvalidQuery(): void {
		echo "\nTesting fromRequest with invalid query\n";
		$request = Request::fromArray([
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => 'P01: syntax error, unexpected identifier',
			'payload' => 'INVALID QUERY',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'all',
		]);
		$this->expectException(BuddyRequestError::class);
		$this->expectExceptionMessage('Failed to handle your query');
		Payload::fromRequest($request);
	}
}
