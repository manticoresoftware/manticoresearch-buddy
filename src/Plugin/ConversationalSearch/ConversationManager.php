<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

use JsonException;
use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\Lib\SqlEscapingTrait;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

class ConversationManager {
	use SqlEscapingTrait;

	private const int CONVERSATION_TTL_SECONDS = 30 * 24 * 60 * 60; // 30 days
	private const int TIMESTAMP_PRECISION = 1000000;
	private readonly string $tableName;

	/**
	 * @throws ManticoreSearchClientError
	 * @throws QueryParseError
	 */
	public function __construct(
		private readonly Client $client,
		string $modelName
	) {
		$this->tableName = ResourceTable::name(ResourceTable::RESOURCE_CHAT_HISTORY, $modelName);
		$this->initializeTable();
	}

	/**
	 * Initialize conversations table if it doesn't exist
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function initializeTable(): void {
		// Keep existing history tables intact; new tables store messages and their model names.
		$sql
			= /** @lang Manticore */
			'CREATE TABLE IF NOT EXISTS ' . $this->tableName . ' (
			conversation_uuid string,
			`model_name` string,
			created_at bigint,
			role string,
			message text,
			ttl bigint
		)';

		$response = $this->client->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create('Failed to create conversations table: ' . $response->getError());
		}
	}

	/**
	 * @param string $conversationUuid
	 * @param string $modelName
	 * @param string $role
	 * @param string $message
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	public function saveMessage(
		string $conversationUuid,
		string $modelName,
		string $role,
		string $message
	): void {
		$this->insertMessages($conversationUuid, $modelName, [['role' => $role, 'message' => $message]]);
	}

	/**
	 * @param string $indexName Target search index, not the history table
	 * @param list<string>|null $excludedIds
	 * @throws JsonException|ManticoreSearchClientError
	 */
	public function saveTurn(
		string $conversationUuid,
		string $modelName,
		string $userMessage,
		string $assistantMessage,
		string $indexName,
		?array $excludedIds = null
	): void {
		$messages = [
			['role' => 'user', 'message' => $userMessage],
			['role' => 'assistant', 'message' => $assistantMessage],
		];
		if ($excludedIds !== null) {
			$messages[] = [
				'role' => 'excluded_ids:' . $indexName,
				'message' => json_encode($excludedIds, JSON_THROW_ON_ERROR),
			];
		}
		$this->insertMessages($conversationUuid, $modelName, $messages);
	}

	/**
	 * @param list<array{role:string, message:string}> $messages
	 * @throws ManticoreSearchClientError
	 */
	private function insertMessages(string $conversationUuid, string $modelName, array $messages): void {
		$currentTime = (int)(microtime(true) * self::TIMESTAMP_PRECISION);
		$ttlTime = time() + self::CONVERSATION_TTL_SECONDS;
		$values = [];
		foreach ($messages as $message) {
			$values[] = sprintf(
				'(%s, %s, %d, %s, %s, %d)',
				$this->quote($conversationUuid),
				$this->quote($modelName),
				$currentTime,
				$this->quote($message['role']),
				$this->quote($message['message']),
				$ttlTime
			);
		}
		$sql = sprintf(
		/** @lang manticore */
			'INSERT INTO %s (conversation_uuid, `model_name`, created_at, role, message, ttl) '
			. 'VALUES %s',
			$this->tableName,
			implode(', ', $values)
		);

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create(
				'Failed to insert into conversations table: ' . $result->getError()
			);
		}
	}

	/**
	 * @param string $conversationUuid
	 * @param int $limit
	 * @param string $indexName Target search index, not the history table
	 *
	 * @return ConversationHistory
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function getConversationMessages(
		string $conversationUuid,
		int $limit = 100,
		string $indexName = ''
	): ConversationHistory {
		$excludedRole = $indexName === '' ? null : 'excluded_ids:' . $indexName;
		/** @lang Manticore */
		$sql = sprintf(
		/** @lang manticore */
			'SELECT role, message FROM %s '
			. 'WHERE conversation_uuid = %s '
			. 'ORDER BY created_at DESC, id DESC '
			. 'LIMIT %d',
			$this->tableName,
			$this->quote($conversationUuid),
			$limit
		);

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Failed to retrieve conversation history: ' . $result->getError());
		}

		$data = $result->getResult();
		if (!is_array($data[0])) {
			return new ConversationHistory([]);
		}
		$messages = [];
		$excludedIdsJson = null;
		foreach ($data[0]['data'] as $row) {
			if ($excludedRole !== null && $row['role'] === $excludedRole) {
				if ($excludedIdsJson === null) {
					$excludedIdsJson = (string)$row['message'];
				}
				continue;
			}
			if ($row['role'] !== 'user' && $row['role'] !== 'assistant') {
				continue;
			}
			$messages[] = ['role' => (string)$row['role'], 'message' => (string)$row['message']];
		}

		return new ConversationHistory(array_reverse($messages), $excludedIdsJson);
	}

}
