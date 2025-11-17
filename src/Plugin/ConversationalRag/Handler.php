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

	private ?LLMProviderManager $llmProviderManager;

	/**
	 * Initialize the handler
	 *
	 * @param Payload $payload
	 * @param LLMProviderManager|null $llmProviderManager
	 * @return void
	 */
	public function __construct(public Payload $payload, ?LLMProviderManager $llmProviderManager = null) {
		$this->llmProviderManager = $llmProviderManager;
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
			?LLMProviderManager $injectedProviderManager
		): TaskResult {
			// Initialize components with the client
			$modelManager = new ModelManager();
			$providerManager = $injectedProviderManager ?? new LLMProviderManager();
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
					$payload, $modelManager, $providerManager,
					$conversationManager, $intentClassifier, $searchEngine, $client
				),
				default => throw QueryParseError::create("Unknown action: {$payload->action}")
			};
		};

		return Task::create($taskFn, [$this->payload, $this->manticoreClient, $this->llmProviderManager])->run();
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
	 * @param LLMProviderManager $providerManager
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
		LLMProviderManager $providerManager,
		ConversationManager $conversationManager,
		IntentClassifier $intentClassifier,
		SearchEngine $searchEngine,
		Client $client
	): TaskResult {
		/** @var array{query:string, table: string, model_uuid: string,
		 *   conversation_uuid: string} $params */
		$params = self::parseCallRagParams($payload);
		$conversationUuid = self::ensureConversationUuid($params);
		$model = self::getModel($modelManager, $client, $params['model_uuid']);

		self::logConversationStart($conversationUuid);
		$conversationHistory = $conversationManager->getConversationHistory($conversationUuid);
		self::logConversationHistory($conversationHistory);

		$intent = self::classifyIntent(
			$intentClassifier, $params['query'], $conversationHistory, $providerManager, $model
		);

		$settings = $model['settings'];
		[$searchResults, $queries, $excludedIds] = self::performSearch(
			$intent, $params, $conversationHistory, $conversationManager,
			$conversationUuid, $providerManager, $model, $searchEngine, $client
		);

		self::logPreprocessingResults($params, $intent, $queries);
		$context = self::buildContext($searchResults, $settings);
		self::logContextBuilding($searchResults, $context, $settings);
		$response = self::generateResponse(
			$model, $params['query'], $context, $conversationHistory, $settings, $providerManager
		);

		if (!$response['success']) {
			return TaskResult::withError('LLM request failed: ' . ($response['error'] ?? 'Unknown error'));
		}

		$responseText = $response['content'];
		$tokensUsed = $response['metadata']['tokens_used'] ?? 0;

		self::saveConversationMessages(
			$conversationManager, $conversationUuid, $model['uuid'], $intent,
			$params, $queries, $excludedIds, $responseText, $tokensUsed
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
	 * @return array{query:string, table: string, model_uuid: string, conversation_uuid: string}
	 */
	private static function parseCallRagParams(Payload $payload): array {
		// Parse CALL CONVERSATIONAL_RAG parameters from payload
		return [
			'query' => $payload->params['query'] ?? '',
			'table' => $payload->params['table'] ?? '',
			'model_uuid' => $payload->params['model_uuid'] ?? '',
			'conversation_uuid' => $payload->params['conversation_uuid'] ?? '',
		];
	}

	/**
	 * Ensure conversation UUID exists
	 *
	 * @param array<string, string> $params
	 * @return string
	 */
	private static function ensureConversationUuid(array $params): string {
		if (empty($params['conversation_uuid'])) {
			$params['conversation_uuid'] = self::generateUuid();
		}
		return $params['conversation_uuid'];
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
	 * Get model by UUID or name
	 *
	 * @param ModelManager $modelManager
	 * @param Client $client
	 * @param string $modelUuid
	 *
	 * @return array{id:string, uuid:string, name:string,llm_provider:string,
	 *   llm_model:string,style_prompt:string,settings:array{ temperature?: string,
	 *   max_tokens?: string, k_results?: string, similarity_threshold?: string,
	 *   max_document_length?: string},created_at:string,updated_at:string}
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	private static function getModel(ModelManager $modelManager, Client $client, string $modelUuid): array {
		return $modelManager->getModelByUuidOrName($client, $modelUuid);
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
	 * @param LLMProviderManager $providerManager
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
		LLMProviderManager $providerManager,
		array $model
	): string {
		$intent = $intentClassifier->classifyIntent(
			$query, $conversationHistory, $providerManager, $model
		);
		Buddy::info("├─ Intent classified: {$intent}");
		return $intent;
	}

	/**
	 * Perform search based on intent
	 *
	 * @param string $intent
	 * @param array{query:string, table: string, model_uuid: string,
	 *   conversation_uuid: string} $params
	 * @param string $conversationHistory
	 * @param ConversationManager $conversationManager
	 * @param string $conversationUuid
	 * @param LLMProviderManager $providerManager
	 * @param array{id:string, uuid:string, name:string,llm_provider:string,
	 *   llm_model:string,style_prompt:string,settings:array{ temperature?: string,
	 *   max_tokens?: string, k_results?: string, similarity_threshold?: string,
	 *   max_document_length?: string},created_at:string,updated_at:string} $model
	 * @param SearchEngine $searchEngine
	 * @param Client $client
	 * @return array{array<int, array<string, mixed>>, array{search_query:string,
	 *   exclude_query:string}, array<int, string|int>}
	 */
	private static function performSearch(
		string $intent,
		array $params,
		string $conversationHistory,
		ConversationManager $conversationManager,
		string $conversationUuid,
		LLMProviderManager $providerManager,
		array $model,
		SearchEngine $searchEngine,
		Client $client
	): array {
		if ($intent === 'CONTENT_QUESTION') {
			return self::handleContentQuestionIntent(
				$params, $conversationHistory, $conversationManager, $conversationUuid,
				$providerManager, $model, $searchEngine, $client
			);
		}

		return self::handleQueryGeneratingIntent(
			$intent, $params, $conversationHistory, $conversationManager,
			$conversationUuid, $providerManager, $model, $searchEngine, $client
		);
	}

	/**
	 * Handle CONTENT_QUESTION intent
	 *
	 * @param array{query:string, table: string, model_uuid: string,
	 *   conversation_uuid: string} $params
	 * @param string $conversationHistory
	 * @param ConversationManager $conversationManager
	 * @param string $conversationUuid
	 * @param LLMProviderManager $providerManager
	 * @param array{id:string, uuid:string, name:string,llm_provider:string,
	 *   llm_model:string,style_prompt:string,settings:array{ temperature?: string,
	 *   max_tokens?: string, k_results?: string, similarity_threshold?: string,
	 *   max_document_length?: string},created_at:string,updated_at:string} $model
	 * @param SearchEngine $searchEngine
	 * @param Client $client
	 *
	 * @return array{array<int, array<string, mixed>>, array{search_query: string,
	 *   exclude_query: string}, array<int, string|int>}
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function handleContentQuestionIntent(
		array $params,
		string $conversationHistory,
		ConversationManager $conversationManager,
		string $conversationUuid,
		LLMProviderManager $providerManager,
		array $model,
		SearchEngine $searchEngine,
		Client $client
	): array {
		Buddy::info('├─ Processing CONTENT_QUESTION intent');
		$lastContext = $conversationManager->getLatestSearchContext($conversationUuid);

		if ($lastContext) {
			Buddy::info('├─ Found previous search context to reuse');
			$queries = [
				'search_query' => $lastContext['search_query'],
				'exclude_query' => $lastContext['exclude_query'],
			];

			$thresholdManager = new DynamicThresholdManager();
			$thresholdInfo = $thresholdManager->calculateDynamicThreshold(
				$params['query'], $conversationHistory, $providerManager, $model
			);

			$excludedIds = json_decode($lastContext['excluded_ids'], true) ?? [];
			if (!is_array($excludedIds)) {
				throw ManticoreSearchClientError::create('Excluded IDs must be an array');
			}

			$searchResults = $searchEngine->performSearchWithExcludedIds(
				$client, $params['table'], $queries['search_query'], $excludedIds,
				$model, $thresholdInfo['threshold']
			);
			Buddy::info('├─ CONTENT_QUESTION performed KNN search with previous query parameters');
			return [$searchResults, $queries, $excludedIds];
		}

		Buddy::info('├─ No previous search context found, falling back to NEW_SEARCH');
		// Fallback to NEW_SEARCH logic
		return self::handleQueryGeneratingIntent(
			'NEW_SEARCH', $params, $conversationHistory, $conversationManager,
			$conversationUuid, $providerManager, $model, $searchEngine, $client
		);
	}

	/**
	 * Handle query-generating intents
	 *
	 * @param string $intent
	 * @param array{query:string, table: string, model_uuid: string,
	 *   conversation_uuid: string} $params
	 * @param string $conversationHistory
	 * @param ConversationManager $conversationManager
	 * @param string $conversationUuid
	 * @param LLMProviderManager $providerManager
	 * @param array{id:string, uuid:string, name:string,llm_provider:string,
	 *   llm_model:string,style_prompt:string,settings:array{ temperature?: string,
	 *   max_tokens?: string, k_results?: string, similarity_threshold?: string,
	 *   max_document_length?: string},created_at:string,updated_at:string} $model
	 * @param SearchEngine $searchEngine
	 * @param Client $client
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 *
	 * @return array{array<int, array<string, mixed>>, array{search_query:string,
	 *   exclude_query:string}, array<int, string|int>}
	 */
	private static function handleQueryGeneratingIntent(
		string $intent,
		array $params,
		string $conversationHistory,
		ConversationManager $conversationManager,
		string $conversationUuid,
		LLMProviderManager $providerManager,
		array $model,
		SearchEngine $searchEngine,
		Client $client
	): array {
		Buddy::info("├─ Processing query-generating intent: {$intent}");
		$cleanHistory = $conversationManager->getConversationHistoryForQueryGeneration($conversationUuid);

		Buddy::info('├─ Using filtered history for query generation');
		Buddy::info('├─ Clean history length: ' . strlen($cleanHistory) . ' chars');

		$intentClassifierInstance = new IntentClassifier();
		$queries = $intentClassifierInstance->generateQueries(
			$params['query'], $intent, $cleanHistory, $providerManager, $model
		);

		$thresholdManager = new DynamicThresholdManager();
		$thresholdInfo = $thresholdManager->calculateDynamicThreshold(
			$params['query'], $conversationHistory, $providerManager, $model
		);

		$excludedIds = [];
		if (!empty($queries['exclude_query']) && $queries['exclude_query'] !== 'none') {
			$excludedIds = $searchEngine->getExcludedIds(
				$client, $params['table'], $queries['exclude_query']
			);
		}

		$searchResults = $searchEngine->performSearchWithExcludedIds(
			$client, $params['table'], $queries['search_query'], $excludedIds,
			$model, $thresholdInfo['threshold']
		);

		return [$searchResults, $queries, $excludedIds];
	}

	/**
	 * Log preprocessing results
	 *
	 * @param array{query:string, table: string, model_uuid: string, conversation_uuid: string} $params
	 * @param string $intent
	 * @param array{search_query:string, exclude_query:string} $queries
	 * @return void
	 */
	private static function logPreprocessingResults(array $params, string $intent, array $queries): void {
		Buddy::info('[DEBUG PREPROCESSING]');
		Buddy::info("├─ User query: '{$params['query']}'");
		Buddy::info("├─ Intent: $intent");
		Buddy::info("├─ Search query: '{$queries['search_query']}'");
		Buddy::info("└─ Exclude query: '{$queries['exclude_query']}'");
	}

	/**
	 * @param array<int, array<string, mixed>> $searchResults
	 * @param array{ temperature?: string, max_tokens?: string, k_results?: string,
	 *   similarity_threshold?: string, max_document_length?: string} $settings
	 *
	 * @return string
	 */
	private static function buildContext(array $searchResults, array $settings): string {
		if (empty($searchResults)) {
			return '';
		}

		$maxDocLength = $settings['max_document_length'] ?? 2000;
		$truncatedDocs = array_map(
			function ($doc) use ($maxDocLength) {
				$content = isset($doc['content']) && is_string($doc['content']) ? $doc['content'] : '';
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
	 * @param LLMProviderManager $providerManager
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
		LLMProviderManager $providerManager
	): array {
		// Use LLM provider manager for proper connection handling
		$provider = $providerManager->getConnection($model['uuid'], $model);

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
	 * @param string $conversationUuid
	 * @param string $modelUuid
	 * @param string $intent
	 * @param array{query:string, table: string, model_uuid: string, conversation_uuid: string} $params
	 * @param array{search_query:string, exclude_query:string} $queries
	 * @param array<int, string|int> $excludedIds
	 * @param string $responseText
	 * @param int $tokensUsed
	 * @throws ManticoreSearchClientError|JsonException
	 *
	 * @return void
	 */
	private static function saveConversationMessages(
		ConversationManager $conversationManager,
		string $conversationUuid,
		string $modelUuid,
		string $intent,
		array $params,
		array $queries,
		array $excludedIds,
		string $responseText,
		int $tokensUsed
	): void {
		if ($intent === 'CONTENT_QUESTION') {
			$conversationManager->saveMessage(
				$conversationUuid, $modelUuid, 'user', $params['query'], 0, $intent
			);
		} else {
			$stringExcludedIds = array_map('strval', $excludedIds);
			$conversationManager->saveMessage(
				$conversationUuid, $modelUuid, 'user', $params['query'],
				0, $intent, $queries['search_query'], $queries['exclude_query'], $stringExcludedIds
			);
		}

		$assistantIntent = ($intent === 'CONTENT_QUESTION') ? 'CONTENT_QUESTION' : null;
		Buddy::info('├─ Saving assistant response');
		Buddy::info('├─ Assistant intent: ' . ($assistantIntent ?? 'none'));
		Buddy::info('├─ Response length: ' . strlen($responseText) . ' chars');
		Buddy::info("├─ Tokens used: {$tokensUsed}");

		$conversationManager->saveMessage(
			$conversationUuid, $modelUuid, 'assistant', $responseText, $tokensUsed, $assistantIntent
		);

		Buddy::info('└─ Conversation processing completed');
	}


}
