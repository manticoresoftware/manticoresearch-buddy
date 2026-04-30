<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation;

use JsonException;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Handler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LlmProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Tool\LlmToolCallArgumentsReader;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class ConversationSearchWithResearchRouter {
	private const string TOOL_NAME = 'route_conversation_strategy';

	/**
	 * @param array<string, mixed> $modelConfig
	 * @throws ManticoreSearchClientError
	 */
	public function route(
		string $userQuery,
		ConversationHistory $conversationHistory,
		LlmProvider $llmProvider,
		array $modelConfig
	): ConversationRoute {
		$llmProvider->configure($modelConfig);
		$response = $llmProvider->generateToolCall(
			$this->buildPrompt($userQuery, $conversationHistory->payload()),
			$this->toolDefinition(),
			[
				'temperature' => Handler::RESPONSE_TEMPERATURE,
				'max_tokens' => 320,
			]
		);

		if (!$response['success']) {
			throw ManticoreSearchClientError::create(
				LlmProvider::formatFailureMessage('Conversation strategy routing failed', $response)
			);
		}

		$decision = $this->parseDecision($response['tool_calls']);
		Buddy::debugv("\nRAG: [DEBUG CONVERSATION ROUTE]");
		Buddy::debugv("RAG: ├─ Strategy: $decision->route");
		Buddy::debugv("RAG: ├─ Search query: '$decision->searchQuery'");
		Buddy::debugv("RAG: ├─ Derive task: '$decision->deriveTask'");
		Buddy::debugv("RAG: └─ Reason: $decision->reason");

		return $decision;
	}

	/**
	 * @param array<string, array{user?: string, assistant?: string}> $historyPayload
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function buildPrompt(string $userQuery, array $historyPayload): string {
		return 'You are a RAG routing classifier. Decide if the user query can be used directly'
			. " for vector search, or if it must first be transformed into concrete searchable terms.\n\n"
			. "Apply rules in order:\n"
			. '1. ' . ConversationRoute::ANSWER_FROM_HISTORY . ': The current query is answered completely '
			. "by a previous assistant message in the conversation history.\n"
			. '2. ' . ConversationRoute::DERIVE_THEN_SEARCH . ': The query refers to items by describing '
			. 'a property, condition, relationship, or membership, without providing the specific names '
			. "of those items.\n"
			. 'Use this only when external/domain knowledge is needed to convert the description into '
			. "specific searchable values before retrieval.\n"
			. 'Detection: Extract the target items from the query. If the target is expressed as a noun '
			. 'modified by a descriptive clause, and that descriptive clause is not itself a name, model, '
			. "or SKU, then derivation is required.\n"
			. 'Action: Set derive_task to an instruction that converts the description into a list of '
			. "specific names, models, or technical identifiers that can be searched.\n"
			. '3. ' . ConversationRoute::DIRECT_SEARCH . ': The query provides the specific names, models, '
			. 'SKUs, or identifiers of the items to be retrieved, or it provides a direct comparison '
			. "between two or more such named items.\n"
			. 'Use this also for broad subjective recommendations or preference refinements where no '
			. "factual mapping is required before retrieval.\n"
			. 'Detection: The target items are named explicitly in the query. Any additional words indicate '
			. "an operation to be performed on the retrieved items, not a filter before retrieval.\n"
			. 'Action: Set search_query to the named items and any essential attributes, cleaned of '
			. "conversational filler.\n"
			. '4. ' . ConversationRoute::REJECT . ": The query is outside the system's scope.\n\n"
			. "Critical Rules:\n"
			. '- Base the decision only on the semantic structure of the user_query. '
			. "Do not perform keyword matching in any language.\n"
			. "- You have no knowledge of the database contents.\n"
			. '- Analyze meaning, not words. The same semantic structure in any language must yield '
			. "the same strategy.\n"
			. '- If after semantic analysis you cannot determine whether the target is named or described, '
			. 'choose ' . ConversationRoute::DERIVE_THEN_SEARCH . ".\n"
			. "- derive_task must always be in English.\n"
			. "<user_query>$userQuery</user_query>\n"
			. "<conversation_history>\n"
			. $this->encodeJson($historyPayload, 'Conversation strategy routing prompt encoding failed')
			. "\n</conversation_history>";
	}

	/**
	 * @return array{name:string, description:string, parameters:array<string, mixed>}
	 */
	private function toolDefinition(): array {
		return [
			'name' => self::TOOL_NAME,
			'description' => 'Choose the single retrieval strategy for one user message.',
			'parameters' => [
				'type' => 'object',
				'properties' => [
					'strategy' => [
						'type' => 'string',
						'enum' => [
							ConversationRoute::DIRECT_SEARCH,
							ConversationRoute::DERIVE_THEN_SEARCH,
							ConversationRoute::ANSWER_FROM_HISTORY,
							ConversationRoute::REJECT,
						],
					],
					'reason' => ['type' => 'string'],
					'search_query' => [
						'type' => 'string',
						'description' => 'Required for DIRECT_SEARCH, empty otherwise.',
					],
					'derive_task' => [
						'type' => 'string',
						'description' => 'Required for DERIVE_THEN_SEARCH, empty otherwise.',
					],
				],
				'required' => ['strategy', 'reason', 'search_query', 'derive_task'],
			],
		];
	}

	/**
	 * @param array<int, mixed> $toolCalls
	 * @throws ManticoreSearchClientError
	 */
	private function parseDecision(array $toolCalls): ConversationRoute {
		$decoded = (new LlmToolCallArgumentsReader())->read($toolCalls, 'Conversation strategy routing');
		if (!is_string($decoded['strategy'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation strategy routing returned invalid strategy');
		}
		if (!is_string($decoded['reason'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation strategy routing returned invalid reason');
		}
		if (!is_string($decoded['search_query'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation strategy routing returned invalid search query');
		}
		if (!is_string($decoded['derive_task'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation strategy routing returned invalid derive task');
		}

		$strategy = $decoded['strategy'];
		$reason = trim($decoded['reason']);
		$searchQuery = trim($decoded['search_query']);
		$deriveTask = trim($decoded['derive_task']);
		$this->validateDecision($strategy, $searchQuery, $deriveTask);

		return new ConversationRoute($strategy, $searchQuery, '', $reason, $deriveTask);
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private function validateDecision(string $strategy, string $searchQuery, string $deriveTask): void {
		$validStrategies = [
			ConversationRoute::DIRECT_SEARCH,
			ConversationRoute::DERIVE_THEN_SEARCH,
			ConversationRoute::ANSWER_FROM_HISTORY,
			ConversationRoute::REJECT,
		];
		if (!in_array($strategy, $validStrategies, true)) {
			throw ManticoreSearchClientError::create(
				"Conversation strategy routing returned unknown strategy: $strategy"
			);
		}
		if ($strategy === ConversationRoute::DIRECT_SEARCH && $searchQuery === '') {
			throw ManticoreSearchClientError::create('Conversation strategy routing returned empty search query');
		}
		if ($strategy === ConversationRoute::DERIVE_THEN_SEARCH && $deriveTask === '') {
			throw ManticoreSearchClientError::create('Conversation strategy routing returned empty derive task');
		}
		if ($strategy !== ConversationRoute::DIRECT_SEARCH && $searchQuery !== '') {
			throw ManticoreSearchClientError::create('Conversation strategy routing returned unexpected search query');
		}
		if ($strategy !== ConversationRoute::DERIVE_THEN_SEARCH && $deriveTask !== '') {
			throw ManticoreSearchClientError::create('Conversation strategy routing returned unexpected derive task');
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws ManticoreSearchClientError
	 */
	private function encodeJson(array $data, string $errorPrefix): string {
		try {
			return json_encode($data, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw ManticoreSearchClientError::create($errorPrefix . ': ' . $e->getMessage());
		}
	}
}
