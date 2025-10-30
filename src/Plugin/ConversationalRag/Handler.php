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

/**
 * This class handles all ConversationalRag plugin operations
 * including model management and conversation processing
 */
final class Handler extends BaseHandlerWithClient {

	/**
	 * Initialize the handler
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws ManticoreSearchClientError
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, Client $client): TaskResult {
			// Initialize components with the client
			$modelManager = new ModelManager();
			$providerManager = new LLMProviderManager();
			$conversationManager = new ConversationManager($client);
			$intentClassifier = new IntentClassifier();
			$searchEngine = new SearchEngine($client);

			// Ensure database tables exist
			self::initializeTables($modelManager, $client);

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

		return Task::create($taskFn, [$this->payload, $this->manticoreClient])->run();
	}

	/**
	 * Initialize database tables if they don't exist
	 *
	 * @param ModelManager $modelManager
	 * @param Client $client
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private static function initializeTables(ModelManager $modelManager, Client $client): void {
		$modelManager->initializeTables($client);
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

			// Generate UUID if conversation_uuid not provided
		if (empty($params['conversation_uuid'])) {
			$params['conversation_uuid'] = self::generateUuid();
		}

			$conversationUuid = $params['conversation_uuid'];

			$model = $modelManager->getModelByUuidOrName($client, $params['model_uuid']);
		if (!$model) {
			throw ManticoreSearchClientError::create('Model not found');
		}

			$modelName = $model['name'];

			$conversationHistory = $conversationManager->getConversationHistory($conversationUuid);

			// Step 1: Classify intent using LLM
			$intentResult = $intentClassifier->classifyIntent(
				$params['query'], $conversationHistory, [], $providerManager, $model
			);
			$intent = $intentResult['intent'];

			// Step 2: Generate search and exclusion queries using LLM
			$queries = $intentClassifier->generateQueries(
				$params['query'], $intent, $conversationHistory, $providerManager, $model
			);

			// Merge model settings with overrides
			$modelSettings = is_string($model['settings'])
				? json_decode($model['settings'], true) ?? []
				: $model['settings'];
			$effectiveSettings = array_merge($modelSettings, $params['overrides']);

			// Step 3: Calculate dynamic threshold using LLM
			$thresholdManager = new DynamicThresholdManager();
			$thresholdInfo = $thresholdManager->calculateDynamicThreshold(
				$params['query'],
				$conversationHistory,
				$providerManager,
				$model
			);

		// Step 4: Perform search with dynamic threshold
			$searchResults = $searchEngine->performSearch(
				$client,
				$params['table'],
				$queries['search_query'],
				$queries['exclude_query'],
				$model,
				['overrides' => $params['overrides']],
				$thresholdInfo['threshold']
			);

		// Add threshold info to results
			$searchResults['threshold_info'] = $thresholdInfo;

			$context = self::buildContext($searchResults['results'], $effectiveSettings);

			$response = self::generateResponse(
				$model, $params['query'], $context, $conversationHistory, $effectiveSettings, $providerManager
			);

			// Save messages
			$conversationManager->saveMessage(
				$conversationUuid, $modelName, 'user', $params['query'], [], $intent
			);
			$conversationManager->saveMessage(
				$conversationUuid, $modelName, 'assistant',
				$response['response'], [], '', $response['tokens_used'], $response['response_time_ms']
			);

			return TaskResult::raw(
				[
				'conversation_uuid' => $conversationUuid,
				'response' => $response['response'],
				'intent' => $intent,
				'sources' => count($searchResults['results']),
				'threshold_info' => $searchResults['threshold_info'],
				'search_type' => $searchResults['search_type'],
				]
			);
	}



	/**
	 * Validate model configuration
	 *
	 * @param array $config
	 * @return void
	 * @throws QueryParseError
	 */
	private static function validateModelConfig(array $config): void {
		$required = ['llm_provider', 'llm_model'];

		foreach ($required as $field) {
			if (!isset($config[$field]) || empty($config[$field])) {
				throw QueryParseError::create("Required field '{$field}' is missing or empty");
			}
		}

		$validProviders = ['openai'];
		if (!in_array($config['llm_provider'], $validProviders)) {
			throw QueryParseError::create(
				"Invalid LLM provider: {$config['llm_provider']}. Only 'openai' is supported."
			);
		}

		// Validate numeric parameters
		if (isset($config['temperature'])) {
			$temp = (float)$config['temperature'];
			if ($temp < 0 || $temp > 2) {
				throw QueryParseError::create('Temperature must be between 0 and 2');
			}
		}

		if (isset($config['max_tokens'])) {
			$tokens = (int)$config['max_tokens'];
			if ($tokens < 1 || $tokens > 32768) {
				throw QueryParseError::create('max_tokens must be between 1 and 32768');
			}
		}

		if (!isset($config['k_results'])) {
			return;
		}

		$k = (int)$config['k_results'];
		if ($k < 1 || $k > 50) {
			throw QueryParseError::create('k_results must be between 1 and 50');
		}
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

	private static function generateResponse(
		array $model,
		string $query,
		string $context,
		array $history,
		array $effectiveSettings,
		LLMProviderManager $providerManager
	): array {
		// Use LLM provider manager for proper connection handling
		$modelId = $model['id'] ?? null;
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

		return $provider->generateResponse($prompt, [], $effectiveSettings);
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

	private static function buildPrompt(string $stylePrompt, string $query, string $context, array $history): string {
		// Build prompt similar to original implementation
		$historyText = '';
		if (!empty($history)) {
			foreach ($history as $msg) {
				$historyText .= "{$msg['role']}: {$msg['message']}\n";
			}
		}

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


}
