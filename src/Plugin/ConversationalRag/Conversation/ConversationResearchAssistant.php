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

final class ConversationResearchAssistant {
	private const string TOOL_NAME = 'ask_llm_research';

	/**
	 * @param array<string, mixed> $modelConfig
	 * @return array{
	 *   constraints:array<int, string>,
	 *   keywords:array<int, string>,
	 *   expanded_query:string,
	 *   exclude_query:string,
	 *   confidence:float,
	 *   reason:string
	 * }
	 * @throws ManticoreSearchClientError
	 */
	public function research(
		string $userQuery,
		string $deriveTask,
		ConversationHistory $conversationHistory,
		LlmProvider $llmProvider,
		array $modelConfig
	): array {
		$llmProvider->configure($modelConfig);
		$response = $llmProvider->generateToolCall(
			$this->buildPrompt($userQuery, $deriveTask, $conversationHistory->payload()),
			$this->toolDefinition(),
			[
				'temperature' => Handler::RESPONSE_TEMPERATURE,
				'max_tokens' => 220,
			]
		);
		if (!$response['success']) {
			throw ManticoreSearchClientError::create(
				LlmProvider::formatFailureMessage('Research tool call failed', $response)
			);
		}

		$result = $this->parseResult($response['tool_calls']);
		Buddy::debugv('RAG: ├─ Research constraints: [' . implode(', ', $result['constraints']) . ']');
		Buddy::debugv('RAG: ├─ Research keywords: [' . implode(', ', $result['keywords']) . ']');
		Buddy::debugv("RAG: ├─ Research expanded_query: '{$result['expanded_query']}'");
		Buddy::debugv("RAG: ├─ Research exclude_query: '{$result['exclude_query']}'");
		Buddy::debugv("RAG: ├─ Research confidence: {$result['confidence']}");
		Buddy::debugv("RAG: └─ Research reason: {$result['reason']}");
		return $result;
	}

	/**
	 * @param array<string, array{user?: string, assistant?: string}> $historyPayload
	 */
	private function buildPrompt(string $userQuery, string $deriveTask, array $historyPayload): string {
		return 'You are a database research helper. Your job is to convert a derive task into concrete, '
			. "searchable terms for vector KNN retrieval.\n\n"
			. "Rules:\n"
			. '1. expanded_query is the final KNN query. It must be concise and optimized for semantic '
			. "vector search, not full-text boolean matching.\n"
			. '2. Prefer concrete values likely stored in documents: model names, SKUs, standards, '
			. "protocols, colors, generations, codenames, and product families.\n"
			. "3. Do not duplicate terms between expanded_query parts.\n"
			. "4. exclude_query is for explicit negative constraints.\n"
			. "5. For compatibility tasks, derive concrete compatibility facts when known.\n"
			. '6. For alternatives tasks, derive the source entity category, bracket, and key features, then '
			. "list searchable alternatives with those features.\n"
			. "7. Preserve constraints from conversation history when the user query is a follow-up.\n"
			. '8. If concrete facts cannot be derived with confidence above 0.6, return a natural-language '
			. "semantic query instead of a generic keyword bag.\n"
			. "9. keywords are for diagnostics only; do not rely on the caller appending them.\n"
			. "Only call the function.\n\n"
			. "<user_query>$userQuery</user_query>\n"
			. "<derive_task>$deriveTask</derive_task>\n"
			. "<conversation_history>\n"
			. $this->encodeJson($historyPayload, 'Research prompt history encoding failed')
			. "\n</conversation_history>";
	}

	/**
	 * @return array{name:string,description:string,parameters:array<string,mixed>}
	 */
	private function toolDefinition(): array {
		return [
			'name' => self::TOOL_NAME,
			'description' => 'Generate an expanded query and optional exclude query for retrieval.',
			'parameters' => [
				'type' => 'object',
				'properties' => [
					'constraints' => [
						'type' => 'array',
						'items' => ['type' => 'string'],
					],
					'keywords' => [
						'type' => 'array',
						'items' => ['type' => 'string'],
					],
					'expanded_query' => ['type' => 'string'],
					'exclude_query' => ['type' => 'string'],
					'confidence' => ['type' => 'number'],
					'reason' => ['type' => 'string'],
				],
					'required' => [
						'constraints',
						'keywords',
						'expanded_query',
						'exclude_query',
						'confidence',
						'reason',
					],
			],
		];
	}

	/**
	 * @param array<int, mixed> $toolCalls
	 * @return array{
	 *   constraints:array<int, string>,
	 *   keywords:array<int, string>,
	 *   expanded_query:string,
	 *   exclude_query:string,
	 *   confidence:float,
	 *   reason:string
	 * }
	 * @throws ManticoreSearchClientError
	 */
	private function parseResult(array $toolCalls): array {
		$decoded = (new LlmToolCallArgumentsReader())->read($toolCalls, 'Research tool');
		$constraints = $this->readStringList($decoded['constraints'] ?? null, 'constraints');
		$keywords = $this->readStringList($decoded['keywords'] ?? null, 'keywords');
		if (!is_string($decoded['expanded_query'] ?? null)) {
			throw ManticoreSearchClientError::create('Research tool returned invalid expanded query');
		}
		if (!is_string($decoded['exclude_query'] ?? null)) {
			throw ManticoreSearchClientError::create('Research tool returned invalid exclude query');
		}
		if (!is_string($decoded['reason'] ?? null)) {
			throw ManticoreSearchClientError::create('Research tool returned invalid reason');
		}

		$expanded = trim($decoded['expanded_query']);
		$exclude = trim($decoded['exclude_query']);
		$confidence = $decoded['confidence'] ?? null;
		$reason = trim($decoded['reason']);
		if ($expanded === '') {
			throw ManticoreSearchClientError::create('Research tool returned empty expanded query');
		}
		if (!is_int($confidence) && !is_float($confidence)) {
			throw ManticoreSearchClientError::create('Research tool returned invalid confidence');
		}
		return [
			'constraints' => $constraints,
			'keywords' => $keywords,
			'expanded_query' => $expanded,
			'exclude_query' => $exclude,
			'confidence' => (float)$confidence,
			'reason' => $reason,
		];
	}

	/**
	 * @return array<int, string>
	 * @throws ManticoreSearchClientError
	 */
	private function readStringList(mixed $value, string $field): array {
		if (!is_array($value)) {
			throw ManticoreSearchClientError::create("Research tool returned invalid $field");
		}

		$items = [];
		foreach ($value as $item) {
			if (!is_string($item)) {
				throw ManticoreSearchClientError::create("Research tool returned invalid $field");
			}
			$item = trim($item);
			if ($item === '') {
				continue;
			}

			$items[] = $item;
		}

		return $items;
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
