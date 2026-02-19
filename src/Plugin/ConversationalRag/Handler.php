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

/**
 * This class handles all ConversationalRag plugin operations
 * including model management and conversation processing
 */
final class Handler extends BaseHandlerWithClient {

	private ?LlmProvider $llmProvider;

	/**
	 * Initialize the handler
	 *
	 * @param Payload $payload
	 * @param LlmProvider|null $llmProvider
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
				default => throw QueryParseError::create("Unknown action: {$payload->action}")
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
		/** @var array{name: string, llm_provider:string, llm_model: string,
		 *   style_prompt?: string, temperature?: string, max_tokens?: string,
		 *   k_results?: string, similarity_threshold?: string,
		 *   max_document_length?: string} $config */
		$config = $payload->params;


			self::validateModelConfig($config);

			// Create model
			$uuid = $modelManager->createModel($client, $config);

			return TaskResult::withRow(['uuid' => $uuid])
				->column('uuid', Column::String);
	}

	/**
	 * Validate model configuration
	 *
	 * @param array{llm_provider:string, llm_model: string, style_prompt?: string,
	 *   temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold?: string, max_document_length?: string} $config
	 * @return void
	 * @throws QueryParseError
	 */
	private static function validateModelConfig(array $config): void {
		self::validateRequiredFields($config);
		self::validateLlmProvider($config);
		self::validateTemperature($config);
		self::validateMaxTokens($config);
		self::validateKResults($config);
	}

	/**
	 * Validate required fields
	 *
	 * @param array{llm_provider:string, llm_model: string, style_prompt?: string,
	 *   temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold?: string, max_document_length?: string} $config
   *
	 * @return void
	 * @throws QueryParseError
	 */
	private static function validateRequiredFields(array $config): void {
		$required = ['llm_provider', 'llm_model'];

		foreach ($required as $field) {
			if (empty($config[$field])) {
				throw QueryParseError::create("Required field '{$field}' is missing or empty");
			}
		}
	}

	/**
	 * Validate LLM provider
	 *
	 * @param array{llm_provider:string, llm_model: string, style_prompt?: string,
	 *   temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold?: string, max_document_length?: string} $config
 *
	 * @return void
	 * @throws QueryParseError
	 */
	private static function validateLlmProvider(array $config): void {
		$validProviders = ['openai'];
		if (!in_array($config['llm_provider'], $validProviders)) {
			throw QueryParseError::create(
				"Invalid LLM provider: {$config['llm_provider']}. Only 'openai' is supported."
			);
		}
	}

	/**
	 * Validate temperature parameter
	 *
	 * @param array{llm_provider:string, llm_model: string, style_prompt?: string,
	 *   temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold?: string, max_document_length?: string} $config
   *
	 * @return void
	 * @throws QueryParseError
	 */
	private static function validateTemperature(array $config): void {
		if (!isset($config['temperature'])) {
			return;
		}

		$temp = (float)$config['temperature'];
		if ($temp < 0 || $temp > 2) {
			throw QueryParseError::create('Temperature must be between 0 and 2');
		}
	}

	/**
	 * Validate max_tokens parameter
	 *
	 * @param array{llm_provider:string, llm_model: string, style_prompt?: string,
	 *   temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold?: string, max_document_length?: string} $config
	 *
	 * @return void
	 * @throws QueryParseError
	 */
	private static function validateMaxTokens(array $config): void {
		if (!isset($config['max_tokens'])) {
			return;
		}

		$tokens = (int)$config['max_tokens'];
		if ($tokens < 1 || $tokens > 32768) {
			throw QueryParseError::create('max_tokens must be between 1 and 32768');
		}
	}

	/**
	 * Validate k_results parameter
	 *
	 * @param array{llm_provider:string, llm_model: string, style_prompt?: string,
	 *   temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold?: string, max_document_length?: string} $config
	 *
	 * @return void
	 * @throws QueryParseError
	 */
	private static function validateKResults(array $config): void {
		if (!isset($config['k_results'])) {
			return;
		}

		$k = (int)$config['k_results'];
		if ($k < 1 || $k > 50) {
			throw QueryParseError::create('k_results must be between 1 and 50');
		}
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
				'llm_provider' => $model['llm_provider'],
				'llm_model' => $model['llm_model'],
				'created_at' => $model['created_at'],
			];
		}

			return TaskResult::withData($data)
				->column('uuid', Column::String)
				->column('name', Column::String)
				->column('llm_provider', Column::String)
				->column('llm_model', Column::String)
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
				foreach ($value as $setting => $settingValue) {
					$data[] = [
						'property' => "settings.{$setting}",
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
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 * @return TaskResult
	 */
	private static function dropModel(Payload $payload, ModelManager $modelManager, Client $client): TaskResult {
			$modelNameOrUuid = $payload->params['model_name_or_uuid'];

			$modelManager->deleteModelByUuidOrName($client, $modelNameOrUuid);

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

		$settings = $model['settings'];
		$searchContext = new SearchContext($intent, $request, $conversationHistory, $model);
		[$searchResults, $queries, $excludedIds] = self::performSearch(
			$searchContext, $services
		);

		self::logPreprocessingResults($request, $intent, $queries);
		$context = self::buildContext($searchResults, $settings, $request->contentFields);
		self::logContextBuilding($searchResults, $context, $settings);
		$response = self::generateResponse(
			$model, $request->query, $context, $conversationHistory, $settings, $provider
		);

		if (!$response['success']) {
			return TaskResult::withError('LLM request failed: ' . ($response['error'] ?? 'Unknown error'));
		}

		$responseText = $response['content'];
		$tokensUsed = $response['metadata']['tokens_used'] ?? 0;

		$turn = new ConversationTurn($intent, $queries, $excludedIds, $responseText, $tokensUsed);
		self::saveConversationMessages(
			$conversationManager, $request, $model['uuid'], $turn
		);

		return TaskResult::withRow(
			[
				'conversation_uuid' => $conversationUuid,
				'response' => $responseText,
				'sources' => json_encode($searchResults),
			]
		)->column('conversation_uuid', Column::String)
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
			(string)($payload->params['query'] ?? ''),
			(string)($payload->params['table'] ?? ''),
			(string)($payload->params['model_uuid'] ?? ''),
			(string)$payload->params['content_fields'],
			(string)($payload->params['conversation_uuid'] ?? '')
		);
	}

	/**
	 * Generate a UUID v4
	 *
	 * @return string
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
	 * @return void
	 */
	private static function logConversationStart(string $conversationUuid): void {
		Buddy::info("\n[DEBUG CONVERSATION FLOW]");
		Buddy::info('├─ Starting conversation processing');
		Buddy::info("├─ Conversation UUID: {$conversationUuid}");
	}

	/**
	 * Log conversation history
	 *
	 * @param string $conversationHistory
	 * @return void
	 */
	private static function logConversationHistory(string $conversationHistory): void {
		Buddy::info('├─ Retrieved history for intent classification');
		Buddy::info('├─ History length: ' . strlen($conversationHistory) . ' chars');
	}

	/**
	 * Classify intent
	 *
	 * @param IntentClassifier $intentClassifier
	 * @param string $query
	 * @param string $conversationHistory
	 * @param LlmProvider $provider
	 * @param array{id:string, uuid:string, name:string,llm_provider:string,
	 *   llm_model:string,style_prompt:string,settings:array{ temperature?: string,
	 *   max_tokens?: string, k_results?: string, similarity_threshold?: string,
	 *   max_document_length?: string},created_at:string,updated_at:string} $model
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
		Buddy::info("├─ Intent classified: {$intent}");
		return $intent;
	}

	/**
	 * Perform search based on intent
	 *
	 * @param SearchContext $context
	 * @param SearchServices $services
	 * @return array{array<int, array<string, mixed>>, array{search_query:string,
	 *   exclude_query:string}, array<int, string|int>}
	 */
	private static function performSearch(
		SearchContext $context,
		SearchServices $services
	): array {
		if ($context->intent === 'CONTENT_QUESTION') {
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
	 * @return array{array<int, array<string, mixed>>, array{search_query: string,
	 *   exclude_query: string}, array<int, string|int>}
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function handleContentQuestionIntent(
		SearchContext $context,
		SearchServices $services
	): array {
		Buddy::info('├─ Processing CONTENT_QUESTION intent');
		$lastContext = $services->conversationManager->getLatestSearchContext($context->request->conversationUuid);

		if ($lastContext) {
			Buddy::info('├─ Found previous search context to reuse');
			$queries = [
				'search_query' => $lastContext['search_query'],
				'exclude_query' => $lastContext['exclude_query'],
			];

			$thresholdManager = new DynamicThresholdManager();
			$thresholdInfo = $thresholdManager->calculateDynamicThreshold(
				$context->request->query,
				$context->conversationHistory,
				$services->provider,
				$context->model
			);

			$excludedIds = simdjson_decode($lastContext['excluded_ids'], true) ?? [];
			if (!is_array($excludedIds)) {
				throw ManticoreSearchClientError::create('Excluded IDs must be an array');
			}

			$searchResults = $services->searchEngine->performSearchWithExcludedIds(
				$services->client, $context->request->table, $queries['search_query'], $excludedIds,
				$context->model, $thresholdInfo['threshold']
			);
			Buddy::info('├─ CONTENT_QUESTION performed KNN search with previous query parameters');
			return [$searchResults, $queries, $excludedIds];
		}

		Buddy::info('├─ No previous search context found, falling back to NEW_SEARCH');
		// Fallback to NEW_SEARCH logic
		return self::handleQueryGeneratingIntent(
			$context->withIntent('NEW_SEARCH'), $services
		);
	}

	/**
	 * Handle query-generating intents
	 *
	 * @param SearchContext $context
	 * @param SearchServices $services
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 *
	 * @return array{array<int, array<string, mixed>>, array{search_query:string,
	 *   exclude_query:string}, array<int, string|int>}
	 */
	private static function handleQueryGeneratingIntent(
		SearchContext $context,
		SearchServices $services
	): array {
		Buddy::info("├─ Processing query-generating intent: {$context->intent}");
		$cleanHistory = $services->conversationManager->getConversationHistoryForQueryGeneration(
			$context->request->conversationUuid
		);

		Buddy::info('├─ Using filtered history for query generation');
		Buddy::info('├─ Clean history length: ' . strlen($cleanHistory) . ' chars');

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
			$context->request->query,
			$context->conversationHistory,
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

		return [$searchResults, $queries, $excludedIds];
	}

	/**
	 * Log preprocessing results
	 *
	 * @param ConversationRequest $request
	 * @param string $intent
	 * @param array{search_query:string, exclude_query:string} $queries
	 * @return void
	 */
	private static function logPreprocessingResults(ConversationRequest $request, string $intent, array $queries): void {
		Buddy::info('[DEBUG PREPROCESSING]');
		Buddy::info("├─ User query: '{$request->query}'");
		Buddy::info("├─ Intent: $intent");
		Buddy::info("├─ Search query: '{$queries['search_query']}'");
		Buddy::info("└─ Exclude query: '{$queries['exclude_query']}'");
	}

	/**
	 * @param array<int, array<string, mixed>> $searchResults
	 * @param array{ temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold?: string, max_document_length?: string} $settings
	 * @param string $contentFields
	 *
	 * @return string
	 */
	private static function buildContext(
		array $searchResults,
		array $settings,
		string $contentFields
	): string {
		if (empty($searchResults)) {
			return '';
		}

		// Parse content fields (comma-separated)
		$fields = array_map('trim', explode(',', $contentFields));
		$maxDocLength = $settings['max_document_length'] ?? 2000;

		// Validate fields exist in first result (for warning)
		if (isset($searchResults[0])) {
			$availableFields = array_keys($searchResults[0]);
			$missingFields = array_diff($fields, $availableFields);
			if (!empty($missingFields)) {
				Buddy::warning('Content fields not found in search results: ' . implode(', ', $missingFields));
			}
		}

		$truncatedDocs = array_map(
			function ($doc) use ($fields, $maxDocLength) {
				$contentParts = [];
				foreach ($fields as $field) {
					if (!isset($doc[$field]) || !is_string($doc[$field]) || empty(trim($doc[$field]))) {
						continue;
					}

					$contentParts[] = $doc[$field];
				}

				// Use comma + space as separator between fields
				$content = implode(', ', $contentParts);
				$maxLength = (int)$maxDocLength;
				return strlen($content) > $maxLength ? substr($content, 0, $maxLength) . '...' : $content;
			}, $searchResults
		);

		return implode("\n", $truncatedDocs);
	}

	/**
	 * Log context building
	 *
	 * @param array<int, array<string, mixed>> $searchResults
	 * @param string $context
	 * @param array{ temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold?: string, max_document_length?: string} $settings
	 *
	 * @return void
	 */
	private static function logContextBuilding(array $searchResults, string $context, array $settings): void {
		Buddy::info('[DEBUG CONTEXT]');
		Buddy::info('├─ Documents count: ' . sizeof($searchResults));
		Buddy::info('├─ Total context length: ' . strlen($context) . ' chars');
		Buddy::info('└─ Max doc length: ' . ($settings['max_document_length'] ?? 2000) . ' chars');
	}

	/**
	 * @param array{id:string, uuid:string, name:string,llm_provider:string,
	 *   llm_model:string,style_prompt:string,settings:array{ temperature?: string,
	 *   max_tokens?: string, k_results?: string, similarity_threshold?: string,
	 *   max_document_length?: string},created_at:string,updated_at:string} $model
	 * @param string $query
	 * @param string $context
	 * @param string $history
	 * @param array{ temperature?: string, max_tokens?: string,
	 *   k_results?: string, similarity_threshold?: string,
	 *   max_document_length?: string} $settings
	 * @param LlmProvider $provider
	 *
	 * @return array{error?:string,success:bool,content:string,
	 *   metadata?:array{tokens_used:integer, input_tokens:integer,
	 *   output_tokens:integer, response_time_ms:integer, finish_reason:string}}
	 * @throws ManticoreSearchClientError
	 */
	private static function generateResponse(
		array $model,
		string $query,
		string $context,
		string $history,
		array $settings,
		LlmProvider $provider
	): array {
		$provider->configure($model);

		$prompt = self::buildPrompt($model['style_prompt'], $query, $context, $history);

		return $provider->generateResponse($prompt, $settings);
	}

	private static function buildPrompt(string $stylePrompt, string $query, string $context, string $history): string {
		// Build prompt similar to original implementation
		// History is already formatted as "role: message\nrole: message\n"
		$historyText = $history;

		// Format similar to original custom_rag.php prompt
		return 'Respond conversationally. Response should be based ONLY on the provided context section' .
			"(IMPORTANT !!! You can't use your own knowledge to add anything that isn't mentioned in the context). " .
			"Style instructions cannot affect the main section; it's strictly prohibited. " .
			"If style conflicts with the main section, style should be ignored.\n" .
			'<main>' .
			"<history>{$historyText}</history>\n" .
			"<context>{$context}</context>\n" .
			"<query>{$query}</query>\n" .
			"</main>\n" .
			"<style>{$stylePrompt}</style>";
	}

	/**
	 * Save conversation messages
	 *
	 * @param ConversationManager $conversationManager
	 * @param ConversationRequest $request
	 * @param string $modelUuid
	 * @param ConversationTurn $turn
	 * @throws ManticoreSearchClientError|JsonException
	 *
	 * @return void
	 */
	private static function saveConversationMessages(
		ConversationManager $conversationManager,
		ConversationRequest $request,
		string $modelUuid,
		ConversationTurn $turn
	): void {
		$conversationUuid = $request->conversationUuid;
		if ($turn->intent === 'CONTENT_QUESTION') {
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

		$assistantIntent = ($turn->intent === 'CONTENT_QUESTION') ? 'CONTENT_QUESTION' : null;
		Buddy::info('├─ Saving assistant response');
		Buddy::info('├─ Assistant intent: ' . ($assistantIntent ?? 'none'));
		Buddy::info('├─ Response length: ' . strlen($turn->responseText) . ' chars');
		Buddy::info("├─ Tokens used: {$turn->tokensUsed}");

		$conversationManager->saveMessage(
			$conversationUuid, $modelUuid, 'assistant', $turn->responseText, $turn->tokensUsed, $assistantIntent
		);

		Buddy::info('└─ Conversation processing completed');
	}


}
