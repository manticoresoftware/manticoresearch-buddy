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
use Manticoresearch\Buddy\Base\Plugin\Auth\UserHandler;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\BuddyTest\Helper\AuthTestHelpers;
use Manticoresearch\BuddyTest\Trait\AuthTestTrait;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class UserHandlerTest extends TestCase {
	use TestProtectedTrait;
	use AuthTestTrait;

	public function testHandleCreateUserSuccess(): void {
		$payload = new Payload();
		$payload->type = 'create';
		$payload->username = 'testuser';
		$payload->password = 'testpass123';

		$handler = new UserHandler($payload);
		$clientMock = $this->createSequentialClientMock(
			[
			AuthTestHelpers::createUserExistsResponse(false), // User doesn't exist
			AuthTestHelpers::createEmptySuccessResponse(),     // INSERT success
			]
		);

		$this->injectClientMock($handler, $clientMock);

		$result = self::invokeMethod($handler, 'handleCreateUser', ['testuser', 0]);
		$this->assertInstanceOf(TaskResult::class, $result);

		// Should return token data for user
		$struct = $result->getStruct();
		$this->assertNotEmpty($struct);
		$data = $struct[0]['data'][0];
		$this->assertArrayHasKey('token', $data);
		$this->assertArrayHasKey('username', $data);
		$this->assertEquals('testuser', $data['username']);
	}

	public function testHandleCreateUserAlreadyExists(): void {
		$payload = new Payload();
		$payload->type = 'create';
		$payload->username = 'testuser';
		$payload->password = 'testpass123';

		$handler = new UserHandler($payload);

		$this->assertGenericError(
			fn() => self::invokeMethod($handler, 'handleCreateUser', ['testuser', 1]),
			"User 'testuser' already exists."
		);
	}

	public function testHandleCreateUserNoPassword(): void {
		$payload = new Payload();
		$payload->type = 'create';
		$payload->username = 'testuser';

		$handler = new UserHandler($payload);

		$this->assertGenericError(
			fn() => self::invokeMethod($handler, 'handleCreateUser', ['testuser', 0]),
			'Password is required for CREATE USER.'
		);
	}

	public function testHandleDropUserSuccess(): void {
		$payload = new Payload();
		$payload->type = 'drop';
		$payload->username = 'testuser';

		$handler = new UserHandler($payload);
		$clientMock = $this->createSequentialClientMock(
			[
			AuthTestHelpers::createEmptySuccessResponse(), // DELETE from perms
			AuthTestHelpers::createEmptySuccessResponse(),  // DELETE from users
			]
		);

		$this->injectClientMock($handler, $clientMock);

		$result = self::invokeMethod($handler, 'handleDropUser', ['testuser', 1]);
		$this->assertInstanceOf(TaskResult::class, $result);
		$struct = $result->getStruct();
		$this->assertEmpty($struct[0]['data'] ?? []);
	}

	public function testHandleDropUserDoesNotExist(): void {
		$payload = new Payload();
		$payload->type = 'drop';
		$payload->username = 'nonexistent';

		$handler = new UserHandler($payload);

		$this->assertGenericError(
			fn() => self::invokeMethod($handler, 'handleDropUser', ['nonexistent', 0]),
			"User 'nonexistent' does not exist."
		);
	}

	public function testValidateUsername(): void {
		$payload = new Payload();
		$handler = new UserHandler($payload);

		// Test valid usernames
		$validUsernames = ['user1', 'test_user', 'user-name', 'user.name', 'a'];
		foreach ($validUsernames as $username) {
			// Should not throw exception
			self::invokeMethod($handler, 'validateUsername', [$username]);
			$this->assertTrue(true); // If we get here, validation passed
		}

		// Test invalid usernames
		$invalidUsernames = [
			['', 'Username cannot be empty.'],
			[str_repeat('a', 65), 'Username is too long (max 64 characters).'],
			['user@name', 'Username contains invalid characters.'],
			['user name', 'Username contains invalid characters.'],
			['user#name', 'Username contains invalid characters.'],
		];

		foreach ($invalidUsernames as [$username, $expectedMessage]) {
			$this->assertGenericError(
				fn() => self::invokeMethod($handler, 'validateUsername', [$username]),
				$expectedMessage
			);
		}
	}

	public function testGenerateToken(): void {
		$payload = new Payload();
		$handler = new UserHandler($payload);

		$token1 = self::invokeMethod($handler, 'generateToken');
		$token2 = self::invokeMethod($handler, 'generateToken');

		// Tokens should be strings
		$this->assertIsString($token1);
		$this->assertIsString($token2);

		// Tokens should be different (very high probability)
		$this->assertNotEquals($token1, $token2);

		// Tokens should be hex strings of correct length (64 chars = 32 bytes * 2)
		$this->assertEquals(64, strlen($token1));
		$this->assertTrue(ctype_xdigit($token1));
	}
}
