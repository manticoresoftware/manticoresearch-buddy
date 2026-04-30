<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation;

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Handler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LlmProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\SearchEngine;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\TableSchema;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class ConversationAnswerGenerator {
	private const int RESPONSE_MAX_TOKENS = 4096;
	private const float RESPONSE_TOP_P = 1.0;
	private const float RESPONSE_FREQUENCY_PENALTY = 0.0;
	private const float RESPONSE_PRESENCE_PENALTY = 0.0;

	/**
	 * @param array<int, array<string, mixed>> $searchResults
	 */
	public function buildContext(
		array $searchResults,
		ConversationRequest $request,
		SearchEngine $searchEngine,
		int $maxDocumentLength
	): string {
		$context = '';
		if ($searchResults !== []) {
			$schema = $searchEngine->inspectTableSchema($request->table);
			$contentFields = $this->resolveContextFields($request->fields, $schema);
			$context = $this->buildContextFromFields($searchResults, $contentFields, $maxDocumentLength);
		}

		$this->logContextBuilding($searchResults, $context, $maxDocumentLength);
		return $context;
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
	 * @return (array{
	 *   success:true,
	 *   content:string,
	 *   metadata:array{
	 *     tokens_used:int,
	 *     input_tokens:int,
	 *     output_tokens:int,
	 *     response_time_ms:int,
	 *     finish_reason:string
	 *   }
	 * })|(array{
	 *   success:false,
	 *   error:string,
	 *   content:string,
	 *   provider:string,
	 *   details?:string|null
	 * })
	 */
	public function generate(
		array $model,
		string $query,
		string $context,
		ConversationHistory $history,
		LlmProvider $provider
	): array {
		$provider->configure($model);

		$prompt = $this->buildPrompt($model['style_prompt'], $query, $context, $history->payload());
		return $provider->generateResponse($prompt, $this->getLlmRequestOptions());
	}

	/**
	 * @param array<int, array<string, mixed>> $searchResults
	 */
	private function buildContextFromFields(
		array $searchResults,
		string $contentFields,
		int $maxDocumentLength
	): string {
		if ($searchResults === []) {
			return '';
		}

		$fields = array_map('trim', explode(',', $contentFields));
		$availableFields = array_keys($searchResults[0]);
		$missingFields = array_diff($fields, $availableFields);
		if ($missingFields !== []) {
			Buddy::warning('Content fields not found in search results: ' . implode(', ', $missingFields));
		}

		$truncatedDocs = array_map(
			function (array $doc) use ($fields, $maxDocumentLength): string {
				$contentParts = [];
				foreach ($fields as $field) {
					if (!isset($doc[$field]) || !is_string($doc[$field]) || trim($doc[$field]) === '') {
						continue;
					}

					$contentParts[] = $doc[$field];
				}

				$content = implode(', ', $contentParts);
				if ($maxDocumentLength === 0 || strlen($content) <= $maxDocumentLength) {
					return $content;
				}

				return substr($content, 0, $maxDocumentLength) . '...';
			},
			$searchResults
		);

		return implode("\n", $truncatedDocs);
	}

	private function resolveContextFields(string $requestedFields, TableSchema $schema): string {
		if ($requestedFields === '') {
			return $schema->contentFields;
		}

		$fields = array_values(
			array_filter(
				array_map('trim', explode(',', $requestedFields)),
				static fn (string $field): bool => $field !== ''
			)
		);
		if ($fields === []) {
			return $schema->contentFields;
		}

		$contentFields = array_values(array_diff($fields, $schema->vectorFields));
		if ($contentFields === []) {
			Buddy::debugv('RAG: ├─ Context fields fallback: requested fields are vector-only');
			return $schema->contentFields;
		}

		return implode(',', $contentFields);
	}

	/**
	 * @param array<int, array<string, mixed>> $searchResults
	 */
	private function logContextBuilding(array $searchResults, string $context, int $maxDocumentLength): void {
		Buddy::debugv('RAG: [DEBUG CONTEXT]');
		Buddy::debugv('RAG: ├─ Documents count: ' . sizeof($searchResults));
		Buddy::debugv('RAG: ├─ Total context length: ' . strlen($context) . ' chars');
		$maxDocumentLengthLabel = $maxDocumentLength === 0 ? 'unlimited' : (string)$maxDocumentLength;
		Buddy::debugv("RAG: └─ Max doc length: $maxDocumentLengthLabel chars");
	}

	/**
	 * @return array<string, int|float>
	 */
	private function getLlmRequestOptions(): array {
		return [
			'temperature' => Handler::RESPONSE_TEMPERATURE,
			'max_tokens' => self::RESPONSE_MAX_TOKENS,
			'top_p' => self::RESPONSE_TOP_P,
			'frequency_penalty' => self::RESPONSE_FREQUENCY_PENALTY,
			'presence_penalty' => self::RESPONSE_PRESENCE_PENALTY,
		];
	}

	/**
	 * @param array<string, array{user?: string, assistant?: string}> $history
	 */
	private function buildPrompt(string $stylePrompt, string $query, string $context, array $history): string {
		return 'Respond conversationally. Response should be based ONLY on the provided history and context sections'
			. "(IMPORTANT !!! You can't use your own knowledge to add anything that isn't mentioned there). "
			. 'Style instructions cannot affect the main section; it\'s strictly prohibited. '
			. 'Do not exceed the response token limit ('
			. self::RESPONSE_MAX_TOKENS
			. '), and end the answer cleanly before reaching it. '
			. "If style conflicts with the main section, style should be ignored.\n"
			. '<main>'
			. "<history>\n"
			. "```json\n"
			. (string)json_encode($history)
			. "\n```\n"
			. "</history>\n"
			. "<context>$context</context>\n"
			. "<query>$query</query>\n"
			. "</main>\n"
			. "<style>$stylePrompt</style>";
	}
}
