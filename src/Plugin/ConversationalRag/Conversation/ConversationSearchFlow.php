<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation;

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LlmProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\SearchEngine;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class ConversationSearchFlow extends AbstractConversationFlow {
	/**
	 * @param array{
	 *   id:string,
	 *   uuid:string,
	 *   name:string,
	 *   model:string,
	 *   style_prompt:string,
	 *   settings:array<string, mixed>,
	 *   created_at:string,
	 *   updated_at:string
	 * } $model
	 * @return array{
	 *   array<int, array<string, mixed>>,
	 *   array{search_query:string, exclude_query:string},
	 *   array<int, string|int>,
	 *   string
	 * }
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function retrieve(
		ConversationRequest $request,
		ConversationHistory $history,
		array $model,
		LlmProvider $provider,
		SearchEngine $searchEngine
	): array {
		$route = $this->router->route($request->query, $history, $provider, $model);
		Buddy::debugv("RAG: ├─ Route selected: $route->route");
		[$searchResults, $queries, $excludedIds] = $this->search($request, $history, $model, $route, $searchEngine);

		return [$searchResults, $queries, $excludedIds, $route->route];
	}

	/**
	 * @param array{
	 *   id:string,
	 *   uuid:string,
	 *   name:string,
	 *   model:string,
	 *   style_prompt:string,
	 *   settings:array<string, mixed>,
	 *   created_at:string,
	 *   updated_at:string
	 * } $model
	 * @return array{
	 *   array<int, array<string, mixed>>,
	 *   array{search_query:string, exclude_query:string},
	 *   array<int, string|int>
	 * }
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function search(
		ConversationRequest $request,
		ConversationHistory $history,
		array $model,
		ConversationRoute $route,
		SearchEngine $searchEngine
	): array {
		if ($route->route === ConversationRoute::ANSWER_FROM_HISTORY) {
			return $this->reuseLatestSearchSources(
				$request,
				$history,
				$model,
				$searchEngine
			);
		}

		if ($route->route !== ConversationRoute::SEARCH) {
			return [[], ['search_query' => '', 'exclude_query' => ''], []];
		}

		return $this->handleSearchIntent($request, $history, $model, $route, $searchEngine);
	}

	/**
	 * @param array{
	 *   id:string,
	 *   uuid:string,
	 *   name:string,
	 *   model:string,
	 *   style_prompt:string,
	 *   settings:array<string, mixed>,
	 *   created_at:string,
	 *   updated_at:string
	 * } $model
	 * @return array{
	 *   array<int, array<string, mixed>>,
	 *   array{search_query:string, exclude_query:string},
	 *   array<int, string|int>
	 * }
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private function handleSearchIntent(
		ConversationRequest $request,
		ConversationHistory $history,
		array $model,
		ConversationRoute $route,
		SearchEngine $searchEngine
	): array {
		Buddy::debugv("RAG: ├─ Processing route: {$route->route}");
		Buddy::debugv('RAG: ├─ Using structured conversation history for query generation');
		$historyPayload = $history->payload();
		Buddy::debugv('RAG: ├─ Query history turns: ' . sizeof($historyPayload));

		$queries = [
			'search_query' => $route->searchQuery,
			'exclude_query' => $route->excludeQuery,
		];

		return $this->searchByQueries(
			$request,
			$model,
			$searchEngine,
			$queries
		);
	}
}
