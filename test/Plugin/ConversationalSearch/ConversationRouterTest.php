<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationHistory;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationMessage;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRoute;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRouter;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\LlmProvider;
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
					static fn (string $prompt): bool => str_contains($prompt, '<Conversation history>')
						&& str_contains($prompt, '{"user":"tv shows?"}')
						&& str_contains($prompt, '<Question>')
						&& str_contains($prompt, '</Question>')
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
				),
				$this->equalTo(
					[
						'temperature' => 0.1,
						'max_tokens' => 1024,
					]
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

	public function testRetriesTransientToolCallFailure(): void {
		$router = new ConversationRouter();

		/** @var MockObject&LlmProvider $provider */
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(2))
			->method('generateToolCall')
			->willReturnOnConsecutiveCalls(
				[
					'success' => false,
					'error' => 'LLM tool call failed',
					'content' => '',
					'provider' => 'llm',
					'details' => 'missing field id at line 77 column 56',
				],
				$this->toolResponse(
					ConversationRoute::SEARCH,
					'What are denim jackets with buttons instead of zippers?',
					'',
					'The user asks for new searchable product criteria.'
				)
			);

		$route = $router->route(
			'Hello, I am looking for denim jackets that have buttons instead of a zipper . X a sipper',
			new ConversationHistory([]),
			$provider,
			['model' => 'openai:gpt-4']
		);

		$this->assertEquals(ConversationRoute::SEARCH, $route->route);
		$this->assertEquals('What are denim jackets with buttons instead of zippers?', $route->standaloneQuestion);
	}

	/**
	 * @dataProvider invalidRouteProvider
	 */
	public function testRetriesInvalidRoute(string $route, string $question, string $excludeQuery): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(2))
			->method('generateToolCall')
			->willReturnOnConsecutiveCalls(
				$this->toolResponse($route, $question, $excludeQuery, 'Invalid routing response.'),
				$this->toolResponse(ConversationRoute::SEARCH, 'What is clustering?', '', 'New question.')
			);

		$result = (new ConversationRouter())->route(
			'What is clustering?', $this->history(), $provider, ['model' => 'openai:gpt-4']
		);

		$this->assertSame(ConversationRoute::SEARCH, $result->route);
		$this->assertSame('What is clustering?', $result->standaloneQuestion);
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalidRouteProvider(): array {
		return [
			'history with question' => [ConversationRoute::ANSWER_FROM_HISTORY, 'What is clustering?', ''],
			'reject with question' => [ConversationRoute::REJECT, 'What is clustering?', ''],
			'history with exclusion' => [ConversationRoute::ANSWER_FROM_HISTORY, '', 'clustering'],
			'reject with exclusion' => [ConversationRoute::REJECT, '', 'clustering'],
			'empty search question' => [ConversationRoute::SEARCH, '', ''],
			'unknown route' => ['UNKNOWN', '', ''],
		];
	}

	public function testRecoversFromMalformedToolCallsToHistoryRoute(): void {
		$valid = $this->toolResponse(ConversationRoute::ANSWER_FROM_HISTORY, '', '', 'Answer is in history.');
		$empty = $valid;
		$empty['tool_calls'] = [];
		$toolCall = $this->createMock(ToolCall::class);
		$toolCall->method('getArguments')->willReturn('{');
		$malformed = $valid;
		$malformed['tool_calls'] = [$toolCall];

		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))
			->method('generateToolCall')
			->willReturnOnConsecutiveCalls($empty, $malformed, $valid);

		$result = (new ConversationRouter())->route(
			'Which one is fantasy?', $this->history(), $provider, ['model' => 'openai:gpt-4']
		);

		$this->assertSame(ConversationRoute::ANSWER_FROM_HISTORY, $result->route);
		$this->assertSame('', $result->standaloneQuestion);
		$this->assertSame('', $result->excludeQuery);
	}

	public function testThrowsLastValidationErrorAfterThreeAttempts(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))
			->method('generateToolCall')
			->willReturnOnConsecutiveCalls(
				$this->toolResponse(ConversationRoute::SEARCH, '', '', 'Missing question.'),
				$this->toolResponse(ConversationRoute::REJECT, '', 'clustering', 'Unexpected exclusion.'),
				$this->toolResponse(ConversationRoute::ANSWER_FROM_HISTORY, 'What is clustering?', '', 'Invalid.')
			);

		try {
			(new ConversationRouter())->route(
				'What is clustering?', $this->history(), $provider, ['model' => 'openai:gpt-4']
			);
			$this->fail('Expected routing to fail after three attempts.');
		} catch (ManticoreSearchClientError $error) {
			$this->assertSame(
				'Conversation routing returned unexpected search question',
				$error->getResponseError()
			);
		}
	}

	public function testProviderAndValidationFailuresShareRetryBudget(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->exactly(3))
			->method('generateToolCall')
			->willReturnOnConsecutiveCalls(
				['success' => false, 'error' => 'LLM tool call failed', 'content' => '', 'provider' => 'llm'],
				$this->toolResponse(ConversationRoute::SEARCH, '', '', 'Missing question.'),
				['success' => false, 'error' => 'LLM tool call failed', 'content' => '',
					'provider' => 'llm', 'details' => 'Provider unavailable']
			);

		try {
			(new ConversationRouter())->route(
				'What is clustering?', $this->history(), $provider, ['model' => 'openai:gpt-4']
			);
			$this->fail('Expected routing to fail after three attempts.');
		} catch (ManticoreSearchClientError $error) {
			$this->assertSame(
				'Conversation routing failed: LLM tool call failed: Provider unavailable',
				$error->getResponseError()
			);
		}
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

		$router->route('What is Chat?', new ConversationHistory([]), $provider, ['model' => 'openai:gpt-4']);
	}

	public function testParsesExtensionToolCallArguments(): void {
		$router = new ConversationRouter();
		$toolCall = $this->createMock(ToolCall::class);
		$toolCall->method('getArguments')
			->willReturn(
				[
					'route' => ConversationRoute::SEARCH,
					'standalone_question' => 'What is Chat?',
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

		$route = $router->route('What is Chat?', new ConversationHistory([]), $provider, ['model' => 'openai:gpt-4']);

		$this->assertEquals(ConversationRoute::SEARCH, $route->route);
		$this->assertEquals('What is Chat?', $route->standaloneQuestion);
	}

	public function testParsesRawJsonToolCallArguments(): void {
		$router = new ConversationRouter();
		$toolCall = $this->createMock(ToolCall::class);
		$toolCall->method('getArguments')
			->willReturn(
				json_encode(
					[
						'route' => ConversationRoute::SEARCH,
						'standalone_question' => 'What is Chat?',
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

		$route = $router->route('What is Chat?', new ConversationHistory([]), $provider, ['model' => 'openai:gpt-4']);

		$this->assertEquals(ConversationRoute::SEARCH, $route->route);
		$this->assertEquals('What is Chat?', $route->standaloneQuestion);
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
