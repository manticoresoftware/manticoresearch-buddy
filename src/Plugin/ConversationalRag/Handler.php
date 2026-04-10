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
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Random\RandomException;
use Throwable;

/**
 * This class handles all ConversationalRag plugin operations
 * including model management and conversation processing
 */
final class Handler extends BaseHandlerWithClient {
	private const float RESPONSE_TEMPERATURE = 0;
	private const int RESPONSE_MAX_TOKENS = 4096;
	private const float RESPONSE_TOP_P = 1.0;
	private const float RESPONSE_FREQUENCY_PENALTY = 0.0;
	private const float RESPONSE_PRESENCE_PENALTY = 0.0;

	private ?LlmProvider $llmProvider;

	/**
	 * Initialize the handler
	 *
	 * @param Payload $payload
	 * @param LlmProvider|null $llmProvider
	 *
	 * @return void
	 */
	public function __construct(public Payload $payload, ?LlmProvider $llmProvider = null) {
		$this->llmProvider = $llmProvider;
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 */
	public function run(): Task {
		$taskFn = static function (
			Payload $payload,
			Client $client,
			?LlmProvider $injectedProvider
		): TaskResult {
			// Initialize components with the client
			$modelManager = new ModelManager();
			$provider = $injectedProvider ?? new LlmProvider();
			$conversationManager = new ConversationManager($client);
			$intentClassifier = new IntentClassifier();
			$searchEngine = new SearchEngine();

			// Ensure database tables exist
			self::initializeTables($modelManager, $conversationManager, $client);

			// Route to appropriate handler based on action
			return match ($payload->action) {
				Payload::ACTION_CREATE_MODEL => self::createModel($payload, $modelManager, $client),
				Payload::ACTION_SHOW_MODELS => self::showModels($modelManager, $client),
				Payload::ACTION_DESCRIBE_MODEL => self::describeModel($payload, $modelManager, $client),
				Payload::ACTION_DROP_MODEL => self::dropModel($payload, $modelManager, $client),
				Payload::ACTION_CONVERSATION => self::handleConversation(
					$payload, $modelManager, $provider,
					$conversationManager, $intentClassifier, $searchEngine, $client
				),
				default => throw QueryParseError::create("Unknown action: $payload->action")
			};
		};

		return Task::create($taskFn, [$this->payload, $this->manticoreClient, $this->llmProvider])->run();
	}

	/**
	 * Initialize database tables if they don't exist
	 *
	 * @param ModelManager $modelManager
	 * @param ConversationManager $conversationManager
	 * @param Client $client
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private static function initializeTables(
		ModelManager $modelManager,
		ConversationManager $conversationManager,
		Client $client
	): void {
		$modelManager->initializeTables($client);
		$conversationManager->initializeTable($client);
	}

	/**
	 * Create a new RAG model
	 *
	 * @param Payload $payload
	 * @param ModelManager $modelManager
	 * @param Client $client
	 *
	 * @return TaskResult
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError|QueryParseError
	 * @throws RandomException
	 */
	private static function createModel(
		Payload $payload,
		ModelManager $modelManager,
		Client $client
	): TaskResult {
		/** @var array{identifier: string, model: string, description?: string, style_prompt?: string,
		 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
		 *   max_document_length?: string|int} $config
		 */
		$config = $payload->params;
		$createConfig = (new ModelConfigValidator())->validate($config);

		// Create model
		$uuid = $modelManager->createModel($client, $createConfig);

		return TaskResult::withRow(['uuid' => $uuid])
			->column('uuid', Column::String);
	}

	/**
	 * Show all RAG models
	 *
	 * @param ModelManager $modelManager
	 * @param Client $client
	 *
	 * @return TaskResult
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function showModels(ModelManager $modelManager, Client $client): TaskResult {
		$models = $modelManager->getAllModels($client);

		$data = [];
		foreach ($models as $model) {
			$data[] = [
				'uuid' => $model['uuid'],
				'name' => $model['name'],
				'model' => $model['model'],
				'created_at' => $model['created_at'],
			];
		}

		return TaskResult::withData($data)
			->column('uuid', Column::String)
			->column('name', Column::String)
			->column('model', Column::String)
			->column('created_at', Column::String);
	}

	/**
	 * Describe a specific RAG model
	 *
	 * @param Payload $payload
	 * @param ModelManager $modelManager
	 * @param Client $client
	 *
	 * @return TaskResult
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private static function describeModel(Payload $payload, ModelManager $modelManager, Client $client): TaskResult {
		$modelNameOrUuid = $payload->params['model_name_or_uuid'];
		$model = $modelManager->getModelByUuidOrName($client, $modelNameOrUuid);

		$data = [];
		foreach ($model as $key => $value) {
			if (is_array($value)) { // Settings key
				/** @var array<string, scalar> $value */
				foreach ($value as $setting => $settingValue) {
					if ($setting === 'api_key') {
						$settingValue = 'HIDDEN';
					}

					$data[] = [
						'property' => "settings.$setting",
						'value' => (string)$settingValue,
					];
				}
			} else {
				$data[] = [
					'property' => $key,
					'value' => (string)$value,
				];
			}
		}


		return TaskResult::withData($data)
			->column('property', Column::String)
			->column('value', Column::String);
	}

	/**
	 * Drop a RAG model
	 *
	 * @param Payload $payload
	 * @param ModelManager $modelManager
	 * @param Client $client
	 *
	 * @return TaskResult
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private static function dropModel(Payload $payload, ModelManager $modelManager, Client $client): TaskResult {
		$modelNameOrUuid = $payload->params['model_name_or_uuid'];
		$ifExists = ($payload->params['if_exists'] ?? '') === '1';

		$modelManager->deleteModelByUuidOrName(
			$client,
			$modelNameOrUuid,
			$ifExists
		);
		return TaskResult::none();
	}

	/**
	 * Handle conversation (CALL CONVERSATIONAL_RAG)
	 *
	 * @param Payload $payload
	 * @param ModelManager $modelManager
	 * @param LlmProvider $provider
	 * @param ConversationManager $conversationManager
	 * @param IntentClassifier $intentClassifier
	 * @param SearchEngine $searchEngine
	 * @param Client $client
	 *
	 * @return TaskResult
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError|JsonException
	 * @throws RandomException
	 */
	private static function handleConversation(
		Payload $payload,
		ModelManager $modelManager,
		LlmProvider $provider,
		ConversationManager $conversationManager,
		IntentClassifier $intentClassifier,
		SearchEngine $searchEngine,
		Client $client
	): TaskResult {
		$request = self::parseCallRagParams($payload);
		if (empty($request->conversationUuid)) {
			$request = $request->withConversationUuid(self::generateUuid());
		}

		$conversationUuid = $request->conversationUuid;
		$model = $modelManager->getModelByUuidOrName($client, $request->modelUuid);
		$services = new SearchServices($conversationManager, $provider, $searchEngine, $client);

		self::logConversationStart($conversationUuid);
		$conversationHistory = $conversationManager->getConversationHistory($conversationUuid);
		self::logConversationHistory($conversationHistory);

		$intent = self::classifyIntent(
			$intentClassifier, $request->query, $conversationHistory, $provider, $model
		);

		/** @var array{max_document_length:int} $settings */
		$settings = $model['settings'];
		$searchContext = new SearchContext($intent, $request, $conversationHistory, $model);
		[$effectiveIntent, $searchResults, $queries, $excludedIds] = self::performSearch(
			$searchContext, $services
		);

		self::logPreprocessingResults($request, $intent, $queries);
		$maxDocumentLength = $settings['max_document_length'];
		$context = self::buildContext($searchResults, $request->contentFields, $maxDocumentLength);
		self::logContextBuilding($searchResults, $context, $maxDocumentLength);
		$response = self::generateResponse(
			$model, $request->query, $context, $conversationHistory, $provider
		);

		if (!$response['success']) {
			return TaskResult::withError('LLM request failed: ' . ($response['error'] ?? 'Unknown error'));
		}

		$responseText = $response['content'];
		$tokensUsed = $response['metadata']['tokens_used'] ?? 0;

		$turn = new ConversationTurn($effectiveIntent, $queries, $excludedIds, $responseText, $tokensUsed);
		self::saveConversationMessages(
			$conversationManager, $request, $model['uuid'], $turn
		);

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
	 * @param Payload $payload
	 *
	 * @return ConversationRequest
	 */
	private static function parseCallRagParams(Payload $payload): ConversationRequest {
		// Parse CALL CONVERSATIONAL_RAG parameters from payload
		return new ConversationRequest(
			$payload->params['query'] ?? '',
			$payload->params['table'] ?? '',
			$payload->params['model_uuid'] ?? '',
			$payload->params['content_fields'],
			$payload->params['conversation_uuid'] ?? ''
		);
	}

	/**
	 * Generate a UUID v4
	 *
	 * @return string
	 * @throws RandomException
	 */
	private static function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
		$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant 10
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Log conversation start
	 *
	 * @param string $conversationUuid
	 *
	 * @return void
	 */
	private static function logConversationStart(string $conversationUuid): void {
		Buddy::debugv("\nRAG: [DEBUG CONVERSATION FLOW]");
		Buddy::debugv('RAG: ├─ Starting conversation processing');
		Buddy::debugv("RAG: ├─ Conversation UUID: $conversationUuid");
	}

	/**
	 * Log conversation history
	 *
	 * @param string $conversationHistory
	 *
	 * @return void
	 */
	private static function logConversationHistory(string $conversationHistory): void {
		Buddy::debugv('RAG: ├─ Retrieved history for intent classification');
		Buddy::debugv('RAG: ├─ History length: ' . strlen($conversationHistory) . ' chars');
	}

	/**
	 * Classify intent
	 *
	 * @param IntentClassifier $intentClassifier
	 * @param string $query
	 * @param string $conversationHistory
	 * @param LlmProvider $provider
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
	 *
	 * @return string
	 */
	private static function classifyIntent(
		IntentClassifier $intentClassifier,
		string $query,
		string $conversationHistory,
		LlmProvider $provider,
		array $model
	): string {
		$intent = $intentClassifier->classifyIntent(
			$query, $conversationHistory, $provider, $model
		);
		Buddy::debugv("RAG: ├─ Intent classified: $intent");
		return $intent;
	}

	/**
	 * Perform search based on intent
	 *
	 * @param SearchContext $context
	 * @param SearchServices $services
	 *
	 * @return array{string, array<int, array<string, mixed>>, array{search_query:string,
	 *   exclude_query:string}, array<int, string|int>}
	 * @throws ManticoreSearchResponseError
	 *
	 * @throws ManticoreSearchClientError
	 */
	private static function performSearch(
		SearchContext $context,
		SearchServices $services
	): array {
		if ($context->intent === Intent::CONTENT_QUESTION) {
			return self::handleContentQuestionIntent(
				$context, $services
			);
		}

		return self::handleQueryGeneratingIntent(
			$context, $services
		);
	}

	/**
	 * Handle CONTENT_QUESTION intent
	 *
	 * @param SearchContext $context
	 * @param SearchServices $services
	 *
	 * @return array{string, array<int, array<string, mixed>>, array{search_query: string,
	 *   exclude_query: string}, array<int, string|int>}
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function handleContentQuestionIntent(
		SearchContext $context,
		SearchServices $services
	): array {
		Buddy::debugv('RAG: ├─ Processing CONTENT_QUESTION intent');
		$lastContext = $services->conversationManager->getLatestSearchContext($context->request->conversationUuid);

		if ($lastContext) {
			Buddy::debugv('RAG: ├─ Found previous search context to reuse');
			$queries = [
				'search_query' => $lastContext['search_query'],
				'exclude_query' => $lastContext['exclude_query'],
			];

			$thresholdManager = new DynamicThresholdManager();
			$thresholdInfo = $thresholdManager->calculateDynamicThreshold(
				$context->request->conversationUuid,
				$context->request->query,
				$context->conversationHistory,
				$services->conversationManager,
				$services->provider,
				$context->model
			);

			$rawExcludedIds = trim($lastContext['excluded_ids']);
			if ($rawExcludedIds === '' || strtoupper($rawExcludedIds) === 'NULL') {
				$excludedIds = [];
			} else {
				try {
					$excludedIds = simdjson_decode($rawExcludedIds, true);
				} catch (Throwable $e) {
					throw ManticoreSearchClientError::create('Invalid excluded_ids JSON: ' . $e->getMessage());
				}
			}
			if (!is_array($excludedIds)) {
				throw ManticoreSearchClientError::create('Excluded IDs must be an array');
			}

			$searchResults = $services->searchEngine->performSearchWithExcludedIds(
				$services->client, $context->request->table, $queries['search_query'], $excludedIds,
				$context->model, $thresholdInfo['threshold']
			);
			Buddy::debugv('RAG: ├─ CONTENT_QUESTION performed KNN search with previous query parameters');
			return [$context->intent, $searchResults, $queries, $excludedIds];
		}

		Buddy::debugv('RAG: ├─ No previous search context found, falling back to NEW_SEARCH');
		// Fallback to NEW_SEARCH logic
		return self::handleQueryGeneratingIntent(
			$context->withIntent(Intent::NEW_SEARCH), $services
		);
	}

	/**
	 * Handle query-generating intents
	 *
	 * @param SearchContext $context
	 * @param SearchServices $services
	 *
	 * @return array{string, array<int, array<string, mixed>>, array{search_query:string,
	 *   exclude_query:string}, array<int, string|int>}
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 *
	 */
	private static function handleQueryGeneratingIntent(
		SearchContext $context,
		SearchServices $services
	): array {
		Buddy::debugv("RAG: ├─ Processing query-generating intent: $context->intent");
		$cleanHistory = $services->conversationManager->getConversationHistoryForQueryGeneration(
			$context->request->conversationUuid
		);

		Buddy::debugv('RAG: ├─ Using filtered history for query generation');
		Buddy::debugv('RAG: ├─ Clean history length: ' . strlen($cleanHistory) . ' chars');

		$intentClassifierInstance = new IntentClassifier();
		$queries = $intentClassifierInstance->generateQueries(
			$context->request->query,
			$context->intent,
			$cleanHistory,
			$services->provider,
			$context->model
		);

		$thresholdManager = new DynamicThresholdManager();
		$thresholdInfo = $thresholdManager->calculateDynamicThreshold(
			$context->request->conversationUuid,
			$context->request->query,
			$context->conversationHistory,
			$services->conversationManager,
			$services->provider,
			$context->model
		);

		$excludedIds = [];
		if (!empty($queries['exclude_query']) && $queries['exclude_query'] !== 'none') {
			$excludedIds = $services->searchEngine->getExcludedIds(
				$services->client, $context->request->table, $queries['exclude_query']
			);
		}

		$searchResults = $services->searchEngine->performSearchWithExcludedIds(
			$services->client, $context->request->table, $queries['search_query'], $excludedIds,
			$context->model, $thresholdInfo['threshold']
		);

		return [$context->intent, $searchResults, $queries, $excludedIds];
	}

	/**
	 * Log preprocessing results
	 *
	 * @param ConversationRequest $request
	 * @param string $intent
	 * @param array{search_query:string, exclude_query:string} $queries
	 *
	 * @return void
	 */
	private static function logPreprocessingResults(
		ConversationRequest $request,
		string $intent,
		array $queries
	): void {
		Buddy::debugv('RAG: [DEBUG PREPROCESSING]');
		Buddy::debugv("RAG: ├─ User query: '$request->query'");
		Buddy::debugv("RAG: ├─ Intent: $intent");
		Buddy::debugv("RAG: ├─ Search query: '{$queries['search_query']}'");
		Buddy::debugv("RAG: └─ Exclude query: '{$queries['exclude_query']}'");
	}

	/**
	 * @param array<int, array<string, mixed>> $searchResults
	 * @param string $contentFields
	 * @param int $maxDocumentLength
	 *
	 * @return string
	 */
	private static function buildContext(
		array $searchResults,
		string $contentFields,
		int $maxDocumentLength
	): string {
		if (empty($searchResults)) {
			return '';
		}

		// Parse content fields (comma-separated)
		$fields = array_map('trim', explode(',', $contentFields));
		// Validate fields exist in first result (for warning)
		if (isset($searchResults[0])) {
			$availableFields = array_keys($searchResults[0]);
			$missingFields = array_diff($fields, $availableFields);
			if (!empty($missingFields)) {
				Buddy::warning('Content fields not found in search results: ' . implode(', ', $missingFields));
			}
		}

		$truncatedDocs = array_map(
			function ($doc) use ($fields, $maxDocumentLength) {
				$contentParts = [];
				foreach ($fields as $field) {
					if (!isset($doc[$field]) || !is_string($doc[$field]) || empty(trim($doc[$field]))) {
						continue;
					}

					$contentParts[] = $doc[$field];
				}

				// Use comma + space as separator between fields
				$content = implode(', ', $contentParts);
				if ($maxDocumentLength === -1 || strlen($content) <= $maxDocumentLength) {
					return $content;
				}

				return substr($content, 0, $maxDocumentLength) . '...';
			}, $searchResults
		);

		return implode("\n", $truncatedDocs);
	}

	/**
	 * Log context building
	 *
	 * @param array<int, array<string, mixed>> $searchResults
	 * @param string $context
	 * @param int $maxDocumentLength
	 *
	 * @return void
	 */
	private static function logContextBuilding(array $searchResults, string $context, int $maxDocumentLength): void {
		Buddy::debugv('RAG: [DEBUG CONTEXT]');
		Buddy::debugv('RAG: ├─ Documents count: ' . sizeof($searchResults));
		Buddy::debugv('RAG: ├─ Total context length: ' . strlen($context) . ' chars');
		$maxDocumentLengthLabel = $maxDocumentLength === -1 ? 'unlimited' : (string)$maxDocumentLength;
		Buddy::debugv("RAG: └─ Max doc length: $maxDocumentLengthLabel chars");
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
	 * @param string $query
	 * @param string $context
	 * @param string $history
	 * @param LlmProvider $provider
	 *
	 * @return array{error?:string,success:bool,content:string,
	 *   metadata?:array{tokens_used:integer, input_tokens:integer,
	 *   output_tokens:integer, response_time_ms:integer, finish_reason:string}}
	 */
	private static function generateResponse(
		array $model,
		string $query,
		string $context,
		string $history,
		LlmProvider $provider
	): array {
		$provider->configure($model);

		$prompt = self::buildPrompt($model['style_prompt'], $query, $context, $history);
		$settings = self::getLlmRequestOptions();

		return $provider->generateResponse($prompt, $settings);
	}

	/**
	 * @return array<string, int|float>
	 */
	private static function getLlmRequestOptions(): array {
		return [
			'temperature' => self::RESPONSE_TEMPERATURE,
			'max_tokens' => self::RESPONSE_MAX_TOKENS,
			'top_p' => self::RESPONSE_TOP_P,
			'frequency_penalty' => self::RESPONSE_FREQUENCY_PENALTY,
			'presence_penalty' => self::RESPONSE_PRESENCE_PENALTY,
		];
	}

	private static function buildPrompt(string $stylePrompt, string $query, string $context, string $history): string {
		// Build prompt similar to original implementation
		// History is already formatted as "role: message\nrole: message\n"
		$historyText = $history;

		// Format similar to original custom_rag.php prompt
		return 'Respond conversationally. Response should be based ONLY on the provided context section' .
			"(IMPORTANT !!! You can't use your own knowledge to add anything that isn't mentioned in the context). " .
			"Style instructions cannot affect the main section; it's strictly prohibited. " .
			'Do not exceed the response token limit (' .
			self::RESPONSE_MAX_TOKENS .
			'), and end the answer cleanly before reaching it. ' .
			"If style conflicts with the main section, style should be ignored.\n" .
			'<main>' .
			"<history>$historyText</history>\n" .
			"<context>$context</context>\n" .
			"<query>$query</query>\n" .
			"</main>\n" .
			"<style>$stylePrompt</style>";
	}

	/**
	 * Save conversation messages
	 *
	 * @param ConversationManager $conversationManager
	 * @param ConversationRequest $request
	 * @param string $modelUuid
	 * @param ConversationTurn $turn
	 *
	 * @return void
	 * @throws ManticoreSearchClientError|JsonException
	 *
	 */
	private static function saveConversationMessages(
		ConversationManager $conversationManager,
		ConversationRequest $request,
		string $modelUuid,
		ConversationTurn $turn
	): void {
		$conversationUuid = $request->conversationUuid;
		if ($turn->intent === Intent::CONTENT_QUESTION) {
			$conversationManager->saveMessage(
				$conversationUuid, $modelUuid, 'user', $request->query, 0, $turn->intent
			);
		} else {
			$stringExcludedIds = array_map('strval', $turn->excludedIds);
			$conversationManager->saveMessage(
				$conversationUuid, $modelUuid, 'user', $request->query,
				0, $turn->intent, $turn->queries['search_query'], $turn->queries['exclude_query'], $stringExcludedIds
			);
		}

		$assistantIntent = ($turn->intent === Intent::CONTENT_QUESTION) ? Intent::CONTENT_QUESTION : null;
		Buddy::debugv('RAG: ├─ Saving assistant response');
		Buddy::debugv('RAG: ├─ Assistant intent: ' . ($assistantIntent ?? 'none'));
		Buddy::debugv('RAG: ├─ Response length: ' . strlen($turn->responseText) . ' chars');
		Buddy::debugv("RAG: ├─ Tokens used: $turn->tokensUsed");

		$conversationManager->saveMessage(
			$conversationUuid, $modelUuid, 'assistant', $turn->responseText, $turn->tokensUsed, $assistantIntent
		);

		Buddy::debugv('RAG: └─ Conversation processing completed');
	}


}
