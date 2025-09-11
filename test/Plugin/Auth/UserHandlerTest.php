<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Base\Plugin\Auth\UserHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class UserHandlerTest extends TestCase {
	use TestProtectedTrait;

	public function testHandleCreateUserSuccess(): void {
		$payload = new Payload();
		$payload->type = 'create';
		$payload->username = 'testuser';
		$payload->password = 'testpass';

		$handler = new UserHandler($payload);
		$clientMock = $this->createMock(HTTPClient::class);
		$clientMock->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				new Response([['data' => [['c' => 0]]]]), // getUserCount
				new Response([]) // INSERT
			);
		$handler->setManticoreClient($clientMock);

		$result = self::invokeMethod($handler, 'handleCreateUser', ['testuser', 0]);
		$this->assertInstanceOf(TaskResult::class, $result);
		$this->assertEmpty($result->getData());
	}

	public function testHandleCreateUserAlreadyExists(): void {
		$payload = new Payload();
		$payload->type = 'create';
		$payload->username = 'testuser';
		$payload->password = 'testpass';

		$handler = new UserHandler($payload);
		$this->expectException(\Manticoresearch\Buddy\Core\Error\GenericError::class);
		$this->expectExceptionMessage("User 'testuser' already exists.");
		self::invokeMethod($handler, 'handleCreateUser', ['testuser', 1]);
	}

	public function testHandleCreateUserNoPassword(): void {
		$payload = new Payload();
		$payload->type = 'create';
		$payload->username = 'testuser';

		$handler = new UserHandler($payload);
		$this->expectException(\Manticoresearch\Buddy\Core\Error\GenericError::class);
		$this->expectExceptionMessage('Password is required for CREATE USER.');
		self::invokeMethod($handler, 'handleCreateUser', ['testuser', 0]);
	}

	public function testHandleDropUserSuccess(): void {
		$payload = new Payload();
		$payload->type = 'drop';
		$payload->username = 'testuser';

		$handler = new UserHandler($payload);
		$clientMock = $this->createMock(HTTPClient::class);
		$clientMock->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				new Response([]), // DELETE from perms
				new Response([]) // DELETE from users
			);
		$handler->setManticoreClient($clientMock);

		$result = self::invokeMethod($handler, 'handleDropUser', ['testuser', 1]);
		$this->assertInstanceOf(TaskResult::class, $result);
		$this->assertEmpty($result->getData());
	}

	public function testHandleDropUserDoesNotExist(): void {
		$payload = new Payload();
		$payload->type = 'drop';
		$payload->username = 'nonexistent';

		$handler = new UserHandler($payload);
		$this->expectException(\Manticoresearch\Buddy\Core\Error\GenericError::class);
		$this->expectExceptionMessage("User 'nonexistent' does not exist.");
		self::invokeMethod($handler, 'handleDropUser', ['nonexistent', 0]);
	}
}
