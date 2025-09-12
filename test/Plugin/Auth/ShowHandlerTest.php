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
use Manticoresearch\Buddy\Base\Plugin\Auth\ShowHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\BuddyTest\Helper\AuthTestHelpers;
use Manticoresearch\BuddyTest\Trait\AuthTestTrait;
use PHPUnit\Framework\TestCase;

class ShowHandlerTest extends TestCase {
	use AuthTestTrait;

	public function testRunShowPermissions(): void {
		$payload = new Payload();
		$payload->actingUser = 'testuser';

		$handler = new ShowHandler($payload);
		$permissionsData = [
			['Username' => 'testuser', 'action' => 'read', 'Target' => '*', 'Allow' => '1', 'Budget' => '{}'],
		];

		$permissionsResponse = AuthTestHelpers::createPermissionResponse($permissionsData);
		$struct = $this->getStruct($permissionsResponse, $handler);
		$this->assertEquals($permissionsData, $struct[0]['data']);
	}

	public function testRunShowPermissionsEmpty(): void {
		$payload = new Payload();
		$payload->actingUser = 'nonexistent';

		$handler = new ShowHandler($payload);
		$emptyResponse = AuthTestHelpers::createPermissionResponse([]);
		$struct = $this->getStruct($emptyResponse, $handler);
		$this->assertEmpty($struct[0]['data'] ?? []);
	}

	public function testShowMyPermissionsWithFiltering(): void {
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION,
			'error' => "P01: syntax error, unexpected identifier, expecting VARIABLES near 'MY PERMISSIONS'",
			'payload' => 'SHOW MY PERMISSIONS',
			'format' => RequestFormat::SQL,
			'endpointBundle' => ManticoreEndpoint::Sql,
			'path' => 'sql?mode=raw',
			'user' => 'user2',
			]
		);
		$payload = Payload::fromRequest($request);

		$mockPermissions = [
			['Username' => 'user1', 'action' => 'read', 'Target' => '*', 'Allow' => '1', 'Budget' => '{}'],
			['Username' => 'user2', 'action' => 'write', 'Target' => 'table/test', 'Allow' => '1', 'Budget' => '{}'],
			['Username' => 'user3', 'action' => 'admin', 'Target' => '*', 'Allow' => '1', 'Budget' => '{}'],
		];

		$permissionsResponse = AuthTestHelpers::createPermissionResponse($mockPermissions);
		$clientMock = $this->createSequentialClientMock([$permissionsResponse]);

		$handler = new ShowHandler($payload);
		$this->injectClientMock($handler, $clientMock);

		$task = $handler->run();
		$this->assertTrue($task->isSucceed());

		$struct = $task->getResult()->getStruct();
		$resultData = $struct[0]['data'];
		$this->assertCount(1, $resultData); // Only user2 permissions
		$this->assertEquals('user2', $resultData[0]['Username']);
		$this->assertEquals('write', $resultData[0]['action']);
	}

	public function testShowMyPermissionsError(): void {
		$payload = new Payload();
		$payload->actingUser = 'testuser';

		$errorResponse = $this->createErrorResponse('Database connection failed');
		$clientMock = $this->createSequentialClientMock([$errorResponse]);

		$handler = new ShowHandler($payload);
		$this->injectClientMock($handler, $clientMock);

		$task = $handler->run();
		$this->assertFalse($task->isSucceed());
		$this->assertStringContainsString(
			'Database connection failed',
			$task->getError()->getResponseError()
		);
	}

	/**
	 * @param Response $permissionsResponse
	 * @param ShowHandler $handler
	 *
	 * @return mixed
	 */
	public function getStruct(
		Response $permissionsResponse,
		ShowHandler $handler
	): mixed {
		$clientMock = $this->createSequentialClientMock([$permissionsResponse]);
		$this->injectClientMock($handler, $clientMock);

		$task = $handler->run();
		$result = $task->getResult();
		$this->assertInstanceOf(TaskResult::class, $result);
		return $result->getStruct();
	}
}
