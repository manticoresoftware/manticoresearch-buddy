<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\BuddyTest\Plugin\PluginsAuthPermissions;

use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\Handler;
use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class HandlerTest extends TestCase {
	public function testSourcePermissionQueriesUseSourceResourceTable(): void {
		$payload = Payload::fromRequest($this->createRequest('GRANT SELECT ON source/orders TO user'));
		$queries = $this->getPermissionQueries($payload);

		$this->assertSame(["GRANT SELECT ON 'system.source_orders' TO user"], $queries);
	}

	public function testMaterializedViewPermissionQueriesUseMaterializedViewResourceTable(): void {
		$payload = Payload::fromRequest($this->createRequest('REVOKE SELECT ON mva/sales FROM user'));
		$queries = $this->getPermissionQueries($payload);

		$this->assertSame(["REVOKE SELECT ON 'system.materialized_view_sales' FROM user"], $queries);
	}

	public function testMaterializedViewResourcePermissionQueriesUseMaterializedViewResourceTable(): void {
		$payload = Payload::fromRequest($this->createRequest('REVOKE SELECT ON materialized view/sales FROM user'));
		$queries = $this->getPermissionQueries($payload);

		$this->assertSame(["REVOKE SELECT ON 'system.materialized_view_sales' FROM user"], $queries);
	}

	public function testChatModelPermissionQueriesAlsoApplyHistoryTable(): void {
		$payload = Payload::fromRequest($this->createRequest('GRANT SELECT ON chat_model/gpt TO user'));
		$queries = $this->getPermissionQueries($payload);

		$this->assertSame(
			[
				"GRANT SELECT ON 'system.chat_model_gpt' TO user",
				"GRANT SELECT ON 'system.chat_history_gpt' TO user",
			],
			$queries
		);
	}

	public function testChatModelResourcePermissionQueriesAlsoApplyHistoryTable(): void {
		$payload = Payload::fromRequest($this->createRequest('GRANT SELECT ON chat model/gpt TO user'));
		$queries = $this->getPermissionQueries($payload);

		$this->assertSame(
			[
				"GRANT SELECT ON 'system.chat_model_gpt' TO user",
				"GRANT SELECT ON 'system.chat_history_gpt' TO user",
			],
			$queries
		);
	}

	public function testChatModelWildcardPermissionQueriesAlsoApplyHistoryWildcard(): void {
		$payload = Payload::fromRequest($this->createRequest('GRANT SELECT ON chat_model/* TO user'));
		$queries = $this->getPermissionQueries($payload);

		$this->assertSame(
			[
				"GRANT SELECT ON 'system.chat_model_*' TO user",
				"GRANT SELECT ON 'system.chat_history_*' TO user",
			],
			$queries
		);
	}

	public function testGrantForChatModelMorphsHistoryTable(): void {
		$payload = Payload::fromRequest($this->createRequest('GRANT SELECT ON chat_model/gpt TO user'));
		$refClass = new ReflectionClass(Handler::class);
		$method = $refClass->getMethod('morphChatHistoryQuery');
		$method->setAccessible(true);

		$this->assertSame(
			"GRANT SELECT ON 'system.chat_history_gpt' TO user",
			$method->invoke(null, $payload)
		);
	}

	/**
	 * @param array<int,string> $expectedQueries
	 * @dataProvider providerHandlerSendsExpectedQueries
	 */
	public function testHandlerSendsExpectedQueries(string $query, array $expectedQueries): void {
		$payload = Payload::fromRequest($this->createRequest($query));
		$client = $this->createMock(Client::class);
		$response = $this->createSuccessfulResponse();
		$requestNumber = 0;

		$client->expects($this->exactly(sizeof($expectedQueries)))
			->method('sendRequest')
			->willReturnCallback(
				function (string $sentQuery) use (&$requestNumber, $expectedQueries, $response): Response {
					$this->assertSame($expectedQueries[$requestNumber], $sentQuery);
					++$requestNumber;

					return $response;
				}
			);

		$handler = new Handler($payload);
		$handler->setManticoreClient($client);

		go(
			function () use ($handler): void {
				$task = $handler->run();
				$task->wait();
				$this->assertTrue($task->isSucceed());
			}
		);

		\Swoole\Event::wait();
	}

	/**
	 * @return array<string,array{query:string,expectedQueries:array<int,string>}>
	 */
	public static function providerHandlerSendsExpectedQueries(): array {
		return [
			'source' => [
				'query' => 'GRANT READ ON source/orders TO user',
				'expectedQueries' => ["GRANT READ ON 'system.source_orders' TO user"],
			],
			'mva' => [
				'query' => 'REVOKE READ ON mva/sales FROM user',
				'expectedQueries' => ["REVOKE READ ON 'system.materialized_view_sales' FROM user"],
			],
			'materialized view' => [
				'query' => 'GRANT READ ON materialized view/sales TO user',
				'expectedQueries' => ["GRANT READ ON 'system.materialized_view_sales' TO user"],
			],
			'chat_model' => [
				'query' => 'GRANT READ ON chat_model/gpt TO user',
				'expectedQueries' => [
					"GRANT READ ON 'system.chat_model_gpt' TO user",
					"GRANT READ ON 'system.chat_history_gpt' TO user",
				],
			],
			'chat model' => [
				'query' => 'REVOKE READ ON chat model/gpt FROM user',
				'expectedQueries' => [
					"REVOKE READ ON 'system.chat_model_gpt' FROM user",
					"REVOKE READ ON 'system.chat_history_gpt' FROM user",
				],
			],
			'chat model wildcard' => [
				'query' => 'GRANT READ ON chat model/* TO user',
				'expectedQueries' => [
					"GRANT READ ON 'system.chat_model_*' TO user",
					"GRANT READ ON 'system.chat_history_*' TO user",
				],
			],
		];
	}

	/**
	 * @return array<int, string>
	 */
	private function getPermissionQueries(Payload $payload): array {
		$refClass = new ReflectionClass(Handler::class);
		$method = $refClass->getMethod('permissionQueries');
		$method->setAccessible(true);

		/** @var array<int, string> */
		return $method->invoke(null, $payload);
	}

	private function createRequest(string $query): Request {
		return Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => 'syntax error',
				'payload' => $query,
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => '',
			]
		);
	}

	private function createSuccessfulResponse(): Response&MockObject {
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);

		return $response;
	}

}
