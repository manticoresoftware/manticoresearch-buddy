<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyTest\Plugin\Auth;

use Manticoresearch\Buddy\Base\Plugin\Auth\GrantRevokeHandler;
use Manticoresearch\Buddy\Base\Plugin\Auth\PasswordHandler;
use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Base\Plugin\Auth\ShowHandler;
use Manticoresearch\Buddy\Base\Plugin\Auth\UserHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Helper\AuthTestHelpers;
use Manticoresearch\BuddyTest\Trait\AuthTestTrait;
use PHPUnit\Framework\TestCase;

class IntegrationTest extends TestCase {
	use AuthTestTrait;

	// These methods are now provided by AuthTestTrait
	// createSequentialClientMock() and injectClientMock() are available

	public function testCompleteUserLifecycle(): void {
		// Simulate: CREATE USER -> GRANT permissions -> SHOW permissions -> DROP USER

		// Step 1: Create user
		$createRequest = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => 'P03: syntax error, unexpected tablename, expecting '.
				"CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER",
			'payload' => "CREATE USER 'testuser' IDENTIFIED BY 'password123'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);

		$createPayload = Payload::fromRequest($createRequest);

		// Mock responses for CREATE USER
		$createResponses = [
			AuthTestHelpers::createUserExistsResponse(false), // User doesn't exist
			AuthTestHelpers::createEmptySuccessResponse(),    // INSERT success
		];

		$createHandler = new UserHandler($createPayload);
		$createClient = $this->createSequentialClientMock($createResponses);
		$this->injectClientMock($createHandler, $createClient);

		$createTask = $createHandler->run();
		$this->assertTrue($createTask->isSucceed());
		$createResult = $createTask->getResult();
		$struct = $createResult->getStruct();
		$this->assertIsArray($struct);
		$this->assertNotEmpty($struct);
		$data = $struct[0]['data'][0];
		$this->assertIsArray($data);
		$this->assertArrayHasKey('token', $data);
		$this->assertArrayHasKey('username', $data);
		$this->assertEquals('testuser', $data['username']);

		// Step 2: Grant permissions
		$grantRequest = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P02: syntax error, unexpected identifier near 'GRANT read ON * TO 'testuser''",
			'payload' => "GRANT read ON * TO 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);

		$grantPayload = Payload::fromRequest($grantRequest);

		// Mock responses for GRANT
		$grantResponses = [
			AuthTestHelpers::createUserExistsResponse(true),      // User exists
			AuthTestHelpers::createPermissionExistsResponse(false), // Permission doesn't exist
			AuthTestHelpers::createEmptySuccessResponse(),        // INSERT permission success
		];

		$grantHandler = new GrantRevokeHandler($grantPayload);
		$grantClient = $this->createSequentialClientMock($grantResponses);
		$this->injectClientMock($grantHandler, $grantClient);

		$grantTask = $grantHandler->run();
		$this->assertTrue($grantTask->isSucceed());

		// Step 3: Show permissions
		$showRequest = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P01: syntax error, unexpected identifier, expecting VARIABLES near 'MY PERMISSIONS'",
			'payload' => 'SHOW MY PERMISSIONS',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'testuser',
			]
		);

		$showPayload = Payload::fromRequest($showRequest);

		$showPermissions = [
			['Username' => 'testuser', 'action' => 'read', 'Target' => '*', 'Allow' => '1', 'Budget' => '{}'],
		];

		$showHandler = new ShowHandler($showPayload);
		$showClient = $this->createSequentialClientMock(
			[
			AuthTestHelpers::createPermissionResponse($showPermissions),
			]
		);
		$this->injectClientMock($showHandler, $showClient);

		$showTask = $showHandler->run();
		$this->assertTrue($showTask->isSucceed());
		$showResult = $showTask->getResult();
		$showStruct = $showResult->getStruct();
		$this->assertIsArray($showStruct);
		$this->assertCount(1, $showStruct[0]['data']);
		$this->assertEquals('testuser', $showStruct[0]['data'][0]['Username']);

		// Step 4: Drop user
		$dropRequest = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P03: syntax error, unexpected tablename, expecting FUNCTION or PLUGIN or TABLE near 'user",
			'payload' => "DROP USER 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);

		$dropPayload = Payload::fromRequest($dropRequest);

		$dropResponses = [
			AuthTestHelpers::createUserExistsResponse(true),   // User exists
			AuthTestHelpers::createEmptySuccessResponse(),     // DELETE permissions
			AuthTestHelpers::createEmptySuccessResponse(),     // DELETE user
		];

		$dropHandler = new UserHandler($dropPayload);
		$dropClient = $this->createSequentialClientMock($dropResponses);
		$this->injectClientMock($dropHandler, $dropClient);

		$dropTask = $dropHandler->run();
		$this->assertTrue($dropTask->isSucceed());
	}

	public function testPasswordUpdateWorkflow(): void {
		// Test: CREATE USER -> SET PASSWORD -> verify password hashes

		// Step 1: Create user with initial password
		$createRequest = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => 'P03: syntax error, unexpected tablename, expecting '.
				"CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER",
			'payload' => "CREATE USER 'passuser' IDENTIFIED BY 'oldpass123'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);

		$createPayload = Payload::fromRequest($createRequest);
		$createHandler = new UserHandler($createPayload);

		$createClient = $this->createSequentialClientMock(
			[
			AuthTestHelpers::createUserExistsResponse(false), // User doesn't exist
			AuthTestHelpers::createEmptySuccessResponse(),     // INSERT success
			]
		);
		$this->injectClientMock($createHandler, $createClient);

		$createTask = $createHandler->run();
		$this->assertTrue($createTask->isSucceed());

		// Step 2: Change password
		$passwordRequest = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P01: syntax error, unexpected string, expecting '=' near",
			'payload' => "SET PASSWORD 'newpass456' FOR 'passuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);

		$passwordPayload = Payload::fromRequest($passwordRequest);
		$passwordHandler = new PasswordHandler($passwordPayload);

		// Mock existing user data
		$existingHashes = (string)json_encode(
			[
			'password_sha1_no_salt' => 'old_sha1_hash',
			'password_sha256' => 'old_sha256_hash',
			'bearer_sha256' => 'existing_token_hash',
			]
		);

		$passwordClient = $this->createSequentialClientMock(
			[
			AuthTestHelpers::createUserDataResponse('salt123', $existingHashes), // Get user data
			AuthTestHelpers::createEmptySuccessResponse(), // REPLACE success
			]
		);
		$this->injectClientMock($passwordHandler, $passwordClient);

		$passwordTask = $passwordHandler->run();
		$this->assertTrue($passwordTask->isSucceed());
	}

	public function testMultiplePermissionGrants(): void {
		// Test granting multiple permissions to same user

		$permissions = [
			['action' => 'read', 'target' => '*'],
			['action' => 'write', 'target' => "'table/test'"],
			['action' => 'schema', 'target' => '*'],
		];

		foreach ($permissions as $index => $permission) {
			$grantRequest = Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => 'P02: syntax error, unexpected identifier near '.
					"'GRANT {$permission['action']} ON {$permission['target']} TO 'multiuser''",
				'payload' => "GRANT {$permission['action']} ON {$permission['target']} TO 'multiuser'",
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
				'user' => 'admin',
				]
			);

			$grantPayload = Payload::fromRequest($grantRequest);
			$grantHandler = new GrantRevokeHandler($grantPayload);

			$grantClient = $this->createSequentialClientMock(
				[
				AuthTestHelpers::createUserExistsResponse(true),      // User exists
				AuthTestHelpers::createPermissionExistsResponse(false), // Permission doesn't exist
				AuthTestHelpers::createEmptySuccessResponse(),        // INSERT success
				]
			);
			$this->injectClientMock($grantHandler, $grantClient);

			$grantTask = $grantHandler->run();
			$this->assertTrue($grantTask->isSucceed(), "Failed to grant permission #$index");
		}
	}

	public function testErrorPropagation(): void {
		// Test that database errors are properly propagated

		$createRequest = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => 'P03: syntax error, unexpected tablename, expecting '.
				"CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER",
			'payload' => "CREATE USER 'erroruser' IDENTIFIED BY 'pass123'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'admin',
			]
		);

		$createPayload = Payload::fromRequest($createRequest);
		$createHandler = new UserHandler($createPayload);

		// Mock database error response
		$errorResponse = $this->createMock(Response::class);
		$errorResponse->method('hasError')->willReturn(true);
		$errorResponse->method('getError')->willReturn('Database connection failed');

		$createClient = $this->createSequentialClientMock(
			[
			AuthTestHelpers::createUserExistsResponse(false), // User doesn't exist
			$this->createErrorResponse('Database connection failed'), // INSERT fails
			]
		);
		$this->injectClientMock($createHandler, $createClient);

		$task = $createHandler->run();
		$this->assertFalse($task->isSucceed(), 'Task should fail with database error');
		$this->assertStringContainsString('Database connection failed', $task->getError()->getResponseError());
	}

	public function testPayloadRouting(): void {
		// Test that different commands are routed to correct handlers

		$testCases = [
			[
				'payload' => "CREATE USER 'test' IDENTIFIED BY 'pass'",
				'error' => 'P03: syntax error, unexpected tablename, expecting '.
					"CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER",
				'expectedHandler' => 'UserHandler',
			],
			[
				'payload' => "DROP USER 'test'",
				'error' => "P03: syntax error, unexpected tablename, expecting FUNCTION or PLUGIN or TABLE near 'user",
				'expectedHandler' => 'UserHandler',
			],
			[
				'payload' => "GRANT read ON * TO 'test'",
				'error' => "P02: syntax error, unexpected identifier near 'GRANT",
				'expectedHandler' => 'GrantRevokeHandler',
			],
			[
				'payload' => "REVOKE read ON * FROM 'test'",
				'error' => "P02: syntax error, unexpected identifier near 'REVOKE",
				'expectedHandler' => 'GrantRevokeHandler',
			],
			[
				'payload' => "SET PASSWORD 'newpass'",
				'error' => "P01: syntax error, unexpected string, expecting '=' near",
				'expectedHandler' => 'PasswordHandler',
			],
			[
				'payload' => 'SHOW MY PERMISSIONS',
				'error' => "P01: syntax error, unexpected identifier, expecting VARIABLES near 'MY PERMISSIONS'",
				'expectedHandler' => 'ShowHandler',
			],
		];

		foreach ($testCases as $case) {
			$request = Request::fromArray(
				[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => $case['error'],
				'payload' => $case['payload'],
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
				'user' => 'admin',
				]
			);

			$payload = Payload::fromRequest($request);
			$handlerClassName = $payload->getHandlerClassName();

			$this->assertStringEndsWith(
				$case['expectedHandler'], $handlerClassName,
				"Wrong handler for: {$case['payload']}"
			);
		}
	}

	public function testConcurrentOperationsSimulation(): void {
		// Simulate multiple operations happening "simultaneously"
		// (In real scenario these would be actual concurrent requests)

		$operations = [
			['type' => 'create', 'user' => 'user1'],
			['type' => 'create', 'user' => 'user2'],
			['type' => 'grant', 'user' => 'user1', 'action' => 'read'],
			['type' => 'grant', 'user' => 'user2', 'action' => 'write'],
		];

		$results = [];

		foreach ($operations as $op) {
			switch ($op['type']) {
				case 'create':
					$request = Request::fromArray(
						[
						'version' => Buddy::PROTOCOL_VERSION,
						'error' => 'P03: syntax error, unexpected tablename, expecting '.
							"CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER",
						'payload' => "CREATE USER '{$op['user']}' IDENTIFIED BY 'pass123'",
						'format' => RequestFormat::SQL,
						'endpointBundle' => ManticoreEndpoint::Sql,
						'path' => 'sql?mode=raw',
						'user' => 'admin',
						]
					);

					$payload = Payload::fromRequest($request);
					$handler = new UserHandler($payload);

					$client = $this->createSequentialClientMock(
						[
						AuthTestHelpers::createUserExistsResponse(false), // User doesn't exist
						AuthTestHelpers::createEmptySuccessResponse(),     // INSERT success
						]
					);
					break;

				case 'grant':
					$request = Request::fromArray(
						[
						'version' => Buddy::PROTOCOL_VERSION,
						'error' => 'P02: syntax error, unexpected identifier near '.
							"'GRANT {$op['action']} ON * TO '{$op['user']}''",
						'payload' => "GRANT {$op['action']} ON * TO '{$op['user']}'",
						'format' => RequestFormat::SQL,
						'endpointBundle' => ManticoreEndpoint::Sql,
						'path' => 'sql?mode=raw',
						'user' => 'admin',
						]
					);

					$payload = Payload::fromRequest($request);
					$handler = new GrantRevokeHandler($payload);

					$client = $this->createSequentialClientMock(
						[
						AuthTestHelpers::createUserExistsResponse(true),      // User exists
						AuthTestHelpers::createPermissionExistsResponse(false), // Permission doesn't exist
						AuthTestHelpers::createEmptySuccessResponse(),        // INSERT success
						]
					);
					break;
			}

			$this->injectClientMock($handler, $client);
			$task = $handler->run();
			$results[] = $task->isSucceed();
		}

		// All operations should succeed
		foreach ($results as $index => $success) {
			$this->assertTrue($success, "Operation #$index failed");
		}
	}
}
