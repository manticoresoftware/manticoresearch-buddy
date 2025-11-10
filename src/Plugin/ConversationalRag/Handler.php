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
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * This class handles all ConversationalRag plugin operations
 * including model management and conversation processing
 */
final class Handler extends BaseHandlerWithClient {

	private ?LLMProviderManager $llmProviderManager = null;

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
	 * @throws ManticoreSearchClientError
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
	 */
	private static function createModel(Payload $payload, ModelManager $modelManager, Client $client): TaskResult {
			$config = $payload->params;

			// Validate configuration
			self::validateModelConfig($config);

			// Create model
			$uuid = $modelManager->createModel($client, $config);

			return TaskResult::withRow(['uuid' => $uuid])
				->column('uuid', Column::String);
	}

	/**
	 * Validate model configuration
	 *
	 * @param array $config
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
	 * @param array $config
	 * @return void
	 * @throws QueryParseError
	 */
	private static function validateRequiredFields(array $config): void {
		$required = ['llm_provider', 'llm_model'];

		foreach ($required as $field) {
			if (!isset($config[$field]) || empty($config[$field])) {
				throw QueryParseError::create("Required field '{$field}' is missing or empty");
			}
		}
	}

	/**
	 * Validate LLM provider
	 *
	 * @param array $config
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
	 * @param array $config
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
	 * @param array $config
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
	 * @param array $config
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
	 * @throws ManticoreSearchClientError
	 */
	private static function describeModel(Payload $payload, ModelManager $modelManager, Client $client): TaskResult {

			$modelNameOrUuid = $payload->params['model_name_or_uuid'];
			$model = $modelManager->getModelByUuidOrName($client, $modelNameOrUuid);

		if (empty($model)) {
			throw ManticoreSearchClientError::create("RAG model '{$modelNameOrUuid}' not found");
		}


			$data = [];
		foreach ($model as $key => $value) {
			if ($key === 'settings' && is_string($value)) {
				$value = json_decode($value, true);
				if (is_array($value)) {
					foreach ($value as $setting => $settingValue) {
						$data[] = [
							'property' => "settings.{$setting}",
							'value' => (string)$settingValue,
						];
					}
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
	 * @return TaskResult
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
		$params = self::parseCallRagParams($payload);
		$conversationUuid = self::ensureConversationUuid($params);
		$model = self::getModel($modelManager, $client, $params['model_uuid']);

		self::logConversationStart($conversationUuid);
		$conversationHistory = $conversationManager->getConversationHistory($conversationUuid);
		self::logConversationHistory($conversationHistory);

		$intent = self::classifyIntent(
			$intentClassifier, $params['query'], $conversationHistory, $providerManager, $model
		);

		$effectiveSettings = self::getEffectiveSettings($model, $params['overrides']);
		[$searchResults, $queries, $excludedIds] = self::performSearch(
			$intent, $params, $conversationHistory, $conversationManager,
			$conversationUuid, $providerManager, $model, $searchEngine, $client
		);

		self::logPreprocessingResults($params, $intent, $queries);
		$context = self::buildContext($searchResults, $effectiveSettings);
		self::logContextBuilding($searchResults, $context, $effectiveSettings);

		$response = self::generateResponse(
			$model, $params['query'], $context, $conversationHistory, $effectiveSettings, $providerManager
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

	private static function parseCallRagParams(Payload $payload): array {
		// Parse CALL CONVERSATIONAL_RAG parameters from payload
		return [
			'query' => $payload->params['query'] ?? '',
			'table' => $payload->params['table'] ?? '',
			'model_uuid' => $payload->params['model_uuid'] ?? '',
			'conversation_uuid' => $payload->params['conversation_uuid'] ?? '',
			'overrides' => $payload->params['overrides'] ?? [],
		];
	}

	/**
	 * Ensure conversation UUID exists
	 *
	 * @param array $params
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
	 * @return array
	 * @throws ManticoreSearchClientError
	 */
	private static function getModel(ModelManager $modelManager, Client $client, string $modelUuid): array {
		$model = $modelManager->getModelByUuidOrName($client, $modelUuid);
		if (!$model) {
			throw ManticoreSearchClientError::create('Model not found');
		}
		return $model;
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
	 * @param array $model
	 * @return string
	 */
	private static function classifyIntent(
		IntentClassifier $intentClassifier,
		string $query,
		string $conversationHistory,
		LLMProviderManager $providerManager,
		array $model
	): string {
		$intentResult = $intentClassifier->classifyIntent(
			$query, $conversationHistory, $providerManager, $model
		);
		$intent = $intentResult['intent'];
		Buddy::info("├─ Intent classified: {$intent}");
		return $intent;
	}

	/**
	 * Get effective settings by merging model settings with overrides
	 *
	 * @param array $model
	 * @param array $overrides
	 * @return array
	 */
	private static function getEffectiveSettings(array $model, array $overrides): array {
		$modelSettings = is_string($model['settings'])
			? json_decode($model['settings'], true) ?? []
			: $model['settings'];
		return array_merge($modelSettings, $overrides);
	}

	/**
	 * Perform search based on intent
	 *
	 * @param string $intent
	 * @param array $params
	 * @param string $conversationHistory
	 * @param ConversationManager $conversationManager
	 * @param string $conversationUuid
	 * @param LLMProviderManager $providerManager
	 * @param array $model
	 * @param SearchEngine $searchEngine
	 * @param Client $client
	 * @return array
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
	 * @param array $params
	 * @param string $conversationHistory
	 * @param ConversationManager $conversationManager
	 * @param string $conversationUuid
	 * @param LLMProviderManager $providerManager
	 * @param array $model
	 * @param SearchEngine $searchEngine
	 * @param Client $client
	 * @return array
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
			$searchResults = $searchEngine->performSearchWithExcludedIds(
				$client, $params['table'], $queries['search_query'], $excludedIds,
				$model, ['overrides' => $params['overrides']], $thresholdInfo['threshold']
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
	 * @param array $params
	 * @param string $conversationHistory
	 * @param ConversationManager $conversationManager
	 * @param string $conversationUuid
	 * @param LLMProviderManager $providerManager
	 * @param array $model
	 * @param SearchEngine $searchEngine
	 * @param Client $client
	 * @return array
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
				$client, $params['table'], $queries['exclude_query'], $model
			);
		}

		$searchResults = $searchEngine->performSearchWithExcludedIds(
			$client, $params['table'], $queries['search_query'], $excludedIds,
			$model, ['overrides' => $params['overrides']], $thresholdInfo['threshold']
		);

		return [$searchResults, $queries, $excludedIds];
	}

	/**
	 * Log preprocessing results
	 *
	 * @param array $params
	 * @param string $intent
	 * @param array $queries
	 * @return void
	 */
	private static function logPreprocessingResults(array $params, string $intent, array $queries): void {
		Buddy::info('[DEBUG PREPROCESSING]');
		Buddy::info("├─ User query: '{$params['query']}'");
		Buddy::info("├─ Intent: {$intent}");
		Buddy::info("├─ Search query: '{$queries['search_query']}'");
		Buddy::info("└─ Exclude query: '{$queries['exclude_query']}'");
	}

	private static function buildContext(array $searchResults, array $settings): string {
		if (empty($searchResults)) {
			return '';
		}

		$maxDocLength = $settings['max_document_length'] ?? 2000;
		$truncatedDocs = array_map(
			function ($doc) use ($maxDocLength) {
				$content = $doc['content'] ?? '';
				return strlen($content) > $maxDocLength ? substr($content, 0, $maxDocLength) . '...' : $content;
			}, $searchResults
		);

		return implode("\n", $truncatedDocs);
	}

	/**
	 * Log context building
	 *
	 * @param array $searchResults
	 * @param string $context
	 * @param array $effectiveSettings
	 * @return void
	 */
	private static function logContextBuilding(array $searchResults, string $context, array $effectiveSettings): void {
		Buddy::info('[DEBUG CONTEXT]');
		Buddy::info('├─ Documents count: ' . sizeof($searchResults));
		Buddy::info('├─ Total context length: ' . strlen($context) . ' chars');
		Buddy::info('└─ Max doc length: ' . ($effectiveSettings['max_document_length'] ?? 2000) . ' chars');
	}

	private static function generateResponse(
		array $model,
		string $query,
		string $context,
		string $history,
		array $effectiveSettings,
		LLMProviderManager $providerManager
	): array {
		// Use LLM provider manager for proper connection handling
		$modelId = $model['uuid'] ?? null;
		if ($modelId === null) {
			throw ManticoreSearchClientError::create('Model ID is null');
		}
		if (!is_string($modelId)) {
			throw ManticoreSearchClientError::create(
				'Model ID is not a string: ' . gettype($modelId) . ' = ' . var_export($modelId, true)
			);
		}
		$provider = $providerManager->getConnection($modelId, $model);

		$prompt = self::buildPrompt($model['style_prompt'], $query, $context, $history);

		return $provider->generateResponse($prompt, $effectiveSettings);
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
	 * @param array $params
	 * @param array $queries
	 * @param array $excludedIds
	 * @param string $responseText
	 * @param int $tokensUsed
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
			$conversationManager->saveMessage(
				$conversationUuid, $modelUuid, 'user', $params['query'],
				0, $intent, $queries['search_query'], $queries['exclude_query'], $excludedIds
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
