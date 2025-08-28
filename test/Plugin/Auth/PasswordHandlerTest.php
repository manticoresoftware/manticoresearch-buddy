<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Base\Plugin\Auth\PasswordHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\CoreTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class PasswordHandlerTest extends TestCase {
	use TestProtectedTrait;

	public function testHandlePasswordUpdateSuccess(): void {
		$payload = new Payload();
		$payload->password = 'newpass';
		$payload->actingUser = 'testuser';

		$handler = new PasswordHandler($payload);
		$clientMock = $this->createMock(HTTPClient::class);
		$clientMock->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				new Response([['data' => [['salt' => 'salt123', 'hashes' => '{}']]]]), // getUserData
				new Response([]) // REPLACE
			);
		$handler->setManticoreClient($clientMock);

		$result = self::invokeMethod($handler, 'processRequest');
		$this->assertInstanceOf(TaskResult::class, $result);
		$this->assertEmpty($result->getData());
	}

	public function testHandlePasswordUpdateNoPassword(): void {
		$payload = new Payload();
		$payload->actingUser = 'testuser';

		$handler = new PasswordHandler($payload);
		$this->expectException(\Manticoresearch\Buddy\Core\Error\GenericError::class);
		$this->expectExceptionMessage("Password is required for SET PASSWORD.");
		self::invokeMethod($handler, 'processRequest');
	}

	public function testHandlePasswordUpdateNonExistentUser(): void {
		$payload = new Payload();
		$payload->password = 'newpass';
		$payload->actingUser = 'nonexistent';

		$handler = new PasswordHandler($payload);
		$clientMock = $this->createMock(HTTPClient::class);
		$clientMock->method('sendRequest')
			->willReturn(new Response([['data' => []]]));
		$handler->setManticoreClient($clientMock);

		$this->expectException(\Manticoresearch\Buddy\Core\Error\GenericError::class);
		$this->expectExceptionMessage("User 'nonexistent' does not exist.");
		self::invokeMethod($handler, 'processRequest');
	}
}
