<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;

class ConversationManager {
	use SqlEscapeTrait;
	public const CONVERSATIONS_TABLE = 'rag_conversations';

	/**
	 * @param Client $client
	 */
	public function __construct(private Client $client) {
	}

	/**
	 * Initialize conversations table if it doesn't exist
	 *
	 * @param Client $client
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	public function initializeTable(Client $client): void {
		// Create enhanced conversations table with search context columns
		$sql = /** @lang Manticore */ 'CREATE TABLE IF NOT EXISTS ' . self::CONVERSATIONS_TABLE . ' (
			conversation_uuid string,
			model_uuid string,
			created_at bigint,
			role string,
			message text,
			tokens_used int,
			intent string,
			search_query text,
			exclude_query text,
			excluded_ids text,
			ttl bigint
		)';

		$response = $client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create('Failed to create conversations table: ' . $response->getError());
		}
	}

	/**
	 * @param string $conversationUuid
	 * @param string $modelUuid
	 * @param string $role
	 * @param string $message
	 * @param int $tokensUsed
	 * @param string|null $intent
	 * @param string|null $searchQuery
	 * @param string|null $excludeQuery
	 * @param array<int, string>|null $excludedIds
	 *
	 * @return void
	 * @throws ManticoreSearchClientError|\JsonException
	 */
	public function saveMessage(
		string $conversationUuid,
		string $modelUuid,
		string $role,
		string $message,
		int $tokensUsed = 0,
		?string $intent = null,
		?string $searchQuery = null,
		?string $excludeQuery = null,
		?array $excludedIds = null
	): void {
		// Debug: Log message saving
		Buddy::info("\n[DEBUG CONVERSATION SAVE]");
		Buddy::info("├─ Conversation UUID: {$conversationUuid}");
		Buddy::info("├─ Model UUID: {$modelUuid}");
		Buddy::info("├─ Role: {$role}");
		Buddy::info('├─ Message: ' . substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''));
		Buddy::info("├─ Tokens used: {$tokensUsed}");
		Buddy::info('├─ Intent: ' . ($intent ?? 'none'));
		Buddy::info('├─ Search query: ' . ($searchQuery ? substr($searchQuery, 0, 50) . '...' : 'none'));
		Buddy::info('├─ Exclude query: ' . ($excludeQuery ? substr($excludeQuery, 0, 50) . '...' : 'none'));
		Buddy::info('└─ Excluded IDs count: ' . ($excludedIds ? sizeof($excludedIds) : 0));

		$currentTime = time();
		$ttlTime = $currentTime + (30 * 24 * 60 * 60); // 30 days

		$intentValue = $intent ? $this->quote($intent) : "''";
		$searchQueryValue = $searchQuery ? $this->quote($searchQuery) : "''";
		$excludeQueryValue = $excludeQuery ? $this->quote($excludeQuery) : "''";
		$excludedIdsValue = $excludedIds ? $this->quote(
			json_encode($excludedIds, JSON_THROW_ON_ERROR)
		) : "''";

		$sql = sprintf(
			'INSERT INTO %s (conversation_uuid, model_uuid, created_at, role, message, tokens_used, '
			. 'intent, search_query, exclude_query, excluded_ids, ttl) '
			. 'VALUES (%s, %s, %d, %s, %s, %d, %s, %s, %s, %s, %d)',
			self::CONVERSATIONS_TABLE,
			$this->quote($conversationUuid),
			$this->quote($modelUuid),
			$currentTime,
			$this->quote($role),
			$this->quote($message),
			$tokensUsed,
			$intentValue,
			$searchQueryValue,
			$excludeQueryValue,
			$excludedIdsValue,
			$ttlTime
		);

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Failed to insert into conversations table: ' . $result->getError()
			);
		}

		Buddy::info('└─ Message saved successfully');
	}

	/**
	 * @param string $conversationUuid
	 * @param int $limit
	 *
	 * @return string
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function getConversationHistory(string $conversationUuid, int $limit = 100): string {
		// Debug: Log history retrieval
		Buddy::info("\n[DEBUG CONVERSATION HISTORY RETRIEVAL]");
		Buddy::info("├─ Conversation UUID: {$conversationUuid}");
		Buddy::info("├─ Limit: {$limit}");

		$sql = /** @lang Manticore */ 'SELECT role, message FROM ' . self::CONVERSATIONS_TABLE . ' '.
			"WHERE conversation_uuid = '$conversationUuid' ".
			"ORDER BY created_at ASC LIMIT $limit";

		Buddy::info("├─ SQL: {$sql}");

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Failed to retrieve conversation history: ' . $result->getError());
		}

		$data = $result->getResult();
		$history = '';
		if (is_array($data[0])) {
			$rows = $data[0]['data'];
			Buddy::info('├─ Messages found: ' . $data->count());
			foreach ($rows as $row) {
				$role = (string)$row['role'];
				$message = (string)$row['message'];
				$history .= "{$role}: {$message}\n";
			}
		}

		$historyLength = strlen($history);
		Buddy::info("├─ History length: {$historyLength} chars");
		Buddy::info('└─ History preview: ' . substr($history, 0, 150) . ($historyLength > 150 ? '...' : ''));

		return $history;
	}


	/**
	 * Get the latest search context that was NOT from a CONTENT_QUESTION intent
	 *
	 * @param string $conversationUuid
	 *
	 * @return array{search_query: string, exclude_query: string, excluded_ids: string}|null
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function getLatestSearchContext(string $conversationUuid): ?array {
		// Debug: Log search context retrieval
		Buddy::info("\n[DEBUG SEARCH CONTEXT RETRIEVAL]");
		Buddy::info("├─ Conversation UUID: {$conversationUuid}");

		$sql = /** @lang Manticore */ 'SELECT search_query, exclude_query, excluded_ids FROM '
			. self::CONVERSATIONS_TABLE . ' ' .
			"WHERE conversation_uuid = '$conversationUuid' " .
			"AND role = 'user' " .
			"AND intent != 'CONTENT_QUESTION' " .
			'ORDER BY created_at DESC LIMIT 1';

		Buddy::info("├─ SQL: {$sql}");

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Failed to retrieve search context: ' . $result->getError());
		}

		$data = $result->getResult();

		if (is_array($data[0]) && isset($data[0]['data'])) {
			$rows = $data[0]['data'];

			if (empty($rows)) {
				Buddy::info('└─ No search context found');
				return null;
			}

			$searchContext = [
				'search_query' => (string)$rows[0]['search_query'],
				'exclude_query' => (string)$rows[0]['exclude_query'],
				'excluded_ids' => (string)$rows[0]['excluded_ids'],
			];

			/** @var array<int, mixed> $excludedIdsArray */
			$excludedIdsArray = json_decode($searchContext['excluded_ids'], true) ?? [];
			Buddy::info('├─ Search query: ' . substr($searchContext['search_query'], 0, 50) . '...');
			$excludePreview = $searchContext['exclude_query']
				? substr($searchContext['exclude_query'], 0, 50) . '...'
				: 'none';
			Buddy::info('├─ Exclude query: ' . $excludePreview);
			Buddy::info('└─ Excluded IDs count: ' . sizeof($excludedIdsArray));

			return $searchContext;
		}

		throw ManticoreSearchClientError::create('Manticore returned wrong context structure');
	}

	/**
	 * Get conversation history filtered for query generation (excludes CONTENT_QUESTION exchanges)
	 *
	 * @param string $conversationUuid
	 * @param int $limit
	 * @return string
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function getConversationHistoryForQueryGeneration(string $conversationUuid, int $limit = 100): string {
		// Debug: Log filtered history retrieval for query generation
		Buddy::info("\n[DEBUG FILTERED HISTORY RETRIEVAL]");
		Buddy::info("├─ Conversation UUID: {$conversationUuid}");
		Buddy::info("├─ Limit: {$limit}");

		$sql = /** @lang Manticore */ 'SELECT role, message FROM ' . self::CONVERSATIONS_TABLE . ' '.
			"WHERE conversation_uuid = '$conversationUuid' " .
			"AND intent != 'CONTENT_QUESTION' " .
			"ORDER BY created_at ASC LIMIT $limit";

		Buddy::info("├─ SQL: {$sql}");

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Failed to retrieve conversation history: ' . $result->getError());
		}

		$data = $result->getResult();

		$history = '';

		if (is_array($data[0])) {
			$rows = $data[0]['data'];

			Buddy::info('├─ Filtered messages found: ' . sizeof($rows));
			foreach ($rows as $row) {
				$role = (string)$row['role'];
				$message = (string)$row['message'];
				$history .= "{$role}: {$message}\n";
			}
		}

		$historyLength = strlen($history);
		Buddy::info("├─ Filtered history length: {$historyLength} chars");
		Buddy::info('└─ Filtered history preview: ' . substr($history, 0, 150) . ($historyLength > 150 ? '...' : ''));

		return $history;
	}
}
