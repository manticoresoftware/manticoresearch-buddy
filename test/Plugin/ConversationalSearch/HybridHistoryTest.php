<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)
 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationManager;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\TestCase;

final class HybridHistoryTest extends TestCase {
	public function testNewHistoryTableOnlyDefinesMessageColumns(): void {
		$queries = [];
		$client = $this->clientWithRows([], $queries);
		new ConversationManager($client, 'assistant');
		$this->assertCount(1, $queries);
		$this->assertStringStartsWith('CREATE TABLE IF NOT EXISTS system.chat_history_assistant', $queries[0]);
		$this->assertStringContainsString('role string', $queries[0]);
		$this->assertStringContainsString('message text', $queries[0]);
		$this->assertStringNotContainsString('lexical_terms', $queries[0]);
		$this->assertStringNotContainsString('search_query', $queries[0]);
	}

	public function testLegacyHistoryColumnsRemainUntouchedAndAreIgnored(): void {
		$queries = [];
		$client = $this->clientWithRows(
			[[
				'role' => 'assistant', 'message' => 'Here are some pants.', 'intent' => 'SEARCH',
				'search_query' => '', 'lexical_terms' => '',
			], [
				'role' => 'user', 'message' => 'Find Flex Pants', 'intent' => 'SEARCH',
				'search_query' => 'Old search question', 'exclude_query' => 'old exclusion',
				'excluded_ids' => '[7]', 'lexical_terms' => '["Flex Pants","activewear"]',
			]],
			$queries
		);
		$manager = new ConversationManager($client, 'assistant');
		$manager->saveMessage('conv', 'assistant', 'user', 'Which are black?');
		$history = $manager->getConversationMessages('conv');

		$this->assertCount(3, $queries);
		$this->assertStringStartsWith('INSERT INTO system.chat_history_assistant', $queries[1]);
		$this->assertStringContainsString(
			'(conversation_uuid, `model_name`, created_at, role, message, ttl)', $queries[1]
		);
		$this->assertStringContainsString('SELECT role, message FROM system.chat_history_assistant', $queries[2]);
		$this->assertSame(
			'user: Find Flex Pants' . "\n" . 'assistant: Here are some pants.' . "\n",
			$history->format()
		);
		$this->assertSame(
			['user' => 'Find Flex Pants', 'assistant' => 'Here are some pants.'],
			array_values($history->payload())[0]
		);
		foreach ($queries as $sql) {
			$this->assertStringNotContainsString('ALTER TABLE', $sql);
			$this->assertStringNotContainsString('DROP TABLE', $sql);
		}
	}

	/**
	 * @param list<array<string, string>> $rows
	 * @param list<string> $queries
	 */
	private function clientWithRows(array $rows, array &$queries): Client {
		$client = $this->createMock(Client::class);
		$client->method('sendRequest')->willReturnCallback(
			function (string $sql) use ($rows, &$queries): Response {
				$queries[] = $sql;
				$response = $this->createMock(Response::class);
				$response->method('hasError')->willReturn(false);
				$response->method('getResult')->willReturn(Struct::fromData([['data' => $rows]]));
				return $response;
			}
		);
		return $client;
	}
}
