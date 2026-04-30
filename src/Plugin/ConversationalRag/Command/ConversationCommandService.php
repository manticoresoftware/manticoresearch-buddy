<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Command;

use JsonException;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationAnswerGenerator;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationHistory;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationMessage;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationRequest;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationRoute;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationSearchFlow;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationSearchWithResearchFlow;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Conversation\ConversationTurn;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\LlmProvider;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ModelManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Payload;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\SearchEngine;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Random\RandomException;

final class ConversationCommandService {
	private const string TABLE_IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/';

	public function __construct(
		private readonly ModelManager $modelManager,
		private readonly LlmProvider $provider,
		private readonly ConversationManager $conversationManager,
		private readonly ConversationSearchFlow $searchFlow,
		private readonly ConversationSearchWithResearchFlow $searchWithResearchFlow,
		private readonly ConversationAnswerGenerator $answerGenerator,
		private readonly SearchEngine $searchEngine,
		private readonly Client $client
	) {
	}

	/**
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError|JsonException|RandomException
	 */
	public function handle(Payload $payload): TaskResult {
		$request = $this->parseCallRagParams($payload);
		if ($request->conversationUuid === '') {
			$request = $request->withConversationUuid($this->generateUuid());
		}

		$conversationUuid = $request->conversationUuid;
		$this->validateTable($request->table);
		$model = $this->modelManager->getModelByUuidOrName($this->client, $request->modelUuid);

		$conversationHistory = $this->conversationManager->getConversationMessages($conversationUuid);
		$this->logConversationStart($conversationUuid, $conversationHistory);
		$searchWithResearchEnabled = $this->isSearchWithResearchEnabled($model);
		Buddy::debugv(
			'RAG: ├─ Search with research enabled: ' . ($searchWithResearchEnabled ? 'yes' : 'no')
		);

		/** @var array{max_document_length:int} $settings */
		$settings = $model['settings'];

		$conversationFlow = $searchWithResearchEnabled ? $this->searchWithResearchFlow : $this->searchFlow;
		[$searchResults, $queries, $excludedIds, $finalAction] = $conversationFlow->retrieve(
			$request,
			$conversationHistory,
			$model,
			$this->provider,
			$this->searchEngine
		);

		$this->logPreprocessingResults($request, $finalAction, $queries);
		$context = $this->answerGenerator->buildContext(
			$searchResults,
			$request,
			$this->searchEngine,
			$settings['max_document_length']
		);
		$response = $this->answerGenerator->generate(
			$model,
			$request->query,
			$context,
			$conversationHistory,
			$this->provider
		);

		if (!$response['success']) {
			return TaskResult::withError(
				LlmProvider::formatFailureMessage('LLM response generation failed', $response)
			);
		}

		$responseText = $response['content'];
		$tokensUsed = $response['metadata']['tokens_used'];
		$turn = new ConversationTurn($finalAction, $queries, $excludedIds, $responseText, $tokensUsed);
		$this->saveConversationMessages($request, $model['uuid'], $turn);

		return TaskResult::withRow(
			[
				'conversation_uuid' => $conversationUuid,
				'user_query' => $request->query,
				'search_query' => $queries['search_query'],
				'response' => $responseText,
				'sources' => json_encode($searchResults),
			]
		)->column('conversation_uuid', Column::String)
			->column('user_query', Column::String)
			->column('search_query', Column::String)
			->column('response', Column::String)
			->column('sources', Column::String);
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private function validateTable(string $table): void {
		if (preg_match(self::TABLE_IDENTIFIER_PATTERN, $table) !== 1) {
			throw ManticoreSearchClientError::create('Invalid table identifier');
		}

		if (!$this->client->hasTable($table)) {
			throw ManticoreSearchClientError::create("Table '$table' not found");
		}
	}

	private function parseCallRagParams(Payload $payload): ConversationRequest {
		return new ConversationRequest(
			$payload->params['query'] ?? '',
			$payload->params['table'] ?? '',
			$payload->params['model_uuid'] ?? '',
			$payload->params['conversation_uuid'] ?? '',
			$payload->params['fields'] ?? ''
		);
	}

	/**
	 * @throws RandomException
	 */
	private function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	private function logConversationStart(
		string $conversationUuid,
		ConversationHistory $conversationHistory
	): void {
		Buddy::debugv("\nRAG: [DEBUG CONVERSATION FLOW]");
		Buddy::debugv('RAG: ├─ Starting conversation processing');
		Buddy::debugv("RAG: ├─ Conversation UUID: $conversationUuid");
		Buddy::debugv('RAG: ├─ Retrieved history for conversation routing');
		Buddy::debugv('RAG: ├─ History turns: ' . sizeof($conversationHistory->payload()));
	}

	/**
	 * @param array{search_query:string, exclude_query:string} $queries
	 */
	private function logPreprocessingResults(
		ConversationRequest $request,
		string $route,
		array $queries
	): void {
		Buddy::debugv('RAG: [DEBUG PREPROCESSING]');
		Buddy::debugv("RAG: ├─ User query: '$request->query'");
		Buddy::debugv("RAG: ├─ Route: $route");
		Buddy::debugv("RAG: ├─ Search query: '{$queries['search_query']}'");
		Buddy::debugv("RAG: └─ Exclude query: '{$queries['exclude_query']}'");
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
	 */
	private function isSearchWithResearchEnabled(array $model): bool {
		if (isset($model['settings']['can_research'])) {
			$value = $model['settings']['can_research'];
			return $value === 1 || $value === '1';
		}

		return false;
	}

	/**
	 * @throws ManticoreSearchClientError|JsonException
	 */
	private function saveConversationMessages(
		ConversationRequest $request,
		string $modelUuid,
		ConversationTurn $turn
	): void {
		$conversationUuid = $request->conversationUuid;
		if ($turn->route === ConversationRoute::ANSWER_FROM_HISTORY) {
			$this->conversationManager->saveMessage(
				$conversationUuid,
				$modelUuid,
				ConversationMessage::user($request->query, $turn->route)
			);
		} else {
			$stringExcludedIds = array_map('strval', $turn->excludedIds);
			$this->conversationManager->saveMessage(
				$conversationUuid,
				$modelUuid,
				ConversationMessage::userWithExcludedIds(
					$request->query,
					$turn->route,
					$turn->queries['search_query'],
					$turn->queries['exclude_query'],
					$stringExcludedIds
				)
			);
		}

		Buddy::debugv('RAG: ├─ Saving assistant response');
		Buddy::debugv("RAG: ├─ Assistant route: $turn->route");
		Buddy::debugv('RAG: ├─ Response length: ' . strlen($turn->responseText) . ' chars');
		Buddy::debugv("RAG: ├─ Tokens used: $turn->tokensUsed");

		$this->conversationManager->saveMessage(
			$conversationUuid,
			$modelUuid,
			ConversationMessage::assistant($turn->responseText, $turn->route),
			$turn->tokensUsed
		);

		Buddy::debugv('RAG: └─ Conversation processing completed');
	}
}
