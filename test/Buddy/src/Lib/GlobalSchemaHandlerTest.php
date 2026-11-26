<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Lib\GlobalSchemaHandler;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;
use PHPUnit\Framework\TestCase;

final class GlobalSchemaHandlerTest extends TestCase {
	private string $previousToken;

	protected function setUp(): void {
		$this->previousToken = ConfigManager::get('BUDDY_AUTH_TOKEN');
	}

	protected function tearDown(): void {
		ConfigManager::set('BUDDY_AUTH_TOKEN', $this->previousToken);
	}

	/** @dataProvider invalidResponses */
	public function testInvalidAuthorizationResponseDeniesOperation(string $token, string $body): void {
		ConfigManager::set('BUDDY_AUTH_TOKEN', $token);
		$client = $this->getMockBuilder(Client::class)
			->disableOriginalConstructor()
			->disableOriginalClone()
			->getMock();
		$client->method('setAuthToken')->willReturnSelf();
		$client->method('clearDelegatedUser')->willReturnSelf();
		$client->method('sendRequest')->willReturn(Response::fromBody($body));

		$handler = $this->getMockForAbstractClass(GlobalSchemaHandler::class);
		$handler->expects($this->never())->method('runAuthorized');
		$handler->setRequestUser('alice');
		$handler->setManticoreClient($client);
		$this->assertDenied($handler);
	}

	/** @return array<string,array{string,string}> */
	public static function invalidResponses(): array {
		return [
			'no token, unexpected raw response' => ['', '{"status":"ok"}'],
			'no token, empty SQL response' => ['', '[{"total":0,"error":"","warning":""}]'],
			'no token, incorrect probe' => ['', '[{"data":[{"buddy_auth_probe":0}],"error":""}]'],
			'no token, authentication error' => ['', '{"error":"Authorization header field missed"}'],
			'token, unexpected raw response' => ['token', '{"status":"ok"}'],
			'token, empty permission list' => ['token', '[{"data":[],"error":"","warning":""}]'],
			'token, incomplete permission' => ['token', '[{"data":[{"username":"alice"}],"error":""}]'],
			'token, other user grant' => [
				'token',
				'[{"data":[{"username":"bob","action":"schema","target":"*","allow":"1"}],"error":""}]',
			],
		];
	}

	public function testAuthorizationTransportFailureDeniesOperation(): void {
		ConfigManager::set('BUDDY_AUTH_TOKEN', 'token');
		$client = $this->createMock(Client::class);
		$client->expects($this->once())->method('sendRequest')->with('SHOW PERMISSIONS')
			->willThrowException(new RuntimeException('connection failed'));
		$handler = $this->getMockForAbstractClass(GlobalSchemaHandler::class);
		$handler->expects($this->never())->method('runAuthorized');
		$handler->setRequestUser('alice');
		$handler->setManticoreClient($client);
		$this->assertDenied($handler);
	}

	public function testMissingDelegatedIdentityDeniesOperation(): void {
		ConfigManager::set('BUDDY_AUTH_TOKEN', 'token');
		$client = $this->createMock(Client::class);
		$client->expects($this->never())->method('sendRequest');
		$handler = $this->getMockForAbstractClass(GlobalSchemaHandler::class);
		$handler->expects($this->never())->method('runAuthorized');
		$handler->setManticoreClient($client);
		$this->assertDenied($handler);
	}

	private function assertDenied(GlobalSchemaHandler $handler): void {
		try {
			$handler->run();
			$this->fail('Expected authorization failure');
		} catch (GenericError $e) {
			$this->assertStringContainsString('Permission denied', $e->getResponseError());
			$this->assertSame(403, $e->getResponseErrorCode());
		}
	}
}
