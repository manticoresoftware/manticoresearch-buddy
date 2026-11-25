<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)
 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationHistory;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRequest;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRoute;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRouter;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Handler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\LlmProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\SearchContext;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\SearchEngine;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\VectorFieldInfo;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\TestCase;

final class ConversationSimplificationTest extends TestCase {
	public function testHistoryOnlyPersistsConversationMessagesAndMetadata(): void {
		$queries = [];
		$client = $this->createMock(Client::class);
		$client->method('sendRequest')->willReturnCallback(
			function (string $sql) use (&$queries): Response {
				$queries[] = $sql;
				$response = $this->createMock(Response::class);
				$response->method('hasError')->willReturn(false);
				$response->method('getResult')->willReturn(
					Struct::fromData(
						[['data' => [
							['role' => 'assistant', 'message' => 'Try this one.'],
							['role' => 'user', 'message' => 'Find a movie'],
						]]]
					)
				);
				return $response;
			}
		);
		$manager = new ConversationManager($client, 'assistant');
		$manager->saveTurn('conv', 'assistant', 'Find a movie', 'Try this one.', 'movies');
		$history = $manager->getConversationMessages('conv');
		$this->assertSame("user: Find a movie\nassistant: Try this one.\n", $history->format());
		$this->assertSame(3, sizeof($queries));
		$removedColumns = ['intent', 'search_query', 'exclude_query', 'excluded_ids', 'lexical_terms',
			'tokens_used'];
		foreach ($removedColumns as $column) {
			$this->assertStringNotContainsString($column, $queries[0]);
			$this->assertStringNotContainsString($column, $queries[1]);
			$this->assertStringNotContainsString($column, $queries[2]);
		}
		$this->assertStringContainsString('`model_name` string', $queries[0]);
		$this->assertStringContainsString("VALUES ('conv', 'assistant', ", $queries[1]);
		$this->assertStringContainsString("'user', 'Find a movie'", $queries[1]);
		$this->assertStringContainsString('SELECT role, message FROM system.chat_history_assistant', $queries[2]);
	}

	public function testRouterUsesHistoryWithoutReplayingOldSearch(): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn(string $prompt): bool => str_contains($prompt, 'Bryan Cranston plays Walter White.')
					&& str_contains($prompt, "I like Breaking Bad. Who's in the cast?")
					&& str_contains($prompt, 'Where is the actor who plays Walter White from?')
			),
			$this->callback(
				static fn(array $tool): bool => isset($tool['parameters']['properties']['route'])
					&& isset($tool['parameters']['properties']['standalone_question'])
			),
			$this->anything()
		)->willReturn($this->queryResponse());
		$plan = (new ConversationRouter())->route(
			'Where is the actor who plays Walter White from?',
			new ConversationHistory(
				[
					['role' => 'user', 'message' => "I like Breaking Bad. Who's in the cast?"],
					['role' => 'assistant', 'message' => 'Bryan Cranston plays Walter White.'],
				]
			),
			$provider,
			['model' => 'openai:test']
		);
		$this->assertSame(ConversationRoute::SEARCH, $plan->route);
		$this->assertSame('Where is Bryan Cranston from?', $plan->standaloneQuestion);
		$this->assertSame('', $plan->excludeQuery);
		$this->assertSame('Search current question', $plan->reason);
	}

	/** @dataProvider searchStrategies */
	public function testBarePreferenceSearchesForNamedItemAndSimilarShows(string $strategy): void {
		$provider = $this->createMock(LlmProvider::class);
		$provider->expects($this->once())->method('generateToolCall')->with(
			$this->callback(
				static fn(string $prompt): bool => str_contains($prompt, 'I like Breaking Bad.')
					&& str_contains($prompt, 'Suggest me a good TV show')
					&& str_contains($prompt, 'well-rated shows')
					&& str_contains($prompt, 'preference')
					&& str_contains($prompt, 'similar')
			),
			$this->callback(
				static fn(array $tool): bool => isset($tool['parameters']['properties']['route'])
					&& isset($tool['parameters']['properties']['exclude_query'])
			),
			$this->anything()
		)->willReturn($this->barePreferenceResponse($strategy));
		$plan = (new ConversationRouter())->route(
			'I like Breaking Bad.',
			new ConversationHistory(
				[
					['role' => 'user', 'message' => 'Suggest me a good TV show'],
					['role' => 'assistant', 'message' => 'Here are some well-rated shows.'],
				]
			),
			$provider, ['model' => 'openai:test'], $strategy
		);
		$this->assertSame(
			'Recommend well-rated TV shows similar to Breaking Bad, including Breaking Bad.',
			$plan->standaloneQuestion
		);
		$this->assertSame(
			$strategy === ConversationRequest::STRATEGY_HYBRID ? ['TV shows', 'Breaking Bad'] : [],
			$plan->lexicalTerms
		);
	}

	/** @dataProvider searchStrategies */
	public function testBarePreferenceSearchesAndReturnsCurrentSources(string $strategy): void {
		$engine = $this->createMock(SearchEngine::class);
		$question = 'Recommend well-rated TV shows similar to Breaking Bad, including Breaking Bad.';
		$terms = $strategy === ConversationRequest::STRATEGY_HYBRID ? ['TV shows', 'Breaking Bad'] : [];
		$model = ['id' => '1', 'name' => 'assistant', 'model' => 'openai:test',
			'settings' => [], 'created_at' => '', 'updated_at' => ''];
		$vectorField = new VectorFieldInfo('embedding', 'content', ['embedding']);
		$sources = [['id' => 1, 'content' => 'Breaking Bad']];
		$engine->expects($this->once())->method('search')->with(
			'shows', $question, [], $model, 0.8, $vectorField, $strategy, $terms
		)->willReturn($sources);
		$context = new SearchContext(
			new ConversationRequest('I like Breaking Bad.', 'shows', 'assistant', '', '', $strategy),
			$model,
			new ConversationRoute(ConversationRoute::SEARCH, $question, '', 'Search similar shows', $terms),
			$vectorField, []
		);
		$this->assertSame(
			[$sources, []],
			(new ReflectionMethod(Handler::class, 'performSearch'))->invoke(null, $context, $engine)
		);
	}

	/** @return array<string, array{string}> */
	public static function searchStrategies(): array {
		return ['knn' => [ConversationRequest::STRATEGY_KNN],
			'hybrid' => [ConversationRequest::STRATEGY_HYBRID]];
	}

	/** @return array<string, mixed> */
	private function queryResponse(): array {
		$toolCall = $this->createMock('ToolCall');
		$toolCall->method('getArguments')->willReturn(
			[
				'route' => ConversationRoute::SEARCH,
				'standalone_question' => 'Where is Bryan Cranston from?',
				'exclude_query' => '',
				'reason' => 'Search current question',
			]
		);
		return ['success' => true, 'tool_calls' => [$toolCall]];
	}

	/** @return array<string, mixed> */
	private function barePreferenceResponse(string $strategy): array {
		$toolCall = $this->createMock('ToolCall');
		$arguments = [
			'route' => ConversationRoute::SEARCH,
			'standalone_question' => 'Recommend well-rated TV shows similar to Breaking Bad, including Breaking Bad.',
			'exclude_query' => '',
			'reason' => 'Search similar shows',
		];
		if ($strategy === ConversationRequest::STRATEGY_HYBRID) {
			$arguments['lexical_terms'] = ['TV shows', 'Breaking Bad'];
		}
		$toolCall->method('getArguments')->willReturn($arguments);
		return ['success' => true, 'tool_calls' => [$toolCall]];
	}
}
