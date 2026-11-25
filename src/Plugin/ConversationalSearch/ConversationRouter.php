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
	private const int MAX_ROUTING_ATTEMPTS = 3;
	private const int ROUTING_MAX_TOKENS = 1024;
	private const int MAX_LEXICAL_TERMS = 4;
	private const string MISSING_LEXICAL_TERMS = 'Hybrid conversation query requires lexical terms for retrieval';
	private const string INVALID_LEXICAL_TERMS = 'Conversation query generation returned invalid lexical terms';
	private const string INVALID_LEXICAL_TERM = 'Conversation query generation returned invalid lexical term';
	private const string INVALID_CHAT_FIELDS = 'Conversation query generation returned invalid chat fields';
	private const array LEXICAL_FAILURES = [
		self::MISSING_LEXICAL_TERMS, self::INVALID_LEXICAL_TERMS,
		self::INVALID_LEXICAL_TERM,
	];

	/**
	 * @param array<string, mixed> $modelConfig
	 * @throws ManticoreSearchClientError
	 */
	public function route(
		string $userQuery,
		ConversationHistory $conversationHistory,
		LlmProvider $llmProvider,
		array $modelConfig,
		string $strategy = ConversationRequest::STRATEGY_KNN
	): ConversationRoute {
		$llmProvider->configure($modelConfig);
		$hybrid = $strategy === ConversationRequest::STRATEGY_HYBRID;
		$route = $this->generateRoute(
			$llmProvider,
			$this->buildPrompt($userQuery, $conversationHistory->payload(), $hybrid),
			$hybrid
		);

		Buddy::debugv("\nChat: [DEBUG CONVERSATION ROUTE]");
		Buddy::debugv("Chat: ├─ Route: $route->route");
		Buddy::debugv("Chat: ├─ Standalone question: '$route->standaloneQuestion'");
		Buddy::debugv("Chat: ├─ Exclude query: '$route->excludeQuery'");
		Buddy::debugv("Chat: └─ Reason: $route->reason");

		return $route;
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private function generateRoute(
		LlmProvider $llmProvider,
		string $prompt,
		bool $hybrid
	): ConversationRoute {
		$toolDefinition = $this->toolDefinition($hybrid);
		$options = [
			'temperature' => Handler::RESPONSE_TEMPERATURE,
			'max_tokens' => self::ROUTING_MAX_TOKENS,
		];
		$retryPrompt = $prompt;
		$searchOnlyRepair = false;

		for ($attempt = 1;; $attempt++) {
			$response = $llmProvider->generateToolCall($retryPrompt, $toolDefinition, $options);
			if ($response['success']) {
				try {
					$route = $this->parseRoute($response['tool_calls'], $hybrid);
					if ($searchOnlyRepair && $route->route !== ConversationRoute::SEARCH) {
						throw ManticoreSearchClientError::create('SEARCH-only routing repair returned CHAT');
					}
					return $route;
				} catch (ManticoreSearchClientError $error) {
					// A successful provider request can still contain an invalid route.
					$failure = $error->getResponseError();
					$searchOnlyRepair = $hybrid && $attempt === self::MAX_ROUTING_ATTEMPTS - 1
						&& in_array($failure, self::LEXICAL_FAILURES, true);
					$retryPrompt = $this->retryPrompt($prompt, $failure, $hybrid, $searchOnlyRepair);
					$toolDefinition = $this->toolDefinition($hybrid, $searchOnlyRepair);
				}
			} else {
				$error = ManticoreSearchClientError::create(
					LlmProvider::formatFailureMessage('Conversation routing failed', $response)
				);
			}

			if ($attempt === self::MAX_ROUTING_ATTEMPTS) {
				throw $error;
			}

			Buddy::debugv(
				'Chat: ├─ Conversation routing tool call failed, retrying attempt '
				. ($attempt + 1)
				. ' of '
				. self::MAX_ROUTING_ATTEMPTS
			);
		}
	}

	private function retryPrompt(string $prompt, string $failure, bool $hybrid, bool $searchOnly): string {
		$correction = match ($failure) {
			self::MISSING_LEXICAL_TERMS, self::INVALID_LEXICAL_TERMS,
			self::INVALID_LEXICAL_TERM =>
				'SEARCH requires 1 to 4 non-empty lexical_terms: a JSON list of strings, '
				. 'not a missing field, empty list, scalar, or object. Choose a distinctive anchor '
				. 'from the current message or relevant history, plus up to three separate stated terms. '
				. 'Do not repeat the anchor. CHAT instead requires an empty list; do not choose CHAT '
				. 'just to avoid providing search terms.',
			self::INVALID_CHAT_FIELDS =>
				'CHAT requires empty standalone_question and exclude_query'
				. ($hybrid ? ' and lexical_terms: []' : '')
				. '. Do not copy the previous search plan into an acknowledgement. If this message '
				. 'requests indexed information or a purchase, choose SEARCH and fill its search fields.',
			default => 'Call the tool again with a valid route and matching fields. '
				. ($hybrid
					? 'For hybrid SEARCH, lexical_terms must contain 1 to 4 non-empty strings '
						. 'with a distinctive anchor; for CHAT they must be empty.'
					: 'SEARCH requires a non-empty standalone_question; CHAT requires empty search fields.'),
		};
		// The shared SEARCH/CHAT schema cannot require non-empty lexical terms for SEARCH alone.
		$prefix = $searchOnly ? "Repair the SEARCH route; provide non-empty lexical_terms.\n\n" : '';
		return $prefix . "Previous tool call failed validation. $correction\n\n$prompt";
	}

	/**
	 * @param array<string, array{user?: string, assistant?: string}> $historyPayload
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function buildPrompt(
		string $userQuery,
		array $historyPayload,
		bool $hybrid
	): string {
		$lexicalRules = $hybrid
			? '- For SEARCH, set lexical_terms[0] to a short, distinctive anchor from the current '
				. 'message or relevant conversation history, and add at most three concrete terms that '
				. 'preserve the subject and its stated constraints across turns. Keep entity names and '
				. 'type terms separate unless the user gave an exact name. Do not invent attributes, '
				. 'names, or values, or fill the list with generic process or status phrases. '
				. 'Retain explicit constraints only while they apply to the current subject. '
				. 'Exclude explicitly rejected subjects. '
				. 'For SEARCH, always return at least one lexical term, including on short follow-ups; '
				. 'recover the subject from the applicable conversation history. '
				. 'Return plain terms, not MATCH syntax. '
				. "For CHAT, lexical_terms must be empty.\n"
			: '';
		return 'You route a user message in a retrieval-augmented conversation.' . "\n\n"
			. "Routes:\n"
			. '- ' . ConversationRoute::SEARCH . ': The user asks for new information, corrects the previous '
			. "query, clarifies meaning, adds constraints, changes topic, or questions applicability.\n"
			. '- ' . ConversationRoute::CHAT . ': The message does not request indexed information, '
			. 'such as a greeting, farewell, thanks, casual social question, or acknowledgement '
			. "without a new request.\n\n"
			. "Rules:\n"
			. '- Corrections, clarifications, constraints, topic changes, and applicability challenges are '
			. ConversationRoute::SEARCH . ".\n"
			. '- If the conversation history only mentions an entity
			but does not contain the requested detail, '
			. 'this is ' . ConversationRoute::SEARCH . ".\n"
			. '- Use ' . ConversationRoute::CHAT . ' only when no indexed information is requested, '
			. 'including "Hello", "Bye", "thanks", and "How are you?". A mixed message with a data request '
			. 'is SEARCH; shopping or purchase requests such as "Buy" are SEARCH, not CHAT. '
			. "If unsure whether a factual question needs indexed data, choose SEARCH.\n"
			. '- An acknowledgement such as "I am satisfied for now" is CHAT when it asks for no '
			. 'purchase, alternative, or new information, even if it mentions earlier results. '
			. "Do not carry previous search fields into CHAT.\n"
			. '- A message like "I have heard enough; how can I buy?" is SEARCH: the purchase '
			. "question takes precedence over the conversational closing.\n"
			. '- Positive or negative preferences and bare result rejections are SEARCH: '
			. 'search for similar alternatives using the relevant context; put named exclusions in exclude_query. '
			. "Do not treat negative preferences and bare result rejections as CHAT.\n"
			. '- For ' . ConversationRoute::SEARCH . ', rewrite the user message as a standalone question '
			. 'using the latest message\'s subject and only earlier constraints that still apply. '
			. 'If the latest message changes the subject or category, do not cast the new subject as a subtype '
			. 'of the old one; do not append the old category unless the user explicitly connects them. '
			. 'Do not discard compatible qualifiers when the subject changes; keep them unless the latest '
			. 'message supersedes them. '
			. "Resolve references to the same subject using the conversation history.\n"
			. "- Put explicitly excluded subjects only in exclude_query.\n"
			. "- Do not include excluded items in standalone_question.\n"
			. '- For ' . ConversationRoute::CHAT
			. ", standalone_question and exclude_query must be empty strings.\n"
			. $lexicalRules
			. "Do not answer the user. Only call the function.\n\n"
			. "<Conversation history>\n"
			. $this->formatConversationHistory($historyPayload)
			. "</Conversation history>\n"
			. "<Question>\n"
			. "  $userQuery</Question>";
	}

	/**
	 * @return array{name:string, description:string, parameters:array<string, mixed>}
	 */
	private function toolDefinition(bool $hybrid, bool $searchOnly = false): array {
		$tool = [
			'name' => self::TOOL_NAME,
			'description' => 'Route a user message in a retrieval-augmented conversation.',
			'parameters' => [
				'type' => 'object',
				'properties' => [
					'route' => [
						'type' => 'string',
						'enum' => [ConversationRoute::SEARCH, ConversationRoute::CHAT],
						'description' => 'SEARCH for indexed information; CHAT when no retrieval is requested.',
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
		if ($hybrid) {
			$tool['parameters']['properties']['lexical_terms'] = [
				'type' => 'array',
				'items' => ['type' => 'string'],
				'maxItems' => self::MAX_LEXICAL_TERMS,
				'description' => 'SEARCH requires 1 to 4 non-empty strings: primary anchor followed by '
					. 'up to 3 stated identity or constraint terms; CHAT requires [].',
			];
			$tool['parameters']['required'][] = 'lexical_terms';
		}
		if ($searchOnly) {
			$tool['parameters']['properties']['route']['enum'] = [ConversationRoute::SEARCH];
			$tool['parameters']['properties']['lexical_terms']['minItems'] = 1;
		}
		return $tool;
	}

	/**
	 * @param array<int, mixed> $toolCalls
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function parseRoute(array $toolCalls, bool $hybrid): ConversationRoute {
		if (sizeof($toolCalls) !== 1) {
			throw ManticoreSearchClientError::create('Conversation query generation requires one tool call');
		}

		return $this->createRoute($this->extractToolCallArguments($toolCalls[0]), $hybrid);
	}

	/**
	 * @return array{
	 *   route?: mixed, standalone_question?: mixed, exclude_query?: mixed,
	 *   reason?: mixed, lexical_terms?: mixed
	 * }
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function extractToolCallArguments(mixed $toolCall): array {
		if (!$toolCall instanceof \ToolCall) {
			throw ManticoreSearchClientError::create('Conversation query generation returned invalid tool call');
		}

		$arguments = $toolCall->getArguments();
		if (is_string($arguments)) {
			try {
				$arguments = simdjson_decode($arguments, true);
			} catch (Throwable $e) {
				throw ManticoreSearchClientError::create(
					'Conversation query generation returned invalid tool arguments: ' . $e->getMessage()
				);
			}
		}

		if (!is_array($arguments)) {
			throw ManticoreSearchClientError::create('Conversation query generation returned invalid tool arguments');
		}

		return $arguments;
	}

	/**
	 * @param array{
	 *   route?: mixed, standalone_question?: mixed, exclude_query?: mixed,
	 *   reason?: mixed, lexical_terms?: mixed
	 * } $decoded
	 *
	 * @throws ManticoreSearchClientError
	 */
	private function createRoute(array $decoded, bool $hybrid): ConversationRoute {
		$route = $decoded['route'] ?? null;
		if ($route !== ConversationRoute::SEARCH && $route !== ConversationRoute::CHAT) {
			throw ManticoreSearchClientError::create('Conversation routing returned invalid route');
		}
		if (!is_string($decoded['standalone_question'] ?? null)) {
			throw ManticoreSearchClientError::create(
				'Conversation query generation returned invalid standalone question'
			);
		}
		if (!is_string($decoded['exclude_query'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation query generation returned invalid exclude query');
		}
		if (!is_string($decoded['reason'] ?? null)) {
			throw ManticoreSearchClientError::create('Conversation routing returned invalid reason');
		}

		$standaloneQuestion = trim($decoded['standalone_question']);
		$excludeQuery = trim($decoded['exclude_query']);
		$reason = trim($decoded['reason']);
		if ($route === ConversationRoute::CHAT) {
			if ($decoded['standalone_question'] !== '' || $decoded['exclude_query'] !== '') {
				throw ManticoreSearchClientError::create(self::INVALID_CHAT_FIELDS);
			}
			return new ConversationRoute(ConversationRoute::CHAT, '', '', $reason);
		}
		if ($standaloneQuestion === '') {
			throw ManticoreSearchClientError::create('Conversation query generation returned empty search question');
		}
		$lexicalTerms = $hybrid
			? $this->validateLexicalTerms($decoded['lexical_terms'] ?? null) : [];

		return new ConversationRoute(
			ConversationRoute::SEARCH, $standaloneQuestion, $excludeQuery, $reason, $lexicalTerms
		);
	}

	/** @return list<string> */
	private function validateLexicalTerms(mixed $terms): array {
		if ($terms === null || $terms === []) {
			throw ManticoreSearchClientError::create(self::MISSING_LEXICAL_TERMS);
		}
		if (!is_array($terms) || !array_is_list($terms) || sizeof($terms) > self::MAX_LEXICAL_TERMS) {
			throw ManticoreSearchClientError::create(self::INVALID_LEXICAL_TERMS);
		}
		$normalized = [];
		foreach ($terms as $term) {
			if (!is_string($term) || trim($term) === '') {
				throw ManticoreSearchClientError::create(self::INVALID_LEXICAL_TERM);
			}
			$normalized[] = trim($term);
		}
		return $normalized;
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
				'Conversation query generation prompt encoding failed' . ': ' . $e->getMessage()
			);
		}
	}
}
