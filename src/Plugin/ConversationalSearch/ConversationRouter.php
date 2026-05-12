<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

use JsonException;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Throwable;

final class ConversationRouter {

	private const string TOOL_NAME = 'route_conversation';

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
				'max_tokens' => 256,
			]
		);

		if (!$response['success']) {
			throw ManticoreSearchClientError::create(
				LlmProvider::formatFailureMessage('Conversation routing failed', $response)
			);
		}

		$route = $this->parseRoute($response['tool_calls']);
		Buddy::debugv("\nChat: [DEBUG CONVERSATION ROUTE]");
		Buddy::debugv("Chat: ├─ Route: $route->route");
		Buddy::debugv("Chat: ├─ Standalone question: '$route->standaloneQuestion'");
		Buddy::debugv("Chat: ├─ Exclude query: '$route->excludeQuery'");
		Buddy::debugv("Chat: └─ Reason: $route->reason");

		return $route;
	}

	/**
	 * @param array<string, array{user?: string, assistant?: string}> $historyPayload
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function buildPrompt(string $userQuery, array $historyPayload): string {
		return 'You route a user message in a retrieval-augmented conversation.' . "\n\n"
			. "Routes:\n"
			. '- ' . ConversationRoute::ANSWER_FROM_HISTORY . ': The user asks something that can be answered '
			. "explicitly from the conversation history alone.\n"
			. '- ' . ConversationRoute::SEARCH . ': The user asks for new information, corrects the previous '
			. "query, clarifies meaning, adds constraints, changes topic, or questions applicability.\n"
			. '- ' . ConversationRoute::REJECT . ': The user explicitly rejects the previous assistant answer '
			. 'or retrieved results and provides no concrete correction, clarification, new topic, or searchable '
			. "criteria.\n\n"
			. "Rules:\n"
			. '- Use ' . ConversationRoute::REJECT . ' only for explicit rejection without useful new search '
			. "criteria.\n"
			. '- Corrections, clarifications, constraints, topic changes, and applicability challenges are '
			. ConversationRoute::SEARCH . ".\n"
			. '- If the conversation history only mentions an entity but does not contain the requested detail, '
			. 'this is ' . ConversationRoute::SEARCH . ".\n"
			. '- Use ' . ConversationRoute::ANSWER_FROM_HISTORY . ' only when the answer is directly present in '
			. "the history.\n"
			. '- For ' . ConversationRoute::SEARCH . ', rewrite the user message as a standalone question that '
			. "encompasses all pertinent context.\n"
			. "- Put explicitly excluded subjects only in exclude_query.\n"
			. "- Do not include excluded items in standalone_question.\n"
			. '- For ' . ConversationRoute::ANSWER_FROM_HISTORY . ' and ' . ConversationRoute::REJECT
			. ", standalone_question and exclude_query must be empty strings.\n"
			. "Do not answer the user. Only call the function.\n\n"
			. "    <Conversation history>\n"
			. $this->formatConversationHistory($historyPayload)
			. "\n"
			. "    <Question>\n"
			. "  $userQuery";
	}

	/**
	 * @return array{name:string, description:string, parameters:array<string, mixed>}
	 */
	private function toolDefinition(): array {
		return [
			'name' => self::TOOL_NAME,
			'description' => 'Route a user message in a retrieval-augmented conversation.',
			'parameters' => [
				'type' => 'object',
				'properties' => [
					'route' => [
						'type' => 'string',
						'enum' => [
							ConversationRoute::ANSWER_FROM_HISTORY,
							ConversationRoute::SEARCH,
							ConversationRoute::REJECT,
						],
					],
					'standalone_question' => [
						'type' => 'string',
						'description' => 'Standalone search question. Required for SEARCH, empty otherwise.',
					],
					'exclude_query' => [
						'type' => 'string',
						'description' => 'Explicit item or topic to exclude from SEARCH results, empty otherwise.',
					],
					'reason' => [
						'type' => 'string',
						'description' => 'Short routing explanation for logs.',
					],
				],
				'required' => ['route', 'standalone_question', 'exclude_query', 'reason'],
			],
		];
	}

	/**
	 * @param array<int, mixed> $toolCalls
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function parseRoute(array $toolCalls): ConversationRoute {
		if ($toolCalls === []) {
			throw ManticoreSearchClientError::create('Conversation routing returned no tool calls');
		}

		return $this->createRoute($this->extractToolCallArguments($toolCalls[0]));
	}

	/**
	 * @return array{route?: mixed, standalone_question?: mixed, exclude_query?: mixed, reason?: mixed}
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function extractToolCallArguments(mixed $toolCall): array {
		if (!$toolCall instanceof \ToolCall) {
			throw ManticoreSearchClientError::create('Conversation routing returned invalid tool call');
		}

		$arguments = $toolCall->getArguments();
		if (is_array($arguments)) {
			return $arguments;
		}

		if (is_string($arguments)) {
			return $this->decodeToolCallArguments($arguments);
		}

		throw ManticoreSearchClientError::create('Conversation routing returned invalid tool arguments');
	}

	/**
	 * @return array{route?: mixed, standalone_question?: mixed, exclude_query?: mixed, reason?: mixed}
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function decodeToolCallArguments(string $arguments): array {
		try {
			/** @var mixed $decoded */
			$decoded = simdjson_decode($arguments, true);
		} catch (Throwable $e) {
			throw ManticoreSearchClientError::create(
				'Conversation routing returned invalid tool arguments: ' . $e->getMessage()
			);
		}

		if (!is_array($decoded)) {
			throw ManticoreSearchClientError::create('Conversation routing returned invalid tool arguments');
		}

		return $decoded;
	}

	/**
	 * @param array{route?: mixed, standalone_question?: mixed, exclude_query?: mixed, reason?: mixed} $decoded
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function createRoute(array $decoded): ConversationRoute {
		if (!is_string($decoded['route'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation routing returned invalid route');
		}
		if (!is_string($decoded['standalone_question'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation routing returned invalid standalone question');
		}
		if (!is_string($decoded['exclude_query'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation routing returned invalid exclude query');
		}
		if (!is_string($decoded['reason'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation routing returned invalid reason');
		}

		$route = $decoded['route'];
		$standaloneQuestion = trim($decoded['standalone_question']);
		$excludeQuery = trim($decoded['exclude_query']);
		$reason = trim($decoded['reason']);
		$this->validateRoute($route, $standaloneQuestion, $excludeQuery);

		return new ConversationRoute($route, $standaloneQuestion, $excludeQuery, $reason);
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private function validateRoute(string $route, string $standaloneQuestion, string $excludeQuery): void {
		$validRoutes = [
			ConversationRoute::ANSWER_FROM_HISTORY,
			ConversationRoute::SEARCH,
			ConversationRoute::REJECT,
		];
		if (!in_array($route, $validRoutes, true)) {
			throw ManticoreSearchClientError::create("Conversation routing returned unknown route: $route");
		}

		if ($route === ConversationRoute::SEARCH && $standaloneQuestion === '') {
			throw ManticoreSearchClientError::create('Conversation routing returned empty search question');
		}
		if ($route !== ConversationRoute::SEARCH && $standaloneQuestion !== '') {
			throw ManticoreSearchClientError::create('Conversation routing returned unexpected search question');
		}
		if ($route !== ConversationRoute::SEARCH && $excludeQuery !== '') {
			throw ManticoreSearchClientError::create('Conversation routing returned unexpected exclude query');
		}
	}

	/**
	 * @param array<string, array{user?: string, assistant?: string}> $historyPayload
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function formatConversationHistory(array $historyPayload): string {
		$historyLines = [];
		foreach ($historyPayload as $turn) {
			if (isset($turn['user'])) {
				$historyLines[] = '    '
					. $this->encodeJson(['user' => $turn['user']]);
			}

			if (!isset($turn['assistant'])) {
				continue;
			}

			$historyLines[] = '    '
				. $this->encodeJson(['assistant' => $turn['assistant']]);
		}

		return implode("\n", $historyLines);
	}

	/**
	 * @param array<string, mixed> $data
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function encodeJson(array $data): string {
		try {
			return json_encode($data, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw ManticoreSearchClientError::create(
				'Conversation routing prompt encoding failed' . ': ' . $e->getMessage()
			);
		}
	}
}
