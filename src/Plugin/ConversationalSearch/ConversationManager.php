<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Lib\SqlEscapingTrait;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

class ConversationManager {
	use SqlEscapingTrait;

	public const string CONVERSATIONS_TABLE = 'chat_conversations';
	private const int CONVERSATION_TTL_SECONDS = 30 * 24 * 60 * 60; // 30 days
	private const int TIMESTAMP_PRECISION = 1000000;

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
	 * @param ConversationMessage $message
	 * @param int $tokensUsed
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	public function saveMessage(
		string $conversationUuid,
		string $modelUuid,
		ConversationMessage $message,
		int $tokensUsed = 0
	): void {
		$currentTime = (int)(microtime(true) * self::TIMESTAMP_PRECISION);
		$ttlTime = time() + self::CONVERSATION_TTL_SECONDS;

		$sql = sprintf(
		/** @lang manticore */
			'INSERT INTO %s (conversation_uuid, model_uuid, created_at, role, message, tokens_used, '
			. 'intent, search_query, exclude_query, excluded_ids, ttl) '
			. 'VALUES (%s, %s, %d, %s, %s, %d, %s, %s, %s, %s, %d)',
			self::CONVERSATIONS_TABLE,
			$this->quote($conversationUuid),
			$this->quote($modelUuid),
			$currentTime,
			$this->quote($message->role),
			$this->quote($message->message),
			$tokensUsed,
			"'$message->intent'",
			$message->searchQuery === '' ? "''" : $this->quote($message->searchQuery),
			$message->excludeQuery === '' ? "''" : $this->quote($message->excludeQuery),
			$this->quote($message->excludedIds),
			$ttlTime
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
	 *
	 * @return ConversationHistory
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function getConversationMessages(string $conversationUuid, int $limit = 100): ConversationHistory {
		/** @lang Manticore */
		$sql = sprintf(
		/** @lang manticore */
			'SELECT role, message, intent, search_query, exclude_query, excluded_ids FROM %s '
			. 'WHERE conversation_uuid = %s '
			. 'ORDER BY created_at ASC, id ASC '
			. 'LIMIT %d',
			self::CONVERSATIONS_TABLE,
			$this->quote($conversationUuid),
			$limit
		);

		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			throw ManticoreSearchClientError::create('Failed to retrieve conversation history: ' . $result->getError());
		}

		$data = $result->getResult();
		$messages = [];
		if (is_array($data[0])) {
			$rows = $data[0]['data'];
			foreach ($rows as $row) {
				$messages[] = ConversationMessage::fromStored(
					(string)$row['role'],
					(string)$row['message'],
					(string)$row['intent'],
					(string)$row['search_query'],
					(string)$row['exclude_query'],
					(string)$row['excluded_ids']
				);
			}
		}

		return new ConversationHistory($messages);
	}

}
