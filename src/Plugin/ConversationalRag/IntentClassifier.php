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
	 * Generate search query from conversation context.
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
		unset($intent);

		$queryPrompt = 'Rewrite the follow-up question on top of a human-assistant conversation history '
			. "as a standalone question that encompasses all pertinent context.\n\n"
			. "    <Conversation history>\n"
			. $this->formatConversationHistory($historyPayload)
			. "\n"
			. "    <Question>\n"
			. "  {$userQuery}\n\n"
			. '    <Standalone question>';

		$llmProvider->configure($modelConfig);
		$response = $llmProvider->generateResponse($queryPrompt);

		if (!$response['success']) {
			throw ManticoreSearchClientError::create(
				LlmProvider::formatFailureMessage('Query generation failed', $response)
			);
		}

		$llmResponseContent = (string)$response['content'];
		Buddy::debugv("\nRAG: [DEBUG QUERY GENERATION LLM RESPONSE]");
		Buddy::debugv('RAG: └─ Raw LLM response: ' . $this->formatLogLine($llmResponseContent));

		$searchQuery = $this->normalizeQueryString($llmResponseContent);
		if ($searchQuery === '') {
			throw ManticoreSearchClientError::create('Query generation returned empty search query');
		}

		return [
			'search_query' => $searchQuery,
			'exclude_query' => '',
			'llm_response' => $llmResponseContent,
		];
	}

	private function formatLogLine(string $value): string {
		return trim(str_replace(["\r", "\n"], [' ', ' '], $value));
	}

	/**
	 * @param array<string, array{user?: string, assistant?: string}> $historyPayload
	 */
	private function formatConversationHistory(array $historyPayload): string {
		$historyLines = [];
		foreach ($historyPayload as $turn) {
			if (isset($turn['user'])) {
				$historyLines[] = '    ' . $this->encodePromptJsonLine(['user' => $turn['user']]);
			}

			if (!isset($turn['assistant'])) {
				continue;
			}

			$historyLines[] = '    ' . $this->encodePromptJsonLine(['assistant' => $turn['assistant']]);
		}

		return implode("\n", $historyLines);
	}

	/**
	 * @param array{user?: string, assistant?: string} $line
	 */
	private function encodePromptJsonLine(array $line): string {
		try {
			return json_encode($line, JSON_THROW_ON_ERROR);
		} catch (JsonException $e) {
			throw ManticoreSearchClientError::create('Query generation prompt encoding failed: ' . $e->getMessage());
		}
	}

	private function normalizeQueryString(string $value): string {
		$normalizedValue = trim($value);
		if ($normalizedValue === '' || strtolower($normalizedValue) === 'none') {
			return '';
		}

		return $normalizedValue;
	}
}
