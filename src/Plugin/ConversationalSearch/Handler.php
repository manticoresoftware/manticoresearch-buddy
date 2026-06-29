<?php declare(strict_types=1);

/*
 Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

use JsonException;
use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
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

/**
 * This class handles all ConversationalSearch plugin operations
 * including model management and conversation processing
 */
final class Handler extends BaseHandlerWithClient {
	public const float RESPONSE_TEMPERATURE = 0.1;
	private const int RESPONSE_MAX_TOKENS = 4096;
	private const float RESPONSE_TOP_P = 1.0;
	private const float RESPONSE_FREQUENCY_PENALTY = 0.0;
	private const float RESPONSE_PRESENCE_PENALTY = 0.0;
	private const string TABLE_IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/';

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
			$conversationRouter = new ConversationRouter();
			$searchEngine = new SearchEngine($client);

			// Route to appropriate handler based on action
			return match ($payload->action) {
				Payload::ACTION_CREATE_MODEL => self::createModel($payload, $modelManager, $client),
				Payload::ACTION_SHOW_MODELS => self::showModels($modelManager, $client),
				Payload::ACTION_DESCRIBE_MODEL => self::describeModel($payload, $modelManager, $client),
				Payload::ACTION_DROP_MODEL => self::dropModel($payload, $modelManager, $client),
				Payload::ACTION_CONVERSATION => self::handleConversation(
					$payload, $modelManager, $provider,
					$conversationRouter, $searchEngine, $client
				),
				default => throw QueryParseError::create("Unknown action: $payload->action")
			};
		};

		return Task::create($taskFn, [$this->payload, $this->manticoreClient, $this->llmProvider])->run();
	}

	/**
	 * Create a new chat model
	 *
	 * @param Payload $payload
	 * @param ModelManager $modelManager
	 * @param Client $client
	 *
	 * @return TaskResult
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError|QueryParseError
	 */
	private static function createModel(
		Payload $payload,
		ModelManager $modelManager,
		Client $client
	): TaskResult {
		/** @var array{identifier: string, model: string, description?: string,
		 *   api_key?: string, base_url?: string, timeout?: string|int, retrieval_limit?: string|int,
		 *   max_document_length?: string|int, custom_prompt?: string} $config
		 */
		$config = $payload->params;
		$createConfig = (new ModelConfigValidator())->validate($config);

		// Create model
		$modelName = $modelManager->createModel($client, $createConfig);

		return TaskResult::withRow(['name' => $modelName])
			->column('name', Column::String);
	}

	/**
	 * Show all chat models
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
				'name' => $model['name'],
				'model' => $model['model'],
				'created_at' => $model['created_at'],
			];
		}

		return TaskResult::withData($data)
			->column('name', Column::String)
			->column('model', Column::String)
			->column('created_at', Column::Long);
	}

	/**
	 * Describe a specific chat model
	 *
	 * @param Payload $payload
	 * @param ModelManager $modelManager
	 * @param Client $client
	 *
	 * @return TaskResult
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private static function describeModel(Payload $payload, ModelManager $modelManager, Client $client): TaskResult {
		$modelName = $payload->params['model_name'];

		$tableName = ResourceTable::name(ResourceTable::RESOURCE_CHAT_MODEL, $modelName);
		if (!$client->hasTable($tableName)) {
			throw ManticoreSearchClientError::create("chat model '$modelName' not found");
		}
		$model = $modelManager->getModel($client, $modelName);

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
	 * Drop a chat model
	 *
	 * @param Payload $payload
	 * @param ModelManager $modelManager
	 * @param Client $client
	 *
	 * @return TaskResult
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 * @throws QueryParseError
	 */
	private static function dropModel(Payload $payload, ModelManager $modelManager, Client $client): TaskResult {
		$modelName = $payload->params['model_name'];
		$ifExists = ($payload->params['if_exists'] ?? '') === '1';

		$modelManager->deleteModel(
			$client,
			$modelName,
			$ifExists
		);
		return TaskResult::none();
	}

	/**
	 * Handle conversation (CALL CHAT)
	 *
	 * @param Payload $payload
	 * @param ModelManager $modelManager
	 * @param LlmProvider $provider
	 * @param ConversationRouter $conversationRouter
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
		ConversationRouter $conversationRouter,
		SearchEngine $searchEngine,
		Client $client
	): TaskResult {
		$request = self::parseCallChatParams($payload);
		if (empty($request->conversationUuid)) {
			$request = $request->withConversationUuid(self::generateUuid());
		}

		$conversationUuid = $request->conversationUuid;
		self::validateTable($client, $request->table);
		$model = $modelManager->getModel($client, $request->modelName);
		$conversationManager = new ConversationManager($client, $model['name']);
		$services = new SearchServices($conversationManager, $provider, $searchEngine);

		$conversationHistory = $conversationManager->getConversationMessages($conversationUuid);
		self::logConversationStart($conversationUuid, $conversationHistory);

		$route = $conversationRouter->route($request->query, $conversationHistory, $provider, $model);
		Buddy::debugv("Chat: ├─ Route selected: $route->route");

		/** @var array{max_document_length:int} $settings */
		$settings = $model['settings'];
		$vectorFieldInfo = $searchEngine->inspectVectorFieldInfo($request->table, $request->fields);
		$searchContext = new SearchContext(
			$request,
			$conversationHistory,
			$model,
			$route,
			$vectorFieldInfo
		);
		[$searchResults, $queries, $excludedIds] = self::performSearch(
			$searchContext, $services
		);

		self::logPreprocessingResults($request, $route, $queries);
		$maxDocumentLength = $settings['max_document_length'];
		$context = '';
		if ($searchResults !== []) {
			$context = self::buildContext($searchResults, $vectorFieldInfo->sourceFields, $maxDocumentLength);
		}
		self::logContextBuilding($searchResults, $context, $maxDocumentLength);
		$response = self::generateResponse(
			$model,
			$request->query,
			$context,
			$conversationHistory,
			$provider
		);

		if (!$response['success']) {
			return TaskResult::withError(
				LlmProvider::formatFailureMessage('LLM response generation failed', $response)
			);
		}

		$responseText = $response['content'];
		$tokensUsed = $response['metadata']['tokens_used'];

		$turn = new ConversationTurn(
			$route->route,
			$queries,
			$excludedIds,
			$responseText,
			$tokensUsed
		);
		self::saveConversationMessages(
			$conversationManager, $request, $model['name'], $turn
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
	 * @throws ManticoreSearchClientError
	 */
	private static function validateTable(Client $client, string $table): void {
		if (preg_match(self::TABLE_IDENTIFIER_PATTERN, $table) !== 1) {
			throw ManticoreSearchClientError::create('Invalid table identifier');
		}

		if (!$client->hasTable($table)) {
			throw ManticoreSearchClientError::create("Table '$table' not found");
		}
	}

	/**
	 * @param Payload $payload
	 *
	 * @return ConversationRequest
	 */
	private static function parseCallChatParams(Payload $payload): ConversationRequest {
		// Parse CALL CHAT parameters from payload
		return new ConversationRequest(
			$payload->params['query'] ?? '',
			$payload->params['table'] ?? '',
			$payload->params['model_name'] ?? '',
			$payload->params['conversation_uuid'] ?? '',
			$payload->params['fields'] ?? ''
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
	 * @param ConversationHistory $conversationHistory
	 *
	 * @return void
	 */
	private static function logConversationStart(
		string $conversationUuid,
		ConversationHistory $conversationHistory
	): void {
		Buddy::debugv("\nChat: [DEBUG CONVERSATION FLOW]");
		Buddy::debugv('Chat: ├─ Starting conversation processing');
		Buddy::debugv("Chat: ├─ Conversation UUID: $conversationUuid");
		Buddy::debugv('Chat: ├─ Retrieved history for conversation routing');
		Buddy::debugv('Chat: ├─ History turns: ' . sizeof($conversationHistory->payload()));
	}

	/**
	 * Perform search based on conversation route.
	 *
	 * @param SearchContext $context
	 * @param SearchServices $services
	 *
	 * @return array{array<int, array<string, mixed>>, array{search_query:string, exclude_query:string},
	 *   array<int, string|int>}
	 * @throws ManticoreSearchResponseError
	 *
	 * @throws ManticoreSearchClientError
	 */
	private static function performSearch(
		SearchContext $context,
		SearchServices $services
	): array {
		if ($context->route->route === ConversationRoute::ANSWER_FROM_HISTORY) {
			return self::reuseLatestSearchSources($context, $services);
		}

		if ($context->route->route !== ConversationRoute::SEARCH) {
			return [
				[],
				[
					'search_query' => '',
					'exclude_query' => '',
				],
				[],
			];
		}

		return self::handleSearchIntent(
			$context, $services
		);
	}

	/**
	 * @return array{array<int, array<string, mixed>>, array{search_query:string, exclude_query:string},
	 *   array<int, string|int>}
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private static function reuseLatestSearchSources(
		SearchContext $context,
		SearchServices $services
	): array {
		$lastContext = $context->history->latestSearchContext();
		if (!$lastContext) {
			return [
				[],
				[
					'search_query' => '',
					'exclude_query' => '',
				],
				[],
			];
		}

		$queries = [
			'search_query' => $lastContext['search_query'],
			'exclude_query' => $lastContext['exclude_query'],
		];
		$excludedIds = self::decodeStoredExcludedIds($lastContext['excluded_ids']);
		$searchResults = $services->searchEngine->search(
			$context->request->table,
			$queries['search_query'],
			$excludedIds,
			$context->model,
			0.8,
			$context->vectorFieldInfo
		);

		return [$searchResults, $queries, $excludedIds];
	}

	/**
	 * @return array<int, string|int>
	 */
	private static function decodeStoredExcludedIds(string $excludedIds): array {
		if ($excludedIds === '') {
			return [];
		}

		/** @var array<int, string|int> $decodedExcludedIds */
		$decodedExcludedIds = simdjson_decode($excludedIds, true);
		return $decodedExcludedIds;
	}

	/**
	 * Handle routes that require a new search.
	 *
	 * @param SearchContext $context
	 * @param SearchServices $services
	 *
	 * @return array{array<int, array<string, mixed>>, array{search_query:string, exclude_query:string},
	 *   array<int, string|int>}
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 *
	 */
	private static function handleSearchIntent(
		SearchContext $context,
		SearchServices $services
	): array {
		Buddy::debugv("Chat: ├─ Processing route: {$context->route->route}");
		Buddy::debugv('Chat: ├─ Using structured conversation history for query generation');
		$historyPayload = $context->history->payload();
		Buddy::debugv('Chat: ├─ Query history turns: ' . sizeof($historyPayload));

		$excludeQuery = $context->route->excludeQuery;
		$queries = [
			'search_query' => $context->route->standaloneQuestion,
			'exclude_query' => $excludeQuery,
		];

		$excludedIds = [];
		if ($queries['exclude_query'] !== '') {
			$excludedIds = $services->searchEngine->getExcludedIds(
				$context->request->table, $queries['exclude_query'], $context->vectorFieldInfo->name
			);
		}

		$searchResults = $services->searchEngine->search(
			$context->request->table,
			$queries['search_query'],
			$excludedIds,
			$context->model,
			0.8,
			$context->vectorFieldInfo
		);

		return [$searchResults, $queries, $excludedIds];
	}

	/**
	 * Log preprocessing results
	 *
	 * @param ConversationRequest $request
	 * @param ConversationRoute $route
	 * @param array{search_query:string, exclude_query:string} $queries
	 *
	 * @return void
	 */
	private static function logPreprocessingResults(
		ConversationRequest $request,
		ConversationRoute $route,
		array $queries
	): void {
		Buddy::debugv('Chat: [DEBUG PREPROCESSING]');
		Buddy::debugv("Chat: ├─ User query: '$request->query'");
		Buddy::debugv("Chat: ├─ Route: $route->route");
		Buddy::debugv("Chat: ├─ Search query: '{$queries['search_query']}'");
		Buddy::debugv("Chat: └─ Exclude query: '{$queries['exclude_query']}'");
	}

	/**
	 * @param array<int, array<string, mixed>> $searchResults
	 * @param string $contentFields
	 * @param int $maxDocumentLength
	 *
	 * @return string
	 * @throws JsonException
	 */
	private static function buildContext(
		array $searchResults,
		string $contentFields,
		int $maxDocumentLength
	): string {
		return (new SourceContextBuilder())->build($searchResults, $contentFields, $maxDocumentLength);
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
		Buddy::debugv('Chat: [DEBUG CONTEXT]');
		Buddy::debugv('Chat: ├─ Documents count: ' . sizeof($searchResults));
		Buddy::debugv('Chat: ├─ Total context length: ' . strlen($context) . ' chars');
		$maxDocumentLengthLabel = $maxDocumentLength === 0 ? 'unlimited' : (string)$maxDocumentLength;
		Buddy::debugv("Chat: └─ Max doc length: $maxDocumentLengthLabel chars");
	}

	/**
	 * @param array{
	 *   id:string,
	 *   name:string,
	 *   model:string,
	 *   settings:array<string, mixed>,
	 *   created_at:string,
	 *   updated_at:string
	 * } $model
	 * @param string $query
	 * @param string $context
	 * @param ConversationHistory $history
	 * @param LlmProvider $provider
	 *
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
	private static function generateResponse(
		array $model,
		string $query,
		string $context,
		ConversationHistory $history,
		LlmProvider $provider
	): array {
		$provider->configure($model);

		$customPrompt = $model['settings']['custom_prompt'] ?? null;
		$prompt = self::buildPrompt(
			$query,
			$context,
			$history->payload(),
			is_string($customPrompt) ? $customPrompt : ''
		);
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

	/**
	 * @param array<string, array{user?: string, assistant?: string}> $history
	 *
	 * @throws JsonException
	 */
	private static function buildPrompt(
		string $query,
		string $context,
		array $history,
		string $customPrompt = ''
	): string {
		$historyJson = (string)json_encode($history, JSON_THROW_ON_ERROR);
		$systemPrompt = trim($customPrompt) !== ''
			? trim($customPrompt)
			: "You are a context-only answer writer.\n\n"
				. 'Answer using only the provided context. Do not use outside knowledge, memory, assumptions, '
				. "or unsupported facts.\n"
				. 'Keep the answer concise and under ' . self::RESPONSE_MAX_TOKENS . " tokens.\n"
				. "If the context is insufficient, answer exactly:\n"
				. 'I don’t have enough information in the provided context to answer.';

		return "system:\n"
			. $systemPrompt . "\n\n"
			. "user:\n"
			. "Query: $query\n\n"
			. "History:\n```json\n$historyJson\n```\n"
			. "Context:\n```json\n$context\n```";
	}

	/**
	 * Save conversation messages
	 *
	 * @param ConversationManager $conversationManager
	 * @param ConversationRequest $request
	 * @param string $modelName
	 * @param ConversationTurn $turn
	 *
	 * @return void
	 * @throws ManticoreSearchClientError|JsonException
	 *
	 */
	private static function saveConversationMessages(
		ConversationManager $conversationManager,
		ConversationRequest $request,
		string $modelName,
		ConversationTurn $turn
	): void {
		$conversationUuid = $request->conversationUuid;
		if ($turn->route === ConversationRoute::ANSWER_FROM_HISTORY) {
			$conversationManager->saveMessage(
				$conversationUuid,
				$modelName,
				ConversationMessage::user($request->query, $turn->route)
			);
		} else {
			$stringExcludedIds = array_map('strval', $turn->excludedIds);
			$conversationManager->saveMessage(
				$conversationUuid,
				$modelName,
				ConversationMessage::userWithExcludedIds(
					$request->query,
					$turn->route,
					$turn->queries['search_query'],
					$turn->queries['exclude_query'],
					$stringExcludedIds
				)
			);
		}

		Buddy::debugv('Chat: ├─ Saving assistant response');
		Buddy::debugv("Chat: ├─ Assistant route: $turn->route");
		Buddy::debugv('Chat: ├─ Response length: ' . strlen($turn->responseText) . ' chars');
		Buddy::debugv("Chat: ├─ Tokens used: $turn->tokensUsed");

		$conversationManager->saveMessage(
			$conversationUuid,
			$modelName,
			ConversationMessage::assistant($turn->responseText, $turn->route),
			$turn->tokensUsed
		);

		Buddy::debugv('Chat: └─ Conversation processing completed');
	}


}
