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

final class ConversationSearchWithResearchFlow extends AbstractConversationFlow {
	public function __construct(
		ConversationSearchWithResearchRouter $router,
		private readonly ConversationResearchAssistant $researchAssistant
	) {
		parent::__construct($router);
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
		$route = $this->router->route(
			$request->query,
			$history,
			$provider,
			$model
		);

		if ($route->route === ConversationRoute::ANSWER_FROM_HISTORY) {
			[$searchResults, $queries, $excludedIds] = $this->reuseLatestSearchSources(
				$request,
				$history,
				$model,
				$searchEngine
			);
			$this->logStop('ANSWER_FROM_HISTORY');
			return [$searchResults, $queries, $excludedIds, ConversationRoute::ANSWER_FROM_HISTORY];
		}

		if ($route->route === ConversationRoute::REJECT) {
			$this->logStop('REJECT');
			return [[], ['search_query' => '', 'exclude_query' => ''], [], ConversationRoute::REJECT];
		}

		[$searchResults, $queries, $excludedIds] = $this->executeSearch(
			$route,
			$request,
			$history,
			$model,
			$provider,
			$searchEngine
		);

		$this->logStop('ANSWER');
		return [$searchResults, $queries, $excludedIds, ConversationRoute::SEARCH];
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
	private function executeSearch(
		ConversationRoute $route,
		ConversationRequest $request,
		ConversationHistory $history,
		array $model,
		LlmProvider $provider,
		SearchEngine $searchEngine
	): array {
		if ($route->route === ConversationRoute::DERIVE_THEN_SEARCH) {
			$research = $this->researchAssistant->research(
				$request->query,
				$route->deriveTask,
				$history,
				$provider,
				$model
			);
			$queries = [
				'search_query' => $this->buildResearchSearchQuery($research),
				'exclude_query' => $research['exclude_query'],
			];
		} else {
			$queries = [
				'search_query' => $route->searchQuery,
				'exclude_query' => '',
			];
		}

		[$searchResults, $queries, $excludedIds] = $this->searchByQueries(
			$request,
			$model,
			$searchEngine,
			$queries
		);
		Buddy::debugv('RAG: ├─ Retrieval results: ' . sizeof($searchResults));

		return [$searchResults, $queries, $excludedIds];
	}

	/**
	 * @param array{
	 *   constraints:array<int, string>,
	 *   keywords:array<int, string>,
	 *   expanded_query:string,
	 *   exclude_query:string,
	 *   confidence:float,
	 *   reason:string
	 * } $research
	 */
	private function buildResearchSearchQuery(array $research): string {
		return $research['expanded_query'];
	}

	private function logStop(string $reason): void {
		Buddy::debugv("RAG: └─ Search with research stop reason: $reason");
	}
}
