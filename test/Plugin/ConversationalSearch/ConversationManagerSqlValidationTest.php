<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationManager;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ConversationManagerSqlValidationTest extends TestCase {
	public function testSaveMessageIncludesModelName(): void {
		$client = $this->createMock(HTTPClient::class);
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertMatchesRegularExpression(
					'/^INSERT INTO system\\.chat_history_test_model '
					. '\\(conversation_uuid, `model_name`, created_at, role, message, ttl\\) '
					. "VALUES \\('conv-123', 'test_model', [0-9]+, 'user', 'Hello, how are you\\?', [0-9]+\\)$/",
					$sql
				);
			},
			$this->successfulResponse()
		);
		$manager->saveMessage('conv-123', 'test_model', 'user', 'Hello, how are you?');
	}

	public function testSaveMessageEscapesSpecialCharacters(): void {
		$client = $this->createMock(HTTPClient::class);
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringStartsWith(
					'INSERT INTO system.chat_history_test_model '
					. '(conversation_uuid, `model_name`, created_at, role, message, ttl)',
					$sql
				);
				$this->assertStringContainsString("O\\'Reilly", $sql);
				$this->assertStringContainsString('line\\nbreak', $sql);
				$this->assertStringContainsString('quote\\"test', $sql);
				$this->assertStringNotContainsString('search_query', $sql);
			},
			$this->successfulResponse()
		);
		$manager->saveMessage(
			'conv-123',
			'test_model',
			'user',
			"O'Reilly's book has a line\nbreak and quote\"test"
		);
	}

	public function testGetConversationMessagesSelectsOnlyRoleAndMessage(): void {
		$client = $this->createMock(HTTPClient::class);
		$rows = [
			['role' => 'assistant', 'message' => 'Hi there!'],
			['role' => 'excluded_ids:shows', 'message' => '["42"]'],
			['role' => 'internal', 'message' => 'Not a conversation turn'],
			['role' => 'user', 'message' => 'Hello'],
		];
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(Struct::fromData([['data' => $rows]]));
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertSame(
					"SELECT role, message FROM system.chat_history_test_model WHERE conversation_uuid = 'conv-123' "
					. 'ORDER BY created_at DESC, id DESC LIMIT 100',
					$sql
				);
			},
			$response
		);
		$this->assertSame(
			"user: Hello\nassistant: Hi there!\n",
			$manager->getConversationMessages('conv-123')->format()
		);
	}

	public function testGetConversationMessagesUsesRequestedLimitAndRetainsLegacyRows(): void {
		$client = $this->createMock(HTTPClient::class);
		$rows = [
			['role' => 'assistant', 'message' => 'Here are movies.', 'tokens_used' => 42],
			['role' => 'user', 'message' => 'Show me movies', 'intent' => 'NEW', 'model_name' => 'old-model'],
		];
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(Struct::fromData([['data' => $rows]]));
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringStartsWith('SELECT role, message FROM system.chat_history_test_model', $sql);
				$this->assertStringContainsString('LIMIT 50', $sql);
				$this->assertStringNotContainsString('intent', $sql);
			},
			$response
		);
		$messages = $manager->getConversationMessages('conv-123', 50)->messages();
		$this->assertSame(['role' => 'user', 'message' => 'Show me movies'], $messages[0]);
		$this->assertSame(['role' => 'assistant', 'message' => 'Here are movies.'], $messages[1]);
	}

	public function testHistoryAndExclusionSnapshotShareOneBoundedQuery(): void {
		$client = $this->createMock(HTTPClient::class);
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$rows = [
			['role' => 'excluded_ids:films', 'message' => '["99"]'],
			['role' => 'excluded_ids:shows', 'message' => '["42"]'],
			['role' => 'assistant', 'message' => 'Try these shows.'],
			['role' => 'internal', 'message' => 'Not a conversation turn'],
			['role' => 'user', 'message' => 'Find shows'],
		];
		$response->method('getResult')->willReturn(Struct::fromData([['data' => $rows]]));
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertSame(
					"SELECT role, message FROM system.chat_history_test_model WHERE conversation_uuid = 'conv-123' "
					. 'ORDER BY created_at DESC, id DESC LIMIT 100',
					$sql
				);
			},
			$response
		);
		$history = $manager->getConversationMessages('conv-123', indexName: 'shows');
		$this->assertSame(['42'], $history->excludedIds());
		$this->assertSame("user: Find shows\nassistant: Try these shows.\n", $history->format());
	}

	public function testInitializeTableIncludesModelName(): void {
		$client = $this->createMock(HTTPClient::class);
		$client->expects($this->once())->method('sendRequest')->with(
			$this->callback(
				function (string $sql): bool {
					$this->assertMatchesRegularExpression(
						'/^CREATE TABLE IF NOT EXISTS system\\.chat_history_test_model\\s*'
						. '\\(\\s*conversation_uuid string,\\s*`model_name` string,\\s*'
						. 'created_at bigint,\\s*role string,\\s*'
						. 'message text,\\s*ttl bigint\\s*\\)$/',
						$sql
					);
					return true;
				}
			)
		)->willReturn($this->successfulResponse());
		new ConversationManager($client, 'test_model');
	}

	private function successfulResponse(): Response {
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		return $response;
	}

	private function managerWithRequest(
		HTTPClient&MockObject $client,
		callable $assertSql,
		Response $response
	): ConversationManager {
		$initResponse = $this->successfulResponse();
		$requestNumber = 0;
		$client->expects($this->exactly(2))->method('sendRequest')->willReturnCallback(
			function (string $sql) use (&$requestNumber, $assertSql, $initResponse, $response): Response {
				++$requestNumber;
				if ($requestNumber === 1) {
					$this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS system.chat_history_test_model', $sql);
					return $initResponse;
				}
				$assertSql($sql);
				return $response;
			}
		);
		return new ConversationManager($client, 'test_model');
	}
}
