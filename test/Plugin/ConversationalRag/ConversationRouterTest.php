<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationHistory;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationMessage;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationRoute;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ConversationRouter;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LlmProvider;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ConversationRouterTest extends TestCase {

	public function testRoutesSearchWithStandaloneQuestion(): void {
		$router = new ConversationRouter();
		$modelConfig = ['model' => 'openai:gpt-4'];

		/** @var MockObject&LlmProvider $provider */
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())
			->method('configure')
			->with($modelConfig);
		$provider->expects($this->once())
			->method('generateToolCall')
			->with(
				$this->callback(
					static fn (string $prompt): bool => str_contains($prompt, '    <Conversation history>')
						&& str_contains($prompt, '{"user":"tv shows?"}')
						&& str_contains($prompt, '    <Question>')
						&& str_contains($prompt, 'what is the cast in GoT?')
				),
				$this->callback(
					static fn (array $tool): bool => $tool['name'] === 'route_conversation'
						&& $tool['parameters']['properties']['route']['enum'] === [
							ConversationRoute::ANSWER_FROM_HISTORY,
							ConversationRoute::SEARCH,
							ConversationRoute::REJECT,
						]
						&& in_array('exclude_query', $tool['parameters']['required'], true)
				)
			)
			->willReturn(
				$this->toolResponse(
					ConversationRoute::SEARCH,
					'What is the cast of Game of Thrones?',
					'',
					'The cast is not present in history.'
				)
			);

		$route = $router->route(
			'what is the cast in GoT?',
			$this->history(),
			$provider,
			$modelConfig
		);

		$this->assertEquals(ConversationRoute::SEARCH, $route->route);
		$this->assertEquals('What is the cast of Game of Thrones?', $route->standaloneQuestion);
		$this->assertEquals('', $route->excludeQuery);
	}

	public function testRoutesExplicitExclusionSeparatelyFromSearchQuestion(): void {
		$router = new ConversationRouter();

		/** @var MockObject&LlmProvider $provider */
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')
			->willReturn(
				$this->toolResponse(
					ConversationRoute::SEARCH,
					'What are some good TV shows to watch?',
					'Breaking Bad',
					'The user explicitly said they already saw Breaking Bad.'
				)
			);

		$route = $router->route('I already saw breaking bad', $this->history(), $provider, ['model' => 'openai:gpt-4']);

		$this->assertEquals(ConversationRoute::SEARCH, $route->route);
		$this->assertEquals('What are some good TV shows to watch?', $route->standaloneQuestion);
		$this->assertEquals('Breaking Bad', $route->excludeQuery);
	}

	public function testRoutesAnswerFromHistory(): void {
		$router = new ConversationRouter();

		/** @var MockObject&LlmProvider $provider */
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')
			->willReturn(
				$this->toolResponse(
					ConversationRoute::ANSWER_FROM_HISTORY,
					'',
					'',
					'The answer is directly present in history.'
				)
			);

		$route = $router->route('which one is fantasy?', $this->history(), $provider, ['model' => 'openai:gpt-4']);

		$this->assertEquals(ConversationRoute::ANSWER_FROM_HISTORY, $route->route);
		$this->assertEquals('', $route->standaloneQuestion);
		$this->assertEquals('', $route->excludeQuery);
	}

	public function testRoutesExplicitReject(): void {
		$router = new ConversationRouter();

		/** @var MockObject&LlmProvider $provider */
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')
			->willReturn(
				$this->toolResponse(
					ConversationRoute::REJECT,
					'',
					'',
					'The user rejects the previous result without new criteria.'
				)
			);

		$route = $router->route('No, not these.', $this->history(), $provider, ['model' => 'openai:gpt-4']);

		$this->assertEquals(ConversationRoute::REJECT, $route->route);
		$this->assertEquals('', $route->standaloneQuestion);
		$this->assertEquals('', $route->excludeQuery);
	}

	public function testThrowsWhenSearchRouteHasEmptyStandaloneQuestion(): void {
		$router = new ConversationRouter();

		/** @var MockObject&LlmProvider $provider */
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')
			->willReturn($this->toolResponse(ConversationRoute::SEARCH, '', '', 'Missing question.'));

		$this->expectException(ManticoreSearchClientError::class);

		$router->route('cast?', $this->history(), $provider, ['model' => 'openai:gpt-4']);
	}

	public function testThrowsWhenToolCallHasInvalidShape(): void {
		$router = new ConversationRouter();

		/** @var MockObject&LlmProvider $provider */
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')
			->willReturn(
				[
					'success' => true,
					'content' => '',
					'tool_calls' => [[]],
					'metadata' => [
						'tokens_used' => 10,
						'input_tokens' => 8,
						'output_tokens' => 2,
						'response_time_ms' => 1,
						'finish_reason' => 'tool_calls',
					],
				]
			);

		$this->expectException(ManticoreSearchClientError::class);

		$router->route('What is RAG?', new ConversationHistory([]), $provider, ['model' => 'openai:gpt-4']);
	}

	public function testParsesExtensionToolCallArguments(): void {
		$router = new ConversationRouter();
		$toolCall = $this->createMock(ToolCall::class);
		$toolCall->method('getArguments')
			->willReturn(
				[
					'route' => ConversationRoute::SEARCH,
					'standalone_question' => 'What is RAG?',
					'exclude_query' => '',
					'reason' => 'The user asks for a new definition.',
				]
			);

		/** @var MockObject&LlmProvider $provider */
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')
			->willReturn(
				[
					'success' => true,
					'content' => '',
					'tool_calls' => [$toolCall],
					'metadata' => [
						'tokens_used' => 10,
						'input_tokens' => 8,
						'output_tokens' => 2,
						'response_time_ms' => 1,
						'finish_reason' => 'tool_calls',
					],
				]
			);

		$route = $router->route('What is RAG?', new ConversationHistory([]), $provider, ['model' => 'openai:gpt-4']);

		$this->assertEquals(ConversationRoute::SEARCH, $route->route);
		$this->assertEquals('What is RAG?', $route->standaloneQuestion);
	}

	public function testParsesRawJsonToolCallArguments(): void {
		$router = new ConversationRouter();
		$toolCall = $this->createMock(ToolCall::class);
		$toolCall->method('getArguments')
			->willReturn(
				json_encode(
					[
						'route' => ConversationRoute::SEARCH,
						'standalone_question' => 'What is RAG?',
						'exclude_query' => '',
						'reason' => 'The user asks for a new definition.',
					],
					JSON_THROW_ON_ERROR
				)
			);

		/** @var MockObject&LlmProvider $provider */
		$provider = $this->createMock(LlmProvider::class);
		$provider->method('generateToolCall')
			->willReturn(
				[
					'success' => true,
					'content' => '',
					'tool_calls' => [$toolCall],
					'metadata' => [
						'tokens_used' => 10,
						'input_tokens' => 8,
						'output_tokens' => 2,
						'response_time_ms' => 1,
						'finish_reason' => 'tool_calls',
					],
				]
			);

		$route = $router->route('What is RAG?', new ConversationHistory([]), $provider, ['model' => 'openai:gpt-4']);

		$this->assertEquals(ConversationRoute::SEARCH, $route->route);
		$this->assertEquals('What is RAG?', $route->standaloneQuestion);
	}

	private function history(): ConversationHistory {
		return new ConversationHistory(
			[
				ConversationMessage::user('tv shows?', ConversationRoute::SEARCH),
				ConversationMessage::assistant(
					"1. Breaking Bad: crime drama.\n2. Game of Thrones: fantasy drama.",
					ConversationRoute::SEARCH
				),
			]
		);
	}

	/**
	 * @return array{success:true, content:string, tool_calls:array<int, mixed>, metadata:array<string, int|string>}
	 */
	private function toolResponse(
		string $route,
		string $standaloneQuestion,
		string $excludeQuery,
		string $reason
	): array {
		$toolCall = $this->createMock(ToolCall::class);
		$toolCall->method('getArguments')
			->willReturn(
				json_encode(
					[
						'route' => $route,
						'standalone_question' => $standaloneQuestion,
						'exclude_query' => $excludeQuery,
						'reason' => $reason,
					],
					JSON_THROW_ON_ERROR
				)
			);

		return [
			'success' => true,
			'content' => '',
			'tool_calls' => [$toolCall],
			'metadata' => [
				'tokens_used' => 10,
				'input_tokens' => 8,
				'output_tokens' => 2,
				'response_time_ms' => 1,
				'finish_reason' => 'tool_calls',
			],
		];
	}
}
