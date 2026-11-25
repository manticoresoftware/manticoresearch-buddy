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

class ConversationManagerTest extends TestCase {
	public static function setUpBeforeClass(): void {
		if (getenv('SEARCHD_CONFIG')) {
			return;
		}
		if (!is_dir('/etc/manticore')) {
			mkdir('/etc/manticore', 0755, true);
		}
		touch('/etc/manticore/manticore.conf');
		putenv('SEARCHD_CONFIG=/etc/manticore/manticore.conf');
	}

	public function testInitializeTablePreservesModelNameColumn(): void {
		$client = $this->createMock(HTTPClient::class);
		$client->expects($this->once())->method('sendRequest')->with(
			$this->callback(
				function (string $sql): bool {
					$this->assertSame(
						'CREATE TABLE IF NOT EXISTS system.chat_history_test_model (' . "\n"
						. "			conversation_uuid string,\n			`model_name` string,\n"
						. "			created_at bigint,\n			role string,\n"
						. "			message text,\n			ttl bigint\n		)",
						$sql
					);
					return true;
				}
			)
		)->willReturn($this->successfulResponse());
		new ConversationManager($client, 'test_model');
	}

	public function testSaveTurnPersistsSelectedModelName(): void {
		$client = $this->createMock(HTTPClient::class);
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringStartsWith(
					'INSERT INTO system.chat_history_test_model '
					. '(conversation_uuid, `model_name`, created_at, role, message, ttl) ',
					$sql
				);
				$this->assertMatchesRegularExpression(
					"/VALUES \\('conv-123', 'different-model', [0-9]+, 'user', 'Hello, how are you\\?', [0-9]+\\), "
					. "\\('conv-123', 'different-model', [0-9]+, 'assistant', 'I am well.', [0-9]+\\)$/",
					$sql
				);
			},
			$this->successfulResponse()
		);
		$manager->saveTurn('conv-123', 'different-model', 'Hello, how are you?', 'I am well.', 'docs');
	}

	public function testSaveTurnPersistsAssistantRoleAndMessage(): void {
		$client = $this->createMock(HTTPClient::class);
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringContainsString("'assistant', 'Here is the answer.'", $sql);
				$this->assertStringNotContainsString('excluded_ids', $sql);
			},
			$this->successfulResponse()
		);
		$manager->saveTurn('conv-123', 'test_model', 'What is the answer?', 'Here is the answer.', 'docs');
	}

	public function testSaveTurnPersistsMessagesAndExclusionsInOneInsert(): void {
		$client = $this->createMock(HTTPClient::class);
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringStartsWith('INSERT INTO system.chat_history_test_model ', $sql);
				$this->assertSame(3, substr_count($sql, "'conv-123', 'different-model'"));
				$this->assertStringContainsString("'user', 'Show me shows'", $sql);
				$this->assertStringContainsString("'assistant', 'Here are shows'", $sql);
				$this->assertStringContainsString("'excluded_ids:shows'", $sql);
				$this->assertStringContainsString('[\\"12\\",\\"9007199254740993\\"]', $sql);
			},
			$this->successfulResponse()
		);
		$manager->saveTurn(
			'conv-123', 'different-model', 'Show me shows', 'Here are shows', 'shows', ['12', '9007199254740993']
		);
	}

	public function testSaveTurnWithoutNewExclusionsPersistsOnlyMessages(): void {
		$client = $this->createMock(HTTPClient::class);
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertSame(2, substr_count($sql, "'conv-123', 'test_model'"));
				$this->assertStringContainsString("'user', 'Thanks'", $sql);
				$this->assertStringContainsString("'assistant', 'You are welcome'", $sql);
				$this->assertStringNotContainsString('excluded_ids:', $sql);
			},
			$this->successfulResponse()
		);
		$manager->saveTurn('conv-123', 'test_model', 'Thanks', 'You are welcome', 'shows');
	}

	public function testGetConversationHistoryOrdered(): void {
		$client = $this->createMock(HTTPClient::class);
		$rows = [
			['role' => 'assistant', 'message' => 'I am doing well, thank you!'],
			['role' => 'user', 'message' => 'How are you?'],
			['role' => 'assistant', 'message' => 'Hi there!'],
			['role' => 'user', 'message' => 'Hello'],
		];
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringStartsWith('SELECT role, message FROM system.chat_history_test_model', $sql);
				$this->assertStringContainsString('ORDER BY created_at DESC, id DESC LIMIT 100', $sql);
			},
			$this->rowsResponse($rows)
		);
		$history = $manager->getConversationMessages('conv-123');
		$this->assertSame(
			"user: Hello\nassistant: Hi there!\nuser: How are you?\n"
			. "assistant: I am doing well, thank you!\n",
			$history->format()
		);
		$this->assertSame(['role' => 'user', 'message' => 'Hello'], $history->messages()[0]);
	}

	public function testGetConversationHistoryEmpty(): void {
		$client = $this->createMock(HTTPClient::class);
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringStartsWith('SELECT role, message FROM system.chat_history_test_model', $sql);
			},
			$this->rowsResponse([])
		);
		$this->assertSame([], $manager->getConversationMessages('conv-123')->messages());
	}

	public function testGetConversationHistoryWithLimit(): void {
		$client = $this->createMock(HTTPClient::class);
		$rows = [
			['role' => 'user', 'message' => 'Second message'],
			['role' => 'assistant', 'message' => 'First response'],
			['role' => 'user', 'message' => 'First message'],
		];
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringContainsString('LIMIT 5', $sql);
			},
			$this->rowsResponse($rows)
		);
		$this->assertSame(
			"user: First message\nassistant: First response\nuser: Second message\n",
			$manager->getConversationMessages('conv-123', 5)->format()
		);
	}

	public function testGetConversationHistoryReadsLegacyRowsWithExtraColumns(): void {
		$client = $this->createMock(HTTPClient::class);
		$rows = [
			['role' => 'assistant', 'message' => 'Here are movies.', 'model_name' => 'old-model'],
			['role' => 'user', 'message' => 'Show me movies', 'intent' => 'NEW',
				'search_query' => 'movies about space', 'excluded_ids' => '[1,2,3]'],
		];
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringStartsWith('SELECT role, message FROM system.chat_history_test_model', $sql);
			},
			$this->rowsResponse($rows)
		);
		$messages = $manager->getConversationMessages('conv-123')->messages();
		$this->assertCount(2, $messages);
		$this->assertSame(['role' => 'user', 'message' => 'Show me movies'], $messages[0]);
		$this->assertSame(['role' => 'assistant', 'message' => 'Here are movies.'], $messages[1]);
	}

	public function testGetConversationHistoryKeepsFollowUps(): void {
		$client = $this->createMock(HTTPClient::class);
		$rows = [
			['role' => 'assistant', 'message' => 'It was directed by someone.', 'intent' => 'FOLLOW_UP'],
			['role' => 'user', 'message' => 'Who directed it?', 'intent' => 'FOLLOW_UP'],
			['role' => 'assistant', 'message' => 'Here are some movies'],
			['role' => 'user', 'message' => 'Show me movies', 'intent' => 'NEW'],
		];
		$manager = $this->managerWithRequest(
			$client,
			function (string $sql): void {
				$this->assertStringNotContainsString('intent', $sql);
			},
			$this->rowsResponse($rows)
		);
		$this->assertSame(
			"user: Show me movies\nassistant: Here are some movies\n"
			. "user: Who directed it?\nassistant: It was directed by someone.\n",
			$manager->getConversationMessages('conv-123')->format()
		);
	}

	/** @param array<int, array<string, string>> $rows */
	private function rowsResponse(array $rows): Response {
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(Struct::fromData([['data' => $rows]]));
		return $response;
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
