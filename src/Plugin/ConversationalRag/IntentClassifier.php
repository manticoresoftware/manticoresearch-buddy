<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use JsonException;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use UnexpectedValueException;

/**
 * LLM-driven intent classifier and query generator
 * Based on the original php_rag implementation - no hardcoded patterns
 */
class IntentClassifier {

	/**
	 * Classify user intent using LLM analysis of conversation context
	 *
	 * @param string $userQuery
	 * @param ConversationHistory $conversationHistory
	 * @param LlmProvider $llmProvider
	 * @param array<string, mixed> $modelConfig
	 * @return string
	 */
	public function classifyIntent(
		string $userQuery,
		ConversationHistory $conversationHistory,
		LlmProvider $llmProvider,
		array $modelConfig
	): string {
		try {
			$intentPrompt = 'You are a strict, deterministic intent classifier for conversational search. '
				. 'Analyze the `query` based on the provided `history`. '
				. 'Classify the user\'s intent into **EXACTLY ONE** of these categories: '
				. Intent::FOLLOW_UP . ',' . Intent::REFINE . ',' . Intent::EXPAND . ','
				. Intent::REJECT . ',' . Intent::NEW . ', or ' . Intent::UNCLEAR . ".\n\n"
				. "**Intent Definitions:**\n"
				. '* ' . Intent::FOLLOW_UP . ': Query relates directly to previously shown content '
				. "(e.g., \"Who directed it?\").\n"
				. '* ' . Intent::REFINE . ': User adds constraints or narrows the scope of the current search '
				. "(e.g., \"Only comedies,\" \"Not horror\").\n"
				. '* ' . Intent::EXPAND . ': User requests more results for the existing query without changing '
				. "criteria (e.g., \"Show more,\" \"What else?\").\n"
				. '* ' . Intent::REJECT . ': User dislikes or rejects the current results '
				. "(e.g., \"No,\" \"I don't like this\").\n"
				. '* ' . Intent::NEW . ': Query introduces a completely new topic or search request '
				. "unrelated to history.\n"
				. '* ' . Intent::UNCLEAR . ": Intent is ambiguous, incomplete, or nonsensical.\n\n"
				. '**Rules:** Be strictly deterministic. Base your decision ONLY on the provided JSON data. '
				. 'Do not infer missing context. Prioritize rules as follows: '
				. Intent::FOLLOW_UP . ' ' . Intent::REFINE . ' ' . Intent::EXPAND . ' ' . Intent::NEW
				. ' ' . Intent::REJECT . '. Use ' . Intent::UNCLEAR . " only when confidence is low.\n\n"
				. '**Output Format:** Return ONLY the single classification word '
				. '(e.g., `' . Intent::FOLLOW_UP . "`).\n\n"
				. "**Input:**\n"
				. "```json\n"
				. json_encode(['history' => $conversationHistory->payload(), 'query' => $userQuery])
				. "\n```";

			$llmProvider->configure($modelConfig);
			$response = $llmProvider->generateResponse(
				$intentPrompt,
				[
					'temperature' => Handler::RESPONSE_TEMPERATURE,
					'max_tokens' => 50,
				]
			);

			if (!$response['success']) {
				throw new ManticoreSearchClientError(
					LlmProvider::formatFailureMessage('Intent classification failed', $response)
				);
			}

			$content = (string)$response['content'];
			Buddy::debugv("\nRAG: [DEBUG INTENT CLASSIFICATION LLM RESPONSE]");
			Buddy::debugv('RAG: └─ Raw LLM response: ' . $this->formatLogLine($content));

			$intent = $this->validateIntent(trim(strtoupper($content)));
			if ($intent === null) {
				Buddy::error(new UnexpectedValueException("Unexpected intent classification response: {$content}"));
				return Intent::NEW;
			}

			if ($intent === Intent::UNCLEAR) {
				Buddy::debugv('RAG: └─ UNCLEAR intent classified, falling back to NEW');
				return Intent::NEW;
			}

			// Debug: Log intent classification
			Buddy::debugv("\nRAG: [DEBUG INTENT CLASSIFICATION]");
			Buddy::debugv("RAG: └─ Detected intent: {$intent}");

			return $intent;
		} catch (ManticoreSearchClientError $e) {
			Buddy::debug("Error intent classification: {$e->getMessage()}");
			return Intent::NEW;
		}
	}

	/**
	 * Validate and clean intent from LLM response
	 *
	 * @param string $intent
	 * @return string|null
	 */
	private function validateIntent(string $intent): ?string {
		$intent = trim($intent);
		$validIntents = [
			Intent::FOLLOW_UP,
			Intent::REFINE,
			Intent::EXPAND,
			Intent::REJECT,
			Intent::NEW,
			Intent::UNCLEAR,
		];

		return in_array($intent, $validIntents, true) ? $intent : null;
	}

	/**
	 * Generate search and exclude queries based on intent
	 *
	 * @param string $userQuery
	 * @param string $intent
	 * @param array<string, array{user?: string, assistant?: string}> $historyPayload
	 * @param LlmProvider $llmProvider
	 * @param array<string, mixed> $modelConfig
	 *
	 * @return array{search_query:string, exclude_query: string, llm_response: string}
	 * @throws ManticoreSearchClientError
	 */
	public function generateQueries(
		string $userQuery,
		string $intent,
		array $historyPayload,
		LlmProvider $llmProvider,
		array $modelConfig
	): array {
		$queryPrompt = '**ROLE:** You are an expert Search Query Generator. '
			. 'Your sole function is to convert a user request into a highly structured '
			. "JSON output suitable for advanced search engines.\n"
			. '**GOAL:** Generate a rich, comma-separated list of keywords (`search_keywords`) '
			. 'based on the `Query` and context from `History`. The query must be sorted by '
			. "relevance: Content Type → Genre/Theme → Specific Keywords.\n"
			. '**TERM QUALITY (CRITICAL):** Search terms must be synonym-like expansions of the '
			. 'actual user request and conversation context, not generic tags or detached topic '
			. "labels.\n"
			. "*   Prefer complete phrases that preserve the subject of the request.\n"
			. '*   Do not emit broad standalone tags such as "explanation" or "RAG" unless '
			. "that exact tag is the subject itself.\n"
			. "*   Good: \"RAG explanation\", \"retrieval augmented generation overview\".\n"
			. "*   Bad: \"explanation\", \"RAG\".\n"
			. "**OUTPUT FORMAT (STRICT):** You MUST respond ONLY in JSON format:\n"
			. "```json\n"
			. "{\n"
			. '"search_keywords": [{"term": "keyword1", "confidence": 95}],
         "exclude_query": ["title_to_exclude"],
         "debug": "Here you should explain your answer"
         }'
			. "\n```\n\n"
			. '**INTENT RULES:**

- **NEW**
  - Treat Query as standalone
  - Ignore unrelated History

- **FOLLOW_UP**
  - Keep topic from History
  - Combine with Query (resolve "this", "it", "more")

- **REFINE**
  - Keep topic
  - Apply Query as constraint (narrow or adjust)

- **EXPAND**
  - Keep topic
  - Broaden with related aspects and variations

- **REJECT**
  - Ignore Query as search input if it is only a rejection signal
  - Keep topic from History
  - Generate alternative angles or formulations

- **UNCLEAR**
  - Infer topic from History only if clearly identifiable
  - Otherwise return empty keywords
'
			. '**RULES FOR `EXCLUDE_QUERY` (CRITICAL):** Extract exclusions from both the current '
			. "query and history, following these strict rules:\n"
			. "**A. Explicit Exclusions (Current Query):**\n"
			. "*   If the user says \"I already watched X\" → EXCLUDE: X\n"
			. "*   If the user says \"similar to X but not Y\" → EXCLUDE: Y\n"
			. "*   If the user says \"not Z\" → EXCLUDE: Z\n"
			. "*   (Apply this logic regardless of intent.)\n"
			. "**B. History-Based Exclusions (Contextual):**\n"
			. "1.  Scan `History` **BACKWARDS** from the most recent message.\n"
			. '2.  Stop scanning immediately if you detect a Topic Change
			   3.  Only include exclusions relevant to the *current* topic context.
			   4.  If no exclusions are found in the query or history, use "none".'."\n\n"
			. '**EXAMPLE SCENARIO (History Context):** If History shows a topic change '
			. "occurred before a rejection, that rejection is ignored for `EXCLUDE_QUERY`.\n\n"
				. "**INPUT:**\n"
				. "```json\n"
				. json_encode(['history' => $historyPayload, 'query' => $userQuery, 'intent' => $intent])
				. "\n```\n\n"
				. 'JSON:';

		$llmProvider->configure($modelConfig);
		$response = $llmProvider->generateResponse($queryPrompt);
		$llmResponseContent = (string)$response['content'];

		if (!$response['success']) {
			throw ManticoreSearchClientError::create(
				LlmProvider::formatFailureMessage('Query generation failed', $response)
			);
		}

		Buddy::debugv("\nRAG: [DEBUG QUERY GENERATION LLM RESPONSE]");
		Buddy::debugv('RAG: └─ Raw LLM response: ' . $this->formatLogLine($llmResponseContent));

		$normalizedJson = $this->normalizeJsonResponse($llmResponseContent);
		Buddy::debugv('RAG: └─ Normalized JSON payload: ' . $this->formatLogLine($normalizedJson));

		try {
			$decodedResponse = json_decode($normalizedJson, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw ManticoreSearchClientError::create(
				'Query generation returned invalid JSON: ' . $e->getMessage()
			);
		}

		if (!is_array($decodedResponse)) {
			throw ManticoreSearchClientError::create('Query generation returned invalid JSON structure');
		}

		$searchQuery = $this->normalizeSearchKeywords($decodedResponse['search_keywords'] ?? null);
		if ($searchQuery === '') {
			$searchQuery = $this->normalizeQueryString($userQuery);
		}
		$excludeQuery = $this->normalizeQueryTerms($decodedResponse['exclude_query'] ?? null, 'exclude_query');

		return [
			'search_query' => $searchQuery,
			'exclude_query' => $excludeQuery,
			'llm_response' => $llmResponseContent,
		];
	}

	/**
	 * @param mixed $value
	 * @param string $field
	 * @return string
	 */
	private function normalizeQueryTerms(mixed $value, string $field): string {
		if (is_string($value)) {
			return $this->normalizeQueryString($value);
		}

		if (!is_array($value)) {
			throw new ManticoreSearchClientError("Query generation field '{$field}' must be a JSON array or string");
		}

		$terms = [];
		foreach ($value as $term) {
			if (!is_string($term)) {
				throw new ManticoreSearchClientError(
					"Query generation field '{$field}' must contain only strings"
				);
			}

			$normalizedTerm = trim($term);
			if ($normalizedTerm === '') {
				continue;
			}

			$terms[] = $normalizedTerm;
		}

		return $this->normalizeQueryString(implode(', ', $terms));
	}

	/**
	 * @param mixed $value
	 *
	 * @return string
	 * @throws ManticoreSearchClientError
	 */
	private function normalizeSearchKeywords(mixed $value): string {
		if (!is_array($value)) {
			throw new ManticoreSearchClientError(
				"Query generation field 'search_keywords' must be a JSON array"
			);
		}

		$terms = [];
		foreach ($value as $keyword) {
			if (!is_array($keyword)) {
				throw new ManticoreSearchClientError(
					"Query generation field 'search_keywords' must contain only objects"
				);
			}

			$term = $keyword['term'] ?? null;
			if (!is_string($term)) {
				throw new ManticoreSearchClientError(
					"Query generation field 'search_keywords' objects must contain a string 'term'"
				);
			}

			$normalizedTerm = trim($term);
			if ($normalizedTerm === '') {
				continue;
			}

			$terms[] = $normalizedTerm;
		}

		return $this->normalizeQueryString(implode(', ', $terms));
	}

	private function normalizeJsonResponse(string $response): string {
		$normalizedResponse = trim($response);
		if (!str_starts_with($normalizedResponse, '```')) {
			return $normalizedResponse;
		}

		$normalizedResponse = preg_replace('/^```[a-zA-Z0-9_-]*\s*/', '', $normalizedResponse);
		if (!is_string($normalizedResponse)) {
			throw ManticoreSearchClientError::create('Query generation returned invalid fenced JSON');
		}

		$normalizedResponse = preg_replace('/\s*```$/', '', $normalizedResponse);
		if (!is_string($normalizedResponse)) {
			throw ManticoreSearchClientError::create('Query generation returned invalid fenced JSON');
		}

		return trim($normalizedResponse);
	}

	private function formatLogLine(string $value): string {
		return trim(str_replace(["\r", "\n"], [' ', ' '], $value));
	}

	private function normalizeQueryString(string $value): string {
		$normalizedValue = trim($value);
		if ($normalizedValue === '' || strtolower($normalizedValue) === 'none') {
			return '';
		}

		return $normalizedValue;
	}
}
