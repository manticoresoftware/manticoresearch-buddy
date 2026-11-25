<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)
 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRoute;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Handler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\LlmProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

final class ConversationSmallTalkTest extends TestCase {
	/** @dataProvider socialMessages */
	public function testPureSocialTurnDoesNotSearchAndStoresConversation(string $message, string $strategy): void {
		$provider = $this->createMock(LlmProvider::class);
		$toolCall = $this->createMock('ToolCall');
		$toolCall->method('getArguments')->willReturn(
			json_encode(
				['route' => ConversationRoute::CHAT, 'standalone_question' => '', 'exclude_query' => '',
					'reason' => 'Social message', 'lexical_terms' => []],
				JSON_THROW_ON_ERROR
			)
		);
		$provider->expects($this->once())->method('generateToolCall')->willReturn(
			[
			'success' => true, 'content' => '', 'tool_calls' => [$toolCall],
			'metadata' => ['tokens_used' => 1, 'input_tokens' => 1, 'output_tokens' => 1,
				'response_time_ms' => 1, 'finish_reason' => 'tool_calls'],
			]
		);
		$provider->expects($this->once())->method('generateResponse')->with(
			$this->callback(
				static fn(string $prompt): bool => str_contains($prompt, $message)
					&& str_contains($prompt, 'unrelated to indexed data')
					&& !str_contains($prompt, '<context>')
			),
			$this->callback(static fn(array $options): bool => $options['max_tokens'] === 128)
		)->willReturn(
			[
			'success' => true, 'content' => 'Hello!',
			'metadata' => ['tokens_used' => 2, 'input_tokens' => 1, 'output_tokens' => 1,
				'response_time_ms' => 1, 'finish_reason' => 'stop'],
			]
		);
		$sql = [];
		$handler = $this->handler($message, $strategy, $provider, $sql);
		$task = $handler->run();
		$this->assertTrue($task->isSucceed(), $task->isSucceed() ? '' : $task->getError()->getResponseError());
		$result = $task->getResult();
		$this->assertInstanceOf(TaskResult::class, $result);
		/** @var array<int, array{data: array<int, array{search_query:string, sources:string, response:string}>}> $rows */
		$rows = (array)$result->getStruct();
		$this->assertSame('', $rows[0]['data'][0]['search_query']);
		$this->assertSame('[]', $rows[0]['data'][0]['sources']);
		$this->assertSame('Hello!', $rows[0]['data'][0]['response']);
		$this->assertCount(4, $sql);
		$this->assertStringContainsString('`model_name` string', $sql[1]);
		$this->assertStringContainsString("VALUES ('conv-uuid', 'test_model', ", $sql[3]);
		$this->assertStringContainsString("'user'", $sql[3]);
		$this->assertStringContainsString("'assistant'", $sql[3]);
		$this->assertStringNotContainsString('excluded_ids:', $sql[3]);
	}

	public function testFailedSocialResponseDoesNotPersistConversation(): void {
		$provider = $this->createMock(LlmProvider::class);
		$toolCall = $this->createMock('ToolCall');
		$toolCall->method('getArguments')->willReturn(
			['route' => ConversationRoute::CHAT, 'standalone_question' => '', 'exclude_query' => '',
				'reason' => 'Social message']
		);
		$provider->expects($this->once())->method('generateToolCall')->willReturn(
			[
			'success' => true, 'content' => '', 'tool_calls' => [$toolCall],
			'metadata' => ['tokens_used' => 1, 'input_tokens' => 1, 'output_tokens' => 1,
				'response_time_ms' => 1, 'finish_reason' => 'tool_calls'],
			]
		);
		$provider->expects($this->once())->method('generateResponse')->willReturn(
			[
			'success' => false, 'error' => 'service unavailable', 'content' => '', 'provider' => 'llm',
			]
		);
		$sql = [];
		$task = $this->handler('Thanks', 'knn', $provider, $sql)->run();
		$this->assertTrue($task->isSucceed());
		$this->assertSame(
			['error' => 'LLM response generation failed: service unavailable'],
			$task->getResult()->getStruct()
		);
		$this->assertCount(3, $sql);
	}

	public function testSocialResponseUsesCustomPromptAndConversationHistory(): void {
		$provider = $this->createMock(LlmProvider::class);
		$toolCall = $this->createMock('ToolCall');
		$toolCall->method('getArguments')->willReturn(
			['route' => ConversationRoute::CHAT, 'standalone_question' => '', 'exclude_query' => '',
				'reason' => 'Acknowledgement']
		);
		$provider->method('generateToolCall')->willReturn(
			[
				'success' => true, 'content' => '', 'tool_calls' => [$toolCall],
				'metadata' => ['tokens_used' => 1, 'input_tokens' => 1, 'output_tokens' => 1,
					'response_time_ms' => 1, 'finish_reason' => 'tool_calls'],
			]
		);
		$provider->expects($this->once())->method('generateResponse')->with(
			$this->callback(
				static fn(string $prompt): bool => str_contains($prompt, 'Reply as a movie curator.')
					&& str_contains($prompt, 'Recommend movies')
					&& str_contains($prompt, 'Here are some movies.')
					&& str_contains($prompt, '<message>Thanks for that recommendation</message>')
					&& !str_contains($prompt, '<context>')
			),
			$this->anything()
		)->willReturn(
			[
				'success' => true, 'content' => 'You are welcome!',
				'metadata' => ['tokens_used' => 2, 'input_tokens' => 1, 'output_tokens' => 1,
					'response_time_ms' => 1, 'finish_reason' => 'stop'],
			]
		);
		$sql = [];
		$task = $this->handler('Thanks for that recommendation', 'knn', $provider, $sql, 'Reply as a movie curator.')
			->run();
		$this->assertTrue($task->isSucceed(), $task->isSucceed() ? '' : $task->getError()->getResponseError());
		$this->assertCount(4, $sql);
	}

	/** @return array<string, array{string, string}> */
	public static function socialMessages(): array {
		return [
			'greeting-knn' => ['Hello!', 'knn'],
			'farewell-knn' => ['Bye', 'knn'],
			'gratitude-hybrid' => ['Thanks', 'hybrid'],
			'social-question-hybrid' => ['How are you?', 'hybrid'],
		];
	}

	/** @param list<string> $sql */
	private function handler(
		string $message,
		string $strategy,
		LlmProvider $provider,
		array &$sql,
		string $customPrompt = ''
	): Handler {
		$strategyArg = $strategy === 'hybrid' ? ", '', 'hybrid'" : '';
		$query = "CALL CHAT('$message', 'movies', 'test_model', 'conv-uuid'{$strategyArg})";
		$request = Request::fromArray(
			[
			'version' => Buddy::PROTOCOL_VERSION, 'error' => '', 'payload' => $query,
			'format' => RequestFormat::SQL, 'endpointBundle' => Endpoint::Sql, 'path' => '',
			]
		);
		$client = $this->createMock(Client::class);
		$client->method('hasTable')->with('movies')->willReturn(true);
		$client->method('sendRequest')->willReturnCallback(
			function (string $query) use (&$sql, $customPrompt): Response {
				$sql[] = $query;
				if (str_starts_with($query, 'SELECT * FROM system.chat_model_test_model')) {
					return $this->response(
						[['data' => [[
						'id' => '1', 'name' => 'test_model', 'model' => 'openrouter:test',
						'settings' => (string)json_encode(
							['max_document_length' => 2000, 'custom_prompt' => $customPrompt], JSON_THROW_ON_ERROR
						),
						'created_at' => '', 'updated_at' => '',
						]]]]
					);
				}
				if (str_starts_with($query, 'CREATE TABLE IF NOT EXISTS system.chat_history_test_model')
					|| str_starts_with($query, 'INSERT INTO system.chat_history_test_model')) {
					return $this->response([]);
				}
				if (str_starts_with($query, 'SELECT role, message FROM system.chat_history_test_model')) {
					return $this->response(
						[['data' => [
						['role' => 'excluded_ids:movies', 'message' => 'Not valid JSON'],
						['role' => 'assistant', 'message' => 'Here are some movies.'],
						['role' => 'user', 'message' => 'Recommend movies'],
						]]]
					);
				}
				$this->fail('Unexpected search or exclusion SQL: ' . $query);
			}
		);
		$handler = new Handler(Payload::fromRequest($request), $provider);
		$handler->setManticoreClient($client);
		return $handler;
	}

	/** @param array<int, mixed> $result */
	private function response(array $result): Response {
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(Struct::fromData($result));
		return $response;
	}
}
