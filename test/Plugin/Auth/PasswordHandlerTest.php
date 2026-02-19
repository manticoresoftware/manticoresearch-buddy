<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyTest\Plugin\Auth;

use Manticoresearch\Buddy\Base\Plugin\Auth\PasswordHandler;
use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\BuddyTest\Trait\AuthTestTrait;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class PasswordHandlerTest extends TestCase {
	use TestProtectedTrait;
	use AuthTestTrait;

	public function testHandlePasswordUpdateSuccess(): void {
		$payload = new Payload();
		$payload->password = 'newpass123';
		$payload->actingUser = 'testuser';

		$handler = new PasswordHandler($payload);

		// Mock existing user data with a valid hashes structure
		$existingHashes = json_encode(
			[
			'password_sha1_no_salt' => 'old_sha1',
			'password_sha256' => 'old_sha256',
			'bearer_sha256' => 'existing_token_hash',
			]
		);

		$userDataResponse = $this->createStructResponse([['salt' => 'salt123', 'hashes' => $existingHashes]]);
		$replaceResponse = $this->createEmptySuccessResponse();

		$clientMock = $this->createSequentialClientMock(
			[
			$userDataResponse, // getUserData
			$replaceResponse,   // REPLACE
			]
		);

		$this->injectClientMock($handler, $clientMock);

		$result = self::invokeMethod($handler, 'processRequest');
		$this->assertInstanceOf(TaskResult::class, $result);
		$struct = $result->getStruct();
		$this->assertIsArray($struct);
		$this->assertEmpty($struct[0]['data'] ?? []);
	}

	public function testHandlePasswordUpdateNoPassword(): void {
		$payload = new Payload();
		$payload->actingUser = 'testuser';
		$payload->password = ''; // Empty password

		$handler = new PasswordHandler($payload);

		$this->assertGenericError(
			fn() => self::invokeMethod($handler, 'processRequest'),
			'Password cannot be empty.'
		);
	}

	public function testHandlePasswordUpdateNonExistentUser(): void {
		$payload = new Payload();
		$payload->password = 'newpass123';
		$payload->actingUser = 'nonexistent';

		$handler = new PasswordHandler($payload);
		$clientMock = $this->createSequentialClientMock(
			[
			$this->createStructResponse([]), // Empty user data response
			]
		);

		$this->injectClientMock($handler, $clientMock);

		$this->assertGenericError(
			fn() => self::invokeMethod($handler, 'processRequest'),
			"User 'nonexistent' does not exist."
		);
	}

	public function testValidatePassword(): void {
		$payload = new Payload();
		$handler = new PasswordHandler($payload);

		// Test valid passwords
		$validPasswords = ['password123', 'P@ssw0rd!', 'averylongpasswordthatisvalid', '12345678'];
		foreach ($validPasswords as $password) {
			// Should not throw exception
			self::invokeMethod($handler, 'validatePassword', [$password]);
			$this->assertTrue(true); // If we get here, validation passed
		}

		// Test invalid passwords
		$invalidPasswords = [
			['', 'Password cannot be empty.'],
			['short', 'Password must be at least 8 characters long.'],
			[str_repeat('a', 129), 'Password is too long (max 128 characters).'],
		];

		foreach ($invalidPasswords as [$password, $expectedMessage]) {
			$this->assertGenericError(
				fn() => self::invokeMethod($handler, 'validatePassword', [$password]),
				$expectedMessage
			);
		}
	}
}
