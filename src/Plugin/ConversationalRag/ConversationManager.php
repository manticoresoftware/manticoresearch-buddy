<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

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
		$sql = /** @lang Manticore */ 'CREATE TABLE IF NOT EXISTS ' . self::CONVERSATIONS_TABLE . ' (
			conversation_uuid string,
			model_uuid string,
			created_at bigint,
			role text,
			message text,
			tokens_used int,
			ttl bigint
		)';

		$client->sendRequest($sql);
	}

	/**
	 * @param string $conversationId
	 * @param int $modelId
	 * @param string $role
	 * @param string $message
	 * @param int $tokensUsed
	 *
	 * @return bool
	 * @throws ManticoreSearchClientError
	 */
	public function saveMessage(
		string $conversationUuid,
		string $modelUuid,
		string $role,
		string $message,
		int $tokensUsed = 0
	): bool {

		$currentTime = time();
		$ttlTime = $currentTime + (24 * 60 * 60); // 1 day from now
		$sql = sprintf(
			/** @lang Manticore */            'INSERT INTO %s (conversation_uuid, model_uuid, created_at, role, message, tokens_used, ttl) VALUES (%s, %s, %d, %s, %s, %d, %d)',
			self::CONVERSATIONS_TABLE,
			$this->quote($conversationUuid),
			$this->quote($modelUuid),
			$currentTime,
			$this->quote($role),
			$this->quote($message),
			$tokensUsed,
			$ttlTime
		);

		$result = $this->client->sendRequest($sql);
		return !$result->hasError();
	}

	/**
	 * @param string $conversationId
	 * @param int $limit
	 *
	 * @return string
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function getConversationHistory(string $conversationUuid, int $limit = 100): string {
		$currentTime = time();
		$sql = /** @lang Manticore */ 'SELECT role, message FROM ' . self::CONVERSATIONS_TABLE . ' '.
			"WHERE conversation_uuid = '$conversationUuid' AND ttl > $currentTime ".
			"ORDER BY created_at ASC LIMIT $limit";
		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			return '';
		}
		$data = $result->getResult();
		$history = '';
		foreach (($data[0]['data'] ?? []) as $row) {
			$history .= "{$row['role']}: {$row['message']}\n";
		}
		return $history;
	}
}
