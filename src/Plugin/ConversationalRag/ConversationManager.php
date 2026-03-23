<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use InvalidArgumentException;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Lib\SqlEscapingTrait;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;

class ConversationManager {
	use SqlEscapingTrait;

	public const string CONVERSATIONS_TABLE = 'rag_conversations';
	private const int CONVERSATION_TTL_SECONDS = 30 * 24 * 60 * 60; // 30 days

	/**
	 * @param Client $client
	 */
	public function __construct(private readonly Client $client) {
	}

	/**
	 * Initialize conversations table if it doesn't exist
	 *
	 * @param Client $client
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	public function initializeTable(Client $client): void {
		// Create enhanced conversations table with search context columns
		$sql
			= /** @lang Manticore */
			'CREATE TABLE IF NOT EXISTS ' . self::CONVERSATIONS_TABLE . ' (
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
		Buddy::debugvv("\n[DEBUG CONVERSATION SAVE]");
		Buddy::debugvv("├─ Conversation UUID: $conversationUuid");
		Buddy::debugvv("├─ Model UUID: $modelUuid");
		Buddy::debugvv("├─ Role: $role");
		Buddy::debugvv('├─ Message: ' . substr($message, 0, 100) . (strlen($message) > 100 ? '...' : ''));
		Buddy::debugvv("├─ Tokens used: $tokensUsed");
		Buddy::debugvv('├─ Intent: ' . ($intent ?? 'none'));
		Buddy::debugvv('├─ Search query: ' . ($searchQuery ? substr($searchQuery, 0, 50) . '...' : 'none'));
		Buddy::debugvv('├─ Exclude query: ' . ($excludeQuery ? substr($excludeQuery, 0, 50) . '...' : 'none'));
		Buddy::debugvv(
			'└─ Excluded IDs count: ' . ($excludedIds === null ? 0 : sizeof($excludedIds))
		);

		$currentTime = time();
		$ttlTime = $currentTime + self::CONVERSATION_TTL_SECONDS;

		$intentValue = $intent ? $this->quote($intent) : "''";
		$searchQueryValue = $searchQuery ? $this->quote($searchQuery) : "''";
		$excludeQueryValue = $excludeQuery ? $this->quote($excludeQuery) : "''";
		$excludedIdsPayload = $excludedIds === null ? [] : $excludedIds;
		$excludedIdsValue = $this->quote(
			json_encode($excludedIdsPayload, JSON_THROW_ON_ERROR)
		);

		$sql = sprintf(
		/** @lang manticore */
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

		Buddy::debugvv('└─ Message saved successfully');
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
		Buddy::debugvv("\n[DEBUG CONVERSATION HISTORY RETRIEVAL]");
		Buddy::debugvv("├─ Conversation UUID: $conversationUuid");
		Buddy::debugvv("├─ Limit: $limit");

		$safeLimit = $this->assertPositiveLimit($limit, 'limit');

		/** @lang Manticore */
		$sql = sprintf(
		/** @lang manticore */            'SELECT role, message FROM %s '
			. 'WHERE conversation_uuid = %s '
			. 'ORDER BY created_at ASC '
			. 'LIMIT %d',
			self::CONVERSATIONS_TABLE,
			$this->quote($conversationUuid),
			$safeLimit
		);

		Buddy::debugvv("├─ SQL: $sql");

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Failed to retrieve conversation history: ' . $result->getError());
		}

		$data = $result->getResult();
		$history = '';
		if (is_array($data[0])) {
			$rows = $data[0]['data'];
			Buddy::debugvv('├─ Messages found: ' . $data->count());
			foreach ($rows as $row) {
				$role = (string)$row['role'];
				$message = (string)$row['message'];
				$history .= "$role: $message\n";
			}
		}

		$historyLength = strlen($history);
		Buddy::debugvv("├─ History length: $historyLength chars");
		Buddy::debugvv('└─ History preview: ' . substr($history, 0, 150) . ($historyLength > 150 ? '...' : ''));

		return $history;
	}

	private function assertPositiveLimit(int $limit, string $name): int {
		if ($limit <= 0) {
			throw new InvalidArgumentException("$name must be greater than 0");
		}

		return $limit;
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
		Buddy::debugvv("\n[DEBUG SEARCH CONTEXT RETRIEVAL]");
		Buddy::debugvv("├─ Conversation UUID: $conversationUuid");

		/** @lang Manticore */
		$sql = sprintf(
			'SELECT search_query, exclude_query, excluded_ids FROM %s '
			. 'WHERE conversation_uuid = %s '
			. 'AND role = %s '
			. 'AND intent != %s '
			. 'ORDER BY created_at DESC '
			. 'LIMIT 1',
			self::CONVERSATIONS_TABLE,
			$this->quote($conversationUuid),
			$this->quote('user'),
			$this->quote(Intent::CONTENT_QUESTION)
		);

		Buddy::debugvv("├─ SQL: $sql");

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Failed to retrieve search context: ' . $result->getError());
		}

		$data = $result->getResult();
		if (!is_array($data[0]) || !isset($data[0]['data'])) {
			throw ManticoreSearchClientError::create('Manticore returned wrong context structure');
		}

		$rows = $data[0]['data'];
		if (empty($rows)) {
			Buddy::debugvv('└─ No search context found');
			return null;
		}

		$rawExcludedIds = trim((string)($rows[0]['excluded_ids'] ?? ''));
		if (strtoupper($rawExcludedIds) === 'NULL') {
			$rawExcludedIds = '';
		}

		$searchContext = [
			'search_query' => (string)$rows[0]['search_query'],
			'exclude_query' => (string)$rows[0]['exclude_query'],
			'excluded_ids' => $rawExcludedIds,
		];

		$excludedIdsArray = $rawExcludedIds === ''
			? []
			: self::decodeExcludedIds($rawExcludedIds);

		Buddy::debugvv('├─ Search query: ' . substr($searchContext['search_query'], 0, 50) . '...');
		$excludePreview = $searchContext['exclude_query'] !== ''
			? substr($searchContext['exclude_query'], 0, 50) . '...'
			: 'none';
		Buddy::debugvv('├─ Exclude query: ' . $excludePreview);
		Buddy::debugvv('└─ Excluded IDs count: ' . sizeof($excludedIdsArray));

		return $searchContext;
	}

	/**
	 * @return array<int, mixed>
	 * @throws ManticoreSearchClientError
	 */
	private static function decodeExcludedIds(string $rawExcludedIds): array {
		try {
			$decoded = simdjson_decode($rawExcludedIds, true);
		} catch (\Throwable $e) {
			throw ManticoreSearchClientError::create(
				'Invalid JSON stored in excluded_ids: ' . $e->getMessage()
			);
		}
		if (!is_array($decoded)) {
			throw ManticoreSearchClientError::create('Invalid JSON stored in excluded_ids');
		}

		return $decoded;
	}

	/**
	 * Get conversation history filtered for query generation (excludes CONTENT_QUESTION exchanges)
	 *
	 * @param string $conversationUuid
	 * @param int $limit
	 *
	 * @return string
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function getConversationHistoryForQueryGeneration(string $conversationUuid, int $limit = 100): string {
		// Debug: Log filtered history retrieval for query generation
		Buddy::debugvv("\n[DEBUG FILTERED HISTORY RETRIEVAL]");
		Buddy::debugvv("├─ Conversation UUID: $conversationUuid");
		Buddy::debugvv("├─ Limit: $limit");

		$safeLimit = $this->assertPositiveLimit($limit, 'limit');

		/** @lang Manticore */
		$sql = sprintf(
			'SELECT role, message FROM %s '
			. 'WHERE conversation_uuid = %s '
			. 'AND intent != %s '
			. 'ORDER BY created_at ASC '
			. 'LIMIT %d',
			self::CONVERSATIONS_TABLE,
			$this->quote($conversationUuid),
			$this->quote(Intent::CONTENT_QUESTION),
			$safeLimit
		);

		Buddy::debugvv("├─ SQL: $sql");

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Failed to retrieve conversation history: ' . $result->getError());
		}

		$data = $result->getResult();

		$history = '';

		if (is_array($data[0])) {
			$rows = $data[0]['data'];

			Buddy::debugvv('├─ Filtered messages found: ' . sizeof($rows));
			foreach ($rows as $row) {
				$role = (string)$row['role'];
				$message = (string)$row['message'];
				$history .= "$role: $message\n";
			}
		}

		$historyLength = strlen($history);
		Buddy::debugvv("├─ Filtered history length: $historyLength chars");
		$preview = substr($history, 0, 150);
		if ($historyLength > 150) {
			$preview .= '...';
		}
		Buddy::debugvv('└─ Filtered history preview: ' . $preview);

		return $history;
	}

	/**
	 * @param string $conversationUuid
	 * @param int $limit
	 *
	 * @return array<int, string>
	 * @throws ManticoreSearchClientError|ManticoreSearchResponseError
	 */
	public function getRecentUserIntents(string $conversationUuid, int $limit = 20): array {
		$safeLimit = $this->assertPositiveLimit($limit, 'limit');

		/** @lang Manticore */
		$sql = sprintf(
			'SELECT intent FROM %s '
			. 'WHERE conversation_uuid = %s '
			. 'AND role = %s '
			. 'ORDER BY created_at DESC '
			. 'LIMIT %d',
			self::CONVERSATIONS_TABLE,
			$this->quote($conversationUuid),
			$this->quote('user'),
			$safeLimit
		);

		$response = $this->client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create(
				'Failed to retrieve conversation intents: ' . $response->getError()
			);
		}

		/** @var array<int, array<string, mixed>> $data */
		$data = $response->getResult()->toArray();

		if (!is_array($data[0])) {
			throw ManticoreSearchClientError::create('Manticore returned wrong intent structure');
		}

		/** @var array<int, array{intent: string}> $rows */
		$rows = $data[0]['data'];

		$intents = [];
		foreach ($rows as $row) {
			$intents[] = (string)$row['intent'];
		}

		return $intents;
	}
}
