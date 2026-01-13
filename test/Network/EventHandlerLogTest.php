<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyTest\Network;

use Manticoresearch\Buddy\Base\Plugin\Auth\Exception\AuthError;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Response;
use PHPUnit\Framework\TestCase;

final class EventHandlerLogTest extends TestCase {
	public function testAuthErrorProducesLogEntityInResponse(): void {
		$requestId = 'req-1';
		$originalError = 'P03: syntax error, unexpected tablename, expecting ' .
			"CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER";
		$requestPayload = [
			'type' => 'unknown sql request',
			'error' => [
				'message' => $originalError,
				'body' => ['error' => $originalError],
			],
			'message' => [
				'path_query' => '',
				'http_method' => '',
				'body' => "CREATE USER 'john' IDENTIFIED BY 'secret' EXTRA",
			],
			'version' => 3,
			'user' => 'all',
		];

		$needle = 'Invalid payload: Does not match CREATE USER or DROP USER command.';
		$request = Request::fromPayload($requestPayload, $requestId);
		$exception = AuthError::createFromRequest($request, $needle, true);
		$response = Response::fromError($exception, RequestFormat::SQL);

		echo $response;
		/** @var array<string,mixed> $decoded */
		$decoded = (array)json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);

		$this->assertArrayHasKey('log', $decoded);
		$this->assertIsArray($decoded['log']);
		$this->assertSame('auth', $decoded['log'][0]['type'] ?? null);
		$this->assertSame('ERROR', $decoded['log'][0]['severity'] ?? null);
		$message = (string)($decoded['log'][0]['message'] ?? '');
		$this->assertStringContainsString($needle, $message);
		$this->assertStringContainsString('rid=' . $requestId, $message);
	}
}
