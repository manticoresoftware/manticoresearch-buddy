<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Backup\Lib\ManticoreAuth;
use Manticoresearch\Buddy\Base\Plugin\Backup\Handler as BackupHandler;
use Manticoresearch\Buddy\Base\Plugin\Backup\Payload as BackupPayload;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;
use PHPUnit\Framework\TestCase;

final class BackupHandlerTest extends TestCase {
	protected function tearDown(): void {
		ConfigManager::set('BUDDY_AUTH_TOKEN', '');
	}

	public function testCreateAuthReturnsNullWithoutTransportToken(): void {
		ConfigManager::set('BUDDY_AUTH_TOKEN', '');
		$handler = new BackupHandler(new BackupPayload('/tmp', [], []));

		$this->assertNull($this->createAuth($handler));
	}

	public function testCreateAuthUsesTransportTokenAndOriginalSqlUser(): void {
		ConfigManager::set('BUDDY_AUTH_TOKEN', 'transport-token');
		$payload = new BackupPayload('/tmp', [], []);
		$payload->user = 'alice';
		$handler = new BackupHandler($payload);

		$auth = $this->createAuth($handler);

		$this->assertInstanceOf(ManticoreAuth::class, $auth);
		$this->assertNull($auth->user);
		$this->assertNull($auth->password);
		$this->assertSame('transport-token', $auth->token);
		$this->assertSame('alice', $auth->delegatedUser);
		$this->assertSame('Manticore Buddy/backup', $auth->userAgent);
		$this->assertSame(
			[
				'Authorization: Bearer transport-token',
				'X-Manticore-User: alice',
				'User-Agent: Manticore Buddy/backup',
			],
			$auth->getHeaders()
		);
	}

	private function createAuth(BackupHandler $handler): ?ManticoreAuth {
		$method = new ReflectionMethod(BackupHandler::class, 'createAuth');
		$method->setAccessible(true);
		/** @var ?ManticoreAuth */
		return $method->invoke($handler);
	}
}
