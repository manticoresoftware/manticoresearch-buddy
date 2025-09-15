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
use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class GrantRevokeHandlerTest extends TestCase {
	use TestProtectedTrait;

	/**
	 * @param array<Response> $responses
	 */
	private function createMockClient(array $responses): HTTPClient {
		$clientMock = $this->createMock(HTTPClient::class);
		$responseIndex = 0;

		$clientMock->method('sendRequest')
			->willReturnCallback(
				function () use ($responses, &$responseIndex) {
					if ($responseIndex < sizeof($responses)) {
						return $responses[$responseIndex++];
					}
					return Response::fromBody((string)json_encode([]));
				}
			);

		return $clientMock;
	}

	private function setClientOnHandler(GrantRevokeHandler $handler, HTTPClient $client): void {
		$reflection = new \ReflectionClass($handler);
		$property = $reflection->getProperty('manticoreClient');
		$property->setAccessible(true);
		$property->setValue($handler, $client);
	}

	public function testGrantSuccess(): void {
		$payload = new Payload();
		$payload->type = 'grant';
		$payload->username = 'testuser';
		$payload->action = 'read';
		$payload->target = '*';
		$payload->budget = '{}';

		$handler = new GrantRevokeHandler($payload);

		$responses = [
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 1]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // User exists
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 0]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // Permission doesn't exist
			Response::fromBody((string)json_encode([])), // INSERT success
		];

		$client = $this->createMockClient($responses);
		$this->setClientOnHandler($handler, $client);

		$result = $this->invokeMethod($handler, 'handleGrant', ['testuser', 'read', '*', '{}']);
		$this->assertInstanceOf(TaskResult::class, $result);
		$struct = $result->getStruct();
		$this->assertIsArray($struct);
		$this->assertEmpty($struct[0]['data'] ?? []);
	}

	public function testGrantNonExistentUser(): void {
		$payload = new Payload();
		$payload->type = 'grant';
		$payload->username = 'nonexistent';
		$payload->action = 'read';
		$payload->target = '*';
		$payload->budget = '{}';

		$handler = new GrantRevokeHandler($payload);

		$responses = [
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 0]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // User doesn't exist
		];

		$client = $this->createMockClient($responses);
		$this->setClientOnHandler($handler, $client);

		try {
			$this->invokeMethod($handler, 'handleGrant', ['nonexistent', 'read', '*', '{}']);
			$this->fail('Expected GenericError to be thrown');
		} catch (GenericError $e) {
			$this->assertEquals("User 'nonexistent' does not exist.", $e->getResponseError());
		}
	}

	public function testGrantDuplicatePermission(): void {
		$payload = new Payload();
		$payload->type = 'grant';
		$payload->username = 'testuser';
		$payload->action = 'read';
		$payload->target = '*';
		$payload->budget = '{}';

		$handler = new GrantRevokeHandler($payload);

		$responses = [
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 1]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // User exists
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 1]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // Permission already exists
		];

		$client = $this->createMockClient($responses);
		$this->setClientOnHandler($handler, $client);

		try {
			$this->invokeMethod($handler, 'handleGrant', ['testuser', 'read', '*', '{}']);
			$this->fail('Expected GenericError to be thrown');
		} catch (GenericError $e) {
			$this->assertEquals("User 'testuser' already has 'read' permission on '*'.", $e->getResponseError());
		}
	}

	public function testRevokeSuccess(): void {
		$payload = new Payload();
		$payload->type = 'revoke';
		$payload->username = 'testuser';
		$payload->action = 'read';
		$payload->target = '*';

		$handler = new GrantRevokeHandler($payload);

		$responses = [
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 1]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // User exists
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 1]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // Permission exists
			Response::fromBody((string)json_encode([])), // DELETE success
		];

		$client = $this->createMockClient($responses);
		$this->setClientOnHandler($handler, $client);

		$result = $this->invokeMethod($handler, 'handleRevoke', ['testuser', 'read', '*']);
		$this->assertInstanceOf(TaskResult::class, $result);
		$struct = $result->getStruct();
		$this->assertIsArray($struct);
		$this->assertEmpty($struct[0]['data'] ?? []);
	}

	public function testRevokeNonExistentUser(): void {
		$payload = new Payload();
		$payload->type = 'revoke';
		$payload->username = 'nonexistent';
		$payload->action = 'read';
		$payload->target = '*';

		$handler = new GrantRevokeHandler($payload);

		$responses = [
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 0]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // User doesn't exist
		];

		$client = $this->createMockClient($responses);
		$this->setClientOnHandler($handler, $client);

		try {
			$this->invokeMethod($handler, 'handleRevoke', ['nonexistent', 'read', '*']);
			$this->fail('Expected GenericError to be thrown');
		} catch (GenericError $e) {
			$this->assertEquals("User 'nonexistent' does not exist.", $e->getResponseError());
		}
	}

	public function testRevokeNonExistentPermission(): void {
		$payload = new Payload();
		$payload->type = 'revoke';
		$payload->username = 'testuser';
		$payload->action = 'write';
		$payload->target = '*';

		$handler = new GrantRevokeHandler($payload);

		$responses = [
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 1]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // User exists
			Response::fromBody(
				(string)json_encode(
					[
					[
					'data' => [['c' => 0]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
					],
					]
				)
			), // Permission doesn't exist
		];

		$client = $this->createMockClient($responses);
		$this->setClientOnHandler($handler, $client);

		try {
			$this->invokeMethod($handler, 'handleRevoke', ['testuser', 'write', '*']);
			$this->fail('Expected GenericError to be thrown');
		} catch (GenericError $e) {
			$this->assertEquals("User 'testuser' does not have 'write' permission on '*'.", $e->getResponseError());
		}
	}

	public function testInvalidOperationType(): void {
		$payload = new Payload();
		$payload->type = 'invalid';
		$payload->username = 'testuser';
		$payload->action = 'read';
		$payload->target = '*';

		$handler = new GrantRevokeHandler($payload);

		try {
			$this->invokeMethod($handler, 'processRequest', []);
			$this->fail('Expected GenericError to be thrown');
		} catch (GenericError $e) {
			$this->assertEquals('Invalid operation type for GrantRevokeHandler.', $e->getResponseError());
		}
	}
}
