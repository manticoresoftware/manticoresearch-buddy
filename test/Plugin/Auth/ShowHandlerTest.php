<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Base\Plugin\Auth\ShowHandler;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use PHPUnit\Framework\TestCase;

class ShowHandlerTest extends TestCase {

	public function testRunShowPermissions(): void {
		$payload = new Payload();
		$payload->actingUser = 'testuser';

		$handler = new ShowHandler($payload);
		$clientMock = $this->createMock(HTTPClient::class);
		$clientMock->method('sendRequest')
			->willReturn(new Response([['data' => [
				['Username' => 'testuser', 'action' => 'read', 'Target' => '*', 'Allow' => 'true', 'Budget' => '{}'],
			]]]));
		$handler->setManticoreClient($clientMock);

		$task = $handler->run();
		$result = $task->getResult();
		$this->assertInstanceOf(TaskResult::class, $result);
		$this->assertEquals([['Username' => 'testuser', 'action' => 'read', 'Target' => '*', 'Allow' => 'true', 'Budget' => '{}']], $result->getData());
	}

	public function testRunShowPermissionsEmpty(): void {
		$payload = new Payload();
		$payload->actingUser = 'nonexistent';

		$handler = new ShowHandler($payload);
		$clientMock = $this->createMock(HTTPClient::class);
		$clientMock->method('sendRequest')
			->willReturn(new Response([['data' => []]]));
		$handler->setManticoreClient($clientMock);

		$task = $handler->run();
		$result = $task->getResult();
		$this->assertInstanceOf(TaskResult::class, $result);
		$this->assertEmpty($result->getData());
	}
}
