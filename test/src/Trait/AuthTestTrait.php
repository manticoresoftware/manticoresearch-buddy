<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyTest\Trait;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\MockObject\MockObject;

trait AuthTestTrait {

	/**
	 * Inject a mock HTTP client into a handler's protected manticoreClient property
	 *
	 * @param object $handler The handler instance
	 * @param HTTPClient|MockObject $client The mock client to inject
	 */
	protected function injectClientMock(object $handler, HTTPClient|MockObject $client): void {
		$reflection = new \ReflectionClass($handler);
		$property = $reflection->getProperty('manticoreClient');
		$property->setAccessible(true);
		$property->setValue($handler, $client);
	}

	/**
	 * Create a mock Response with Struct data
	 *
	 * @param array<mixed> $data The data to include in the response
	 * @param bool $hasError Whether the response should indicate an error
	 * @param string $errorMessage Error message if hasError is true
	 * @return MockObject|Response
	 */
	protected function createStructResponse(
		array $data,
		bool $hasError = false,
		string $errorMessage = ''
	): MockObject|Response {
		$response = $this->createMock(Response::class);
		$response->method('getResult')->willReturn(Struct::fromData([['data' => $data]]));
		$response->method('hasError')->willReturn($hasError);

		if ($hasError) {
			$response->method('getError')->willReturn($errorMessage);
		}

		return $response;
	}

	/**
	 * Create a mock Response for user existence check
	 *
	 * @param bool $userExists Whether the user exists
	 * @return MockObject|Response
	 */
	protected function createUserExistsResponse(bool $userExists): MockObject|Response {
		return $this->createStructResponse(
			[['c' => $userExists ? 1 : 0]],
			false
		);
	}

	/**
	 * Create a mock Response for permission existence check
	 *
	 * @param bool $permissionExists Whether the permission exists
	 * @return MockObject|Response
	 */
	protected function createPermissionExistsResponse(bool $permissionExists): MockObject|Response {
		return $this->createStructResponse(
			[['c' => $permissionExists ? 1 : 0]],
			false
		);
	}

	/**
	 * Create an empty success response (for INSERT/UPDATE/DELETE operations)
	 *
	 * @return MockObject|Response
	 */
	protected function createEmptySuccessResponse(): MockObject|Response {
		$response = $this->createMock(Response::class);
		$response->method('getResult')->willReturn(Struct::fromData([['data' => []]]));
		$response->method('hasError')->willReturn(false);
		return $response;
	}

	/**
	 * Create an error response
	 *
	 * @param string $errorMessage The error message
	 * @return MockObject|Response
	 */
	protected function createErrorResponse(string $errorMessage): MockObject|Response {
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(true);
		$response->method('getError')->willReturn($errorMessage);
		return $response;
	}

	/**
	 * Assert that a GenericError is thrown with the expected message
	 *
	 * @param callable $testCode The code that should throw the exception
	 * @param string $expectedMessage The expected error message
	 */
	protected function assertGenericError(callable $testCode, string $expectedMessage): void {
		try {
			$testCode();
			$this->fail('Expected GenericError to be thrown');
		} catch (GenericError $e) {
			$this->assertEquals($expectedMessage, $e->getResponseError());
		}
	}

	/**
	 * Create a mock HTTP client that returns a sequence of responses
	 *
	 * @param array<MockObject|Response> $responses The responses to return in sequence
	 * @return MockObject|HTTPClient
	 */
	protected function createSequentialClientMock(array $responses): MockObject|HTTPClient {
		$clientMock = $this->createMock(HTTPClient::class);
		$responseIndex = 0;

		$clientMock->method('sendRequest')
			->willReturnCallback(
				function () use ($responses, &$responseIndex) {
					if ($responseIndex < sizeof($responses)) {
						return $responses[$responseIndex++];
					}
					return $this->createEmptySuccessResponse();
				}
			);

		return $clientMock;
	}
}
