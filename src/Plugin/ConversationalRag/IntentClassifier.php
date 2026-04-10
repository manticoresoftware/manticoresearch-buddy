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

/**
 * LLM-driven intent classifier and query generator
 * Based on the original php_rag implementation - no hardcoded patterns
 */
class IntentClassifier {

	/**
	 * Classify user intent using LLM analysis of conversation context
	 *
	 * @param string $userQuery
	 * @param string $conversationHistory
	 * @param LlmProvider $llmProvider
	 * @param array<string, mixed> $modelConfig
	 * @return string
	 */
	public function classifyIntent(
		string $userQuery,
		string $conversationHistory,
		LlmProvider $llmProvider,
		array $modelConfig
	): string {
		try {
			// Limit history size for LLM context (conversationHistory is already a formatted string)
			$conversationHistory = $this->limitConversationHistory($conversationHistory);
			$historyText = $conversationHistory;

			$intentPrompt = "Analyze user intent from conversation.

History:
{$historyText}

Query: {$userQuery}

Classify as ONE of:
- REJECTION: User declining shown content (like 'no', 'not interested', 'don't like')
- ALTERNATIVES: User wants more options (like 'what else', 'other options', 'anything else')
- TOPIC_CHANGE: User switching to new topic (like 'I want comedies instead',
  'show me action movies')
- INTEREST: User likes content and wants similar (like 'sounds good, what else like this',
  'tell me more')
- NEW_SEARCH: Fresh search with no prior context
- CONTENT_QUESTION: User asking about previously shown content (like 'what's the cast',
  'who directed it', 'when was it made', 'what's it about')
- NEW_QUESTION: User asking about new topic requiring search (like 'what about action movies',
  'show me comedies', 'tell me about programming')
- CLARIFICATION: User providing additional details or correcting previous query
  (like 'no it's from a movie', 'I meant something else')
- UNCLEAR: Cannot determine intent (like gibberish, confusing, ambiguous)

Answer ONLY with one word: REJECTION, ALTERNATIVES, TOPIC_CHANGE, INTEREST,
NEW_SEARCH, CONTENT_QUESTION, NEW_QUESTION, CLARIFICATION, or UNCLEAR";

			$llmProvider->configure($modelConfig);
			$response = $llmProvider->generateResponse($intentPrompt, ['temperature' => 0, 'max_tokens' => 50]);

			if (!$response['success']) {
				throw new ManticoreSearchClientError(
					'Intent classification failed: ' . $response['error']
				);
			}

			$content = (string)$response['content'];
			$intent = $this->validateIntent(trim(strtoupper($content)));

			// Debug: Log intent classification
			Buddy::debugv("\nRAG: [DEBUG INTENT CLASSIFICATION]");
			Buddy::debugv("RAG: └─ Detected intent: {$intent}");

			return $intent;
		} catch (ManticoreSearchClientError $e) {
			Buddy::debug("Error intent classification: {$e->getMessage()}");
			return Intent::NEW_SEARCH;
		}
	}

	/**
	 * Limit conversation history size for LLM context (matches original php_rag implementation)
	 *
	 * @param string $history
	 * @param int $maxExchanges
	 * @return string
	 */
	private function limitConversationHistory(string $history, int $maxExchanges = 10): string {
		// Split by role markers (matches php_rag Line 57)
		$lines = explode("\n", $history);

		// Keep only last N exchanges (2 lines per exchange) (matches php_rag Line 60-63)
		$maxLines = $maxExchanges * 2;
		if (sizeof($lines) > $maxLines) {
			$lines = array_slice($lines, -$maxLines);
		}

		return implode("\n", $lines);
	}

	/**
	 * Validate and clean intent from LLM response
	 *
	 * @param string $intent
	 * @return string
	 */
	private function validateIntent(string $intent): string {
		$validIntents = [
			Intent::REJECTION,
			Intent::ALTERNATIVES,
			Intent::TOPIC_CHANGE,
			Intent::INTEREST,
			Intent::NEW_SEARCH,
			Intent::CONTENT_QUESTION,
			Intent::NEW_QUESTION,
			Intent::CLARIFICATION,
			Intent::UNCLEAR,
		];

		// Extract just the intent word if LLM added explanation
		foreach ($validIntents as $valid) {
			if (stripos($intent, $valid) !== false) {
				return $valid;
			}
		}

		// Default fallback
		return Intent::NEW_SEARCH;
	}

	/**
	 * Generate search and exclude queries based on intent
	 *
	 * @param string $userQuery
	 * @param string $intent
	 * @param string $conversationHistory
	 * @param LlmProvider $llmProvider
	 * @param array<string, mixed> $modelConfig
	 * @return array{search_query:string, exclude_query: string, llm_response: string}
	 */
	public function generateQueries(
		string $userQuery,
		string $intent,
		string $conversationHistory,
		LlmProvider $llmProvider,
		array $modelConfig
	): array {
		$searchQuery = '';
		$excludeQuery = '';
		$llmResponseContent = '';

		try {
			// Limit history size for LLM context (conversationHistory is already a formatted string)
			$conversationHistory = $this->limitConversationHistory($conversationHistory);
			$historyText = $conversationHistory;

			$queryPrompt = '**ROLE:** You are an expert Search Query Generator. '
				. 'Your sole function is to convert a user request into a highly structured '
				. "JSON output suitable for advanced search engines.\n"
				. '**GOAL:** Generate a rich, comma-separated list of keywords (`search_keywords`) '
				. 'based on the `Query` and context from `History`. The query must be sorted by '
				. "relevance: Content Type → Genre/Theme → Specific Keywords.\n"
				. "**OUTPUT FORMAT (STRICT):** You MUST respond ONLY in JSON format:\n"
				. "```json\n"
				. "{\n"
				. "\"search_keywords\": [{\"term\": \"keyword1\", \"confidence\": 95}],\n"
				. "\"exclude_query\": [\"title_to_exclude\"],\n"
				. "}\n"
				. "```\n\n"
				. '**RULES FOR `EXCLUDE_QUERY` (CRITICAL):** Extract exclusions from both the current '
				. "query and history, following these strict rules:\n"
				. "**A. Explicit Exclusions (Current Query):**\n"
				. "*   If the user says \"I already watched X\" → EXCLUDE: X\n"
				. "*   If the user says \"similar to X but not Y\" → EXCLUDE: Y\n"
				. "*   If the user says \"not Z\" → EXCLUDE: Z\n"
				. "*   (Apply this logic regardless of intent.)\n"
				. "**B. History-Based Exclusions (Contextual):**\n"
				. "1.  Scan `History` **BACKWARDS** from the most recent message.\n"
				. '2.  Stop scanning immediately if you detect a Topic Change Indicator '
				. "(e.g., 'instead', 'actually', 'now show me', genre shift).\n"
				. "3.  Only include exclusions relevant to the *current* topic context.\n"
				. "4.  If no exclusions are found in the query or history, use \"none\".\n\n"
				. '**EXAMPLE SCENARIO (History Context):** If History shows a topic change '
				. "occurred before a rejection, that rejection is ignored for `EXCLUDE_QUERY`.\n\n"
				. "**INPUTS:**\n"
				. 'History: ' . (mb_strlen($historyText) > 0 ? $historyText : '(none)') . "\n"
				. 'Query: ' . $userQuery . "\n"
				. 'Intent: ' . $intent . "\n\n"
				. 'JSON:';

			$llmProvider->configure($modelConfig);
			$response = $llmProvider->generateResponse($queryPrompt, ['temperature' => 0, 'max_tokens' => 200]);
			$llmResponseContent = (string)$response['content'];

			if (!$response['success']) {
				throw new ManticoreSearchClientError(
					'Query generation failed: ' . $response['error']
				);
			}

			Buddy::debugv("\nRAG: [DEBUG QUERY GENERATION LLM RESPONSE]");
			Buddy::debugv('RAG: └─ Raw LLM response: ' . $llmResponseContent);

			/** @var mixed $decodedResponse */
			$decodedResponse = json_decode($llmResponseContent, true, 512, JSON_THROW_ON_ERROR);
			if (!is_array($decodedResponse)) {
				throw new ManticoreSearchClientError('Query generation returned invalid JSON structure');
			}

			$searchQuery = $this->normalizeSearchKeywords($decodedResponse['search_keywords'] ?? null);
			$excludeQuery = $this->normalizeQueryTerms($decodedResponse['exclude_query'] ?? null, 'exclude_query');

			// Fallback to user query if parsing failed
			if (empty($searchQuery)) {
				$searchQuery = $userQuery;
			}

			// Clean up exclude query
			if (empty($excludeQuery) || strtolower($excludeQuery) === 'none') {
				$excludeQuery = '';
			}

			return [
				'search_query' => $searchQuery,
				'exclude_query' => $excludeQuery,
				'llm_response' => $llmResponseContent,
			];
		} catch (JsonException | ManticoreSearchClientError $e) {
			$searchQuery = $userQuery;

			// Debug: Log query generation
			Buddy::debugv("\nRAG: [DEBUG QUERY GENERATION]");
			Buddy::debugv("RAG: ├─ User query: '{$userQuery}'");
			Buddy::debugv("RAG: ├─ Intent: {$intent}");
			Buddy::debugv(
				'RAG: ├─ Raw LLM response: ' .
				($llmResponseContent !== '' ? $llmResponseContent : $e->getMessage())
			);
			Buddy::debugv("RAG: ├─ Generated SEARCH_QUERY: '{$searchQuery}'");
			Buddy::debugv("RAG: └─ Generated EXCLUDE_QUERY: '{$excludeQuery}'");

			return [
				'search_query' => $searchQuery,
				'exclude_query' => $excludeQuery,
				'llm_response' => $llmResponseContent !== '' ? $llmResponseContent : $e->getMessage(),
			];
		}
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
	 * @return string
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

	private function normalizeQueryString(string $value): string {
		$normalizedValue = trim($value);
		if ($normalizedValue === '' || strtolower($normalizedValue) === 'none') {
			return '';
		}

		return $normalizedValue;
	}
}
