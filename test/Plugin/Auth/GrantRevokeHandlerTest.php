<?php declare(strict_types=1);

/*
 Copyright (c) 2024-2025, Manticore Software LTD (https://manticoresearch.com)
*/

namespace Manticoresearch\BuddyTest\Plugin\Auth;

use Manticoresearch\Buddy\Base\Plugin\Auth\GrantRevokeHandler;
use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\CoreTest\Trait\TestHTTPServerTrait;
use Manticoresearch\BuddyTest\Lib\BuddyRequestError;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Auth plugin GrantRevokeHandler class
 */
class GrantRevokeHandlerTest extends TestCase {
	use TestHTTPServerTrait;

	protected function tearDown(): void {
		self::finishMockManticoreServer();
	}

	/**
	 * Test GRANT command with valid user
	 */
	public function testGrantSuccess(): void {
		echo "\nTesting GRANT command executes properly\n";
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P02: syntax error, unexpected identifier near 'GRANT read ON * TO 'testuser''",
			'payload' => "GRANT read ON * TO 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'all',
			]
		);
		$payload = Payload::fromRequest($request);
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new HTTPClient($serverUrl);
		$manticoreClient->setForceSync(true);

		$manticoreClient->method('sendRequest')
			->willReturnCallback(
				function ($query) {
					if (strpos($query, 'SELECT count(*)') !== false) {
						return new \Manticoresearch\Buddy\Core\ManticoreSearch\Response([['data' => [['c' => 1]]]]);
					}
					if (strpos($query, 'REPLACE INTO system.auth_permissions') !== false) {
						return new \Manticoresearch\Buddy\Core\ManticoreSearch\Response([['total' => 1, 'error' => '', 'warning' => '']]);
					}
					return new \Manticoresearch\Buddy\Core\ManticoreSearch\Response([]);
				}
			);

		$handler = new GrantRevokeHandler($payload);
		$handler->setManticoreClient($manticoreClient);
		go(
			function () use ($handler) {
				$task = $handler->run();
				$task->wait(true);
				$this->assertTrue($task->isSucceed());
				$this->assertInstanceOf(TaskResult::class, $task->getResult());
				$this->assertEmpty($task->getResult()->getData());
				self::finishMockManticoreServer();
			}
		);
		\Swoole\Event::wait();
	}

	/**
	 * Test GRANT command with non-existent user
	 */
	public function testGrantNonExistentUser(): void {
		echo "\nTesting GRANT command with non-existent user\n";
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P02: syntax error, unexpected identifier near 'GRANT read ON * TO 'nonexistent''",
			'payload' => "GRANT read ON * TO 'nonexistent'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'all',
			]
		);
		$payload = Payload::fromRequest($request);
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new HTTPClient($serverUrl);
		$manticoreClient->setForceSync(true);

		$manticoreClient->method('sendRequest')
			->willReturn(new \Manticoresearch\Buddy\Core\ManticoreSearch\Response([['data' => [['c' => 0]]]]));

		$handler = new GrantRevokeHandler($payload);
		$handler->setManticoreClient($manticoreClient);
		go(
			function () use ($handler) {
				$task = $handler->run();
				$this->expectException(BuddyRequestError::class);
				$this->expectExceptionMessage("User 'nonexistent' does not exist.");
				$task->getResult();
				self::finishMockManticoreServer();
			}
		);
		\Swoole\Event::wait();
	}

	/**
	 * Test REVOKE command
	 */
	public function testRevokeSuccess(): void {
		echo "\nTesting REVOKE command executes properly\n";
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P02: syntax error, unexpected identifier near 'REVOKE read ON * FROM 'testuser''",
			'payload' => "REVOKE read ON * FROM 'testuser'",
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'all',
			]
		);
		$payload = Payload::fromRequest($request);
		$serverUrl = self::setUpMockManticoreServer(false);
		$manticoreClient = new HTTPClient($serverUrl);
		$manticoreClient->setForceSync(true);

		$manticoreClient->method('sendRequest')
			->willReturn(new \Manticoresearch\Buddy\Core\ManticoreSearch\Response([['total' => 1, 'error' => '', 'warning' => '']]));

		$handler = new GrantRevokeHandler($payload);
		$handler->setManticoreClient($manticoreClient);
		go(
			function () use ($handler) {
				$task = $handler->run();
				$task->wait(true);
				$this->assertTrue($task->isSucceed());
				$this->assertInstanceOf(TaskResult::class, $task->getResult());
				$this->assertEmpty($task->getResult()->getData());
				self::finishMockManticoreServer();
			}
		);
		\Swoole\Event::wait();
	}
}
