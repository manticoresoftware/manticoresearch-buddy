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
	/**
	 * @param Client $client
	 */
	public function __construct(private Client $client) {
	}

	/**
	 * @param string $conversationId
	 * @param int $modelId
	 * @param string $role
	 * @param string $message
	 * @param array $metadata
	 * @param string $intent
	 * @param int $tokensUsed
	 * @param int $responseTimeMs
	 *
	 * @return bool
	 * @throws ManticoreSearchClientError
	 */
	public function saveMessage(
		string $conversationUuid,
		string $modelUuid,
		string $role,
		string $message,
		array $metadata = [],
		string $intent = '',
		int $tokensUsed = 0,
		int $responseTimeMs = 0
	): bool {

		$currentTime = time();
		$ttlTime = $currentTime + (24 * 60 * 60); // 1 day from now
		$pattern = /** @lang Manticore */ 'INSERT INTO ' . ModelManager::CONVERSATIONS_TABLE . ' '.
			'(conversation_uuid, model_uuid, created_at, role, message, metadata, intent, '.
			'tokens_used, response_time_ms, ttl) VALUES '.
			"('%s', '%d', %d, '%s', '%s', '%s', '%s', %d, %d, %d)";
		$sql = sprintf(
			$pattern,
			addslashes($conversationUuid),
			$modelUuid,
			$currentTime,
			addslashes($role),
			addslashes($message),
			addslashes(json_encode($metadata)),
			addslashes($intent),
			$tokensUsed,
			$responseTimeMs,
			$ttlTime
		);

		$result = $this->client->sendRequest($sql);
		return !$result->hasError();
	}

	/**
	 * @param string $conversationId
	 * @param int $limit
	 *
	 * @return array
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function getConversationHistory(string $conversationUuid, int $limit = 100): array {
		$currentTime = time();
		$sql = /** @lang Manticore */ 'SELECT * FROM ' . ModelManager::CONVERSATIONS_TABLE . ' '.
			"WHERE conversation_uuid = '$conversationUuid' AND ttl > $currentTime ".
			"ORDER BY created_at DESC LIMIT $limit";
		$result = $this->client->sendRequest($sql);
		if ($result->hasError()) {
			return [];
		}
		$data = $result->getResult();
		return array_reverse($data[0]['data'] ?? []);
	}
}
