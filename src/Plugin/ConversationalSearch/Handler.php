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
	private const string TABLE_IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?\z/';

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

		$conversationHistory = $conversationManager->getConversationMessages(
			$conversationUuid, indexName: $request->table
		);
		self::logConversationStart($conversationUuid, $conversationHistory);

		$route = $conversationRouter->route(
			$request->query, $conversationHistory, $provider, $model, $request->searchStrategy
		);
		$smallTalk = $route->route === ConversationRoute::CHAT;
		$searchResults = [];
		$excludedIds = null;
		$context = '';
		if (!$smallTalk) {
			$previousExcludedIds = $conversationHistory->excludedIds();
			$vectorFieldInfo = $searchEngine->inspectVectorFieldInfo($request->table, $request->fields);
			$searchContext = new SearchContext($request, $model, $route, $vectorFieldInfo, $previousExcludedIds);
			[$searchResults, $currentExcludedIds] = self::performSearch($searchContext, $searchEngine);
			$excludedIds = $currentExcludedIds === $previousExcludedIds ? null : $currentExcludedIds;
			self::logPreprocessingResults($request, $route);
			$maxDocumentLength = $model['settings']['max_document_length'];
			$context = (new SourceContextBuilder())->build(
				$searchResults, $vectorFieldInfo->sourceFields, $maxDocumentLength
			);
			self::logContextBuilding($searchResults, $context, $maxDocumentLength);
		}
		$response = self::generateResponse(
			$model,
			$request->query,
			$context,
			$conversationHistory,
			$provider,
			$smallTalk
		);

		if (!$response['success']) {
			return TaskResult::withError(
				LlmProvider::formatFailureMessage('LLM response generation failed', $response)
			);
		}

		$responseText = $response['content'];
		$tokensUsed = $response['metadata']['tokens_used'];

		$conversationManager->saveTurn(
			$conversationUuid, $model['name'], $request->query, $responseText, $request->table,
			$excludedIds
		);
		Buddy::debugv("Chat: ├─ Tokens used: $tokensUsed");

		return self::conversationResult($request, $responseText, $route->standaloneQuestion, $searchResults);
	}

	/**
	 * @param array<int, array<string, mixed>> $sources
	 */
	private static function conversationResult(
		ConversationRequest $request,
		string $responseText,
		string $searchQuery,
		array $sources
	): TaskResult {
		return TaskResult::withRow(
			[
				'conversation_uuid' => $request->conversationUuid,
				'user_query' => $request->query,
				'search_query' => $searchQuery,
				'response' => $responseText,
				'sources' => json_encode($sources, JSON_THROW_ON_ERROR),
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
			$payload->params['query'],
			$payload->params['table'],
			$payload->params['model_name'],
			$payload->params['conversation_uuid'] ?? '',
			$payload->params['fields'] ?? '',
			$payload->params['search_strategy'] ?? ConversationRequest::STRATEGY_KNN
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
		Buddy::debugv('Chat: ├─ Retrieved conversation history');
		Buddy::debugv('Chat: ├─ History turns: ' . sizeof($conversationHistory->payload()));
	}

	/**
	 * @param SearchContext $context
	 * @param SearchEngine $searchEngine
	 * @return array{array<int, array<string, mixed>>, list<string>}
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private static function performSearch(SearchContext $context, SearchEngine $searchEngine): array {
		$excludedIds = $context->excludedIds;
		if ($context->route->excludeQuery !== '') {
			$newIds = $searchEngine->getExcludedIds(
				$context->request->table, $context->route->excludeQuery, $context->vectorFieldInfo->name
			);
			$excludedIds = array_values(array_unique(array_merge($excludedIds, array_map('strval', $newIds))));
		}

		$searchResults = $searchEngine->search(
			$context->request->table,
			$context->route->standaloneQuestion,
			$excludedIds,
			$context->model,
			0.8,
			$context->vectorFieldInfo,
			$context->request->searchStrategy,
			$context->route->lexicalTerms
		);

		return [$searchResults, $excludedIds];
	}

	/**
	 * Log preprocessing results
	 *
	 * @param ConversationRequest $request
	 * @param ConversationRoute $route
	 *
	 * @return void
	 */
	private static function logPreprocessingResults(
		ConversationRequest $request,
		ConversationRoute $route
	): void {
		Buddy::debugv('Chat: [DEBUG PREPROCESSING]');
		Buddy::debugv("Chat: ├─ User query: '$request->query'");
		Buddy::debugv("Chat: ├─ Search query: '$route->standaloneQuestion'");
		Buddy::debugv("Chat: └─ Exclude query: '$route->excludeQuery'");
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
		LlmProvider $provider,
		bool $smallTalk = false
	): array {
		$provider->configure($model);

		$customPrompt = $model['settings']['custom_prompt'] ?? null;
		if ($smallTalk) {
			$prompt = (is_string($customPrompt) && trim($customPrompt) !== '' ? trim($customPrompt) . "\n" : '')
				. 'Respond briefly and naturally in the same language to the conversational message '
				. 'unrelated to indexed data. '
				. 'Do not answer factual questions, recommend documents, or invent search results. '
				. "<history>\n" . json_encode($history->payload(), JSON_THROW_ON_ERROR) . "\n</history>\n"
				. "<message>$query</message>";
		} else {
			$prompt = self::buildPrompt(
				$query,
				$context,
				$history->payload(),
				is_string($customPrompt) ? $customPrompt : ''
			);
		}
		$settings = self::getLlmRequestOptions();
		if ($smallTalk) {
			$settings['max_tokens'] = 128;
		}

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
		$prompt = trim($customPrompt) !== ''
			? trim($customPrompt)
			: 'Respond conversationally. Response should be based ONLY on the provided history and context sections' .
				"(IMPORTANT !!! You can't use your own knowledge to add anything that isn't mentioned there). " .
				'Do not exceed the response token limit (' .
				self::RESPONSE_MAX_TOKENS .
				'), and end the answer cleanly before reaching it.';

		return $prompt . ' ' .
			'<main>' .
			"<history>\n" .
			"```json\n" .
			$historyJson .
			"\n```\n" .
			"</history>\n" .
			"<context>$context</context>\n" .
			"<query>$query</query>\n" .
			'</main>';
	}

}
