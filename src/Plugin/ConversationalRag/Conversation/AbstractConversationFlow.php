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

abstract class AbstractConversationFlow {
	public function __construct(
		protected readonly ConversationSearchRouter|ConversationSearchWithResearchRouter $router
	) {
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
	abstract public function retrieve(
		ConversationRequest $request,
		ConversationHistory $history,
		array $model,
		LlmProvider $provider,
		SearchEngine $searchEngine
	): array;

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
	 * @param array{search_query:string, exclude_query:string} $queries
	 * @return array{
	 *   array<int, array<string, mixed>>,
	 *   array{search_query:string, exclude_query:string},
	 *   array<int, string|int>
	 * }
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	protected function searchByQueries(
		ConversationRequest $request,
		array $model,
		SearchEngine $searchEngine,
		array $queries
	): array {
		$excludedIds = $searchEngine->getExcludedIds($request->table, $queries['exclude_query']);
		$searchResults = $searchEngine->search(
			$request->table,
			$queries['search_query'],
			$excludedIds,
			$model,
			0.8,
			$request->fields
		);

		return [$searchResults, $queries, $excludedIds];
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
	protected function reuseLatestSearchSources(
		ConversationRequest $request,
		ConversationHistory $history,
		array $model,
		SearchEngine $searchEngine
	): array {
		$lastContext = $history->latestSearchContext();
		if (!$lastContext) {
			return [[], ['search_query' => '', 'exclude_query' => ''], []];
		}

		$queries = [
			'search_query' => $lastContext['search_query'],
			'exclude_query' => $lastContext['exclude_query'],
		];
		$excludedIds = $this->decodeStoredExcludedIds($lastContext['excluded_ids']);
		$searchResults = $searchEngine->search(
			$request->table,
			$queries['search_query'],
			$excludedIds,
			$model,
			0.8,
			$request->fields
		);

		return [$searchResults, $queries, $excludedIds];
	}

	/**
	 * @return array<int, string|int>
	 */
	private function decodeStoredExcludedIds(string $excludedIds): array {
		if ($excludedIds === '') {
			return [];
		}

		/** @var array<int, string|int> $decodedExcludedIds */
		$decodedExcludedIds = simdjson_decode($excludedIds, true);
		return $decodedExcludedIds;
	}
}
