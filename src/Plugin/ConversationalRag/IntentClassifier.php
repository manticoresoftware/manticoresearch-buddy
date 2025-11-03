<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

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
	 * @param array $lastSearchResults
	 * @param LLMProviderManager $llmProvider
	 * @param array $modelConfig
	 * @return array
	 */
	public function classifyIntent(
		string $userQuery,
		string $conversationHistory,
		array $lastSearchResults,
		LLMProviderManager $llmProvider,
		array $modelConfig
	): array {
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
- TOPIC_CHANGE: User switching to new topic (like 'I want comedies instead', 'show me action movies')
- INTEREST: User likes content and wants similar (like 'sounds good, what else like this', 'tell me more')
- NEW_SEARCH: Fresh search with no prior context
- QUESTION: User asking about shown content (like 'what's it about', 'who's in it')
- CLARIFICATION: User providing additional details or correcting previous query (like 'no it's from a movie', 'I meant something else')
- UNCLEAR: Cannot determine intent (like gibberish, confusing, ambiguous)

Answer ONLY with one word: REJECTION, ALTERNATIVES, TOPIC_CHANGE, INTEREST, NEW_SEARCH, QUESTION, CLARIFICATION, or UNCLEAR";

			$provider = $llmProvider->getConnection('intent_classifier', $modelConfig);
			$response = $provider->generateResponse($intentPrompt, [], ['temperature' => 0.1, 'max_tokens' => 50]);

			if (!$response['success']) {
				throw new ManticoreSearchClientError('Intent classification failed: ' . ($response['error'] ?? 'Unknown error'));
			}

			$intent = $this->validateIntent(trim(strtoupper($response['content'])));

			// Debug: Log intent classification
			Buddy::info("\n[DEBUG INTENT CLASSIFICATION]");
			Buddy::info("└─ Detected intent: {$intent}");

			return [
				'intent' => $intent,
				'confidence' => 0.9,
				'llm_response' => $response['content'],
			];
		} catch (\Exception $e) {
			// Fallback to NEW_SEARCH
			return [
				'intent' => 'NEW_SEARCH',
				'confidence' => 0.5,
				'error' => $e->getMessage(),
			];
		}
	}

	/**
	 * Generate search queries based on user intent and conversation context
	 *
	 * @param string $userQuery
	 * @param string $intent
	 * @param string $conversationHistory
	 * @param LLMProviderManager $llmProvider
	 * @param array $modelConfig
	 * @return array
	 */
	public function generateQueries(
		string $userQuery,
		string $intent,
		string $conversationHistory,
		LLMProviderManager $llmProvider,
		array $modelConfig
	): array {

		$searchQuery = '';
		$excludeQuery = '';

		try {
			// Limit history size for LLM context (conversationHistory is already a formatted string)
			$conversationHistory = $this->limitConversationHistory($conversationHistory);
			$historyText = $conversationHistory;

			$queryPrompt = "Generate search query based on user request.

History:
{$historyText}

Query: {$userQuery}
Intent: {$intent}

Generate a rich search query with:
- Content type (movies, TV shows, books, etc.)
- Genre/theme/topic keywords
- Multiple relevant terms

Answer format:
SEARCH_QUERY: [your query]
EXCLUDE_QUERY: [titles to exclude OR 'none']

Rules for EXCLUDE_QUERY - Extract exclusions from BOTH current query AND history:
1. EXPLICIT exclusions in current query (regardless of intent):
   - 'I already watched X' → EXCLUDE: X
   - 'similar to X but not Y' → EXCLUDE: Y
   - 'I like that direction but not Z' → EXCLUDE: Z
   - 'tell me about programming but not Python' → EXCLUDE: Python

2. HISTORY-based exclusions (CRITICAL - Read carefully):
   - REJECTION/ALTERNATIVES: Include titles previously rejected from history
   - TOPIC_CHANGE: Use 'none' (fresh start, ignore history)
   - IMPORTANT: Scan history BACKWARDS from most recent message
   - STOP extracting exclusions if you encounter a topic change in history
   - Only include exclusions from the CURRENT topic context
   - Indicators of topic change: 'instead', 'actually', 'now show me', 'switch to', genre changes

3. If NO exclusions found in query OR history: Use 'none'

Examples:
- Query: 'I already watched Breaking Bad but I like that direction'
  EXCLUDE_QUERY: Breaking Bad

- Query: 'What else like Game of Thrones? Not The Witcher though'
  EXCLUDE_QUERY: The Witcher

- Query: 'I love this!' (with no exclusions)
  EXCLUDE_QUERY: none

- History: 'crime dramas' → 'I don't like Breaking Bad' → 'Show me comedies instead' → 'What else?'
  Query: 'What else?'
  Intent: ALTERNATIVES
  Analysis: Topic changed to comedies, Breaking Bad rejection was in previous topic
  EXCLUDE_QUERY: none (Breaking Bad is from old topic context)

- History: 'comedies' → 'I don't like The Office' → 'What else?'
  Query: 'What else?'
  Intent: ALTERNATIVES
  Analysis: Same topic (comedies), The Office rejection is current
  EXCLUDE_QUERY: The Office (same topic context)

Answer ONLY in the format above.";

			$provider = $llmProvider->getConnection('query_generator', $modelConfig);
			$response = $provider->generateResponse($queryPrompt, [], ['temperature' => 0.3, 'max_tokens' => 200]);

			if (!$response['success']) {
				throw new ManticoreSearchClientError('Query generation failed: ' . ($response['error'] ?? 'Unknown error'));
			}

			$lines = explode("\n", trim($response['content']));
			foreach ($lines as $line) {
				$line = trim($line);
				if (preg_match('/^SEARCH_QUERY:\s*(.+)$/i', $line, $matches)) {
					$searchQuery = trim($matches[1]);
				}
				if (!preg_match('/^EXCLUDE_QUERY:\s*(.+)$/i', $line, $matches)) {
					continue;
				}

				$excludeQuery = trim($matches[1]);
			}

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
				'llm_response' => $response['content'],
			];
		} catch (\Exception $e) {
			// Fallback to safe defaults
			if (empty($searchQuery)) {
				$searchQuery = $userQuery;
			}

			// Debug: Log query generation
			Buddy::info("\n[DEBUG QUERY GENERATION]");
			Buddy::info("├─ User query: '{$userQuery}'");
			Buddy::info("├─ Intent: {$intent}");
			Buddy::info("├─ Generated SEARCH_QUERY: '{$searchQuery}'");
			Buddy::info("└─ Generated EXCLUDE_QUERY: '{$excludeQuery}'");

			return [
				'search_query' => $searchQuery,
				'exclude_query' => $excludeQuery,
				'llm_response' => $response['content'] ?? $e->getMessage(),
			];
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
		if (count($lines) > $maxLines) {
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
		$validIntents = ['REJECTION',
			'ALTERNATIVES', 'TOPIC_CHANGE',
			'INTEREST', 'NEW_SEARCH', 'QUESTION',
			'CLARIFICATION', 'UNCLEAR'];

		// Extract just the intent word if LLM added explanation
		foreach ($validIntents as $valid) {
			if (stripos($intent, $valid) !== false) {
				return $valid;
			}
		}

		// Default fallback
		return 'NEW_SEARCH';
	}
}
