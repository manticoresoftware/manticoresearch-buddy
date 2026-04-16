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

final readonly class ConversationHistory {
	/**
	 * @param array<int, ConversationMessage> $messages
	 */
	public function __construct(private array $messages) {
	}

	/**
	 * @return array<int, ConversationMessage>
	 */
	public function messages(): array {
		return $this->messages;
	}

	public function format(): string {
		$history = '';
		foreach ($this->messages as $message) {
			$history .= $message->role . ': ' . $message->message . "\n";
		}

		return $history;
	}

	/**
	 * @return array<string, array{user?: string, assistant?: string}>
	 */
	public function payload(int $maxExchanges = 10): array {
		$maxMessages = $maxExchanges * 2;
		$messages = $this->messages;
		if (sizeof($messages) > $maxMessages) {
			$messages = array_slice($messages, -$maxMessages);
		}

		$turns = [];
		$currentTimestamp = null;
		foreach ($messages as $message) {
			if ($message->role === 'user') {
				$currentTimestamp = $this->formatHistoryTimestamp(sizeof($turns));
				$turns[$currentTimestamp] = [
					'user' => $message->message,
				];
				continue;
			}

			if ($message->role !== 'assistant') {
				continue;
			}

			if ($currentTimestamp === null) {
				$currentTimestamp = $this->formatHistoryTimestamp(sizeof($turns));
				$turns[$currentTimestamp] = [];
			}

			$turns[$currentTimestamp]['assistant'] = $message->message;
		}

		return $turns;
	}

	/**
	 * @return array{search_query: string, exclude_query: string, excluded_ids: string}|null
	 * @throws ManticoreSearchClientError
	 */
	public function latestSearchContext(): ?array {
		for ($i = sizeof($this->messages) - 1; $i >= 0; $i--) {
			$message = $this->messages[$i];
			if ($message->role !== 'user' || $message->intent === Intent::FOLLOW_UP) {
				continue;
			}

			if ($message->excludedIds !== '') {
				$this->decodeExcludedIds($message->excludedIds);
			}

			return [
				'search_query' => $message->searchQuery,
				'exclude_query' => $message->excludeQuery,
				'excluded_ids' => $message->excludedIds,
			];
		}

		return null;
	}

	/**
	 * @return array<int, string|int>
	 * @throws ManticoreSearchClientError
	 */
	public function activeExcludedIds(): array {
		$excludedIds = [];
		for ($i = sizeof($this->messages) - 1; $i >= 0; $i--) {
			$message = $this->messages[$i];
			if ($message->role !== 'user') {
				continue;
			}

			if ($message->excludedIds !== '') {
				array_push($excludedIds, ...$this->decodeExcludedIds($message->excludedIds));
			}

			if ($message->intent === Intent::NEW) {
				break;
			}
		}

		return array_values(array_unique($excludedIds, SORT_REGULAR));
	}

	public function consecutiveExpansionCount(): int {
		$count = 0;
		for ($i = sizeof($this->messages) - 1; $i >= 0; $i--) {
			$message = $this->messages[$i];
			if ($message->role !== 'user') {
				continue;
			}

			if ($message->intent !== Intent::EXPAND) {
				break;
			}

			$count++;
		}

		return $count;
	}

	private function formatHistoryTimestamp(int $turnIndex): string {
		return gmdate('Y-m-d\TH:i:s', $turnIndex) . '.000000Z';
	}

	/**
	 * @return array<int, string|int>
	 * @throws ManticoreSearchClientError
	 */
	private function decodeExcludedIds(string $rawExcludedIds): array {
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

		$excludedIds = [];
		foreach ($decoded as $excludedId) {
			if (!is_int($excludedId) && !is_string($excludedId)) {
				throw ManticoreSearchClientError::create('Invalid JSON stored in excluded_ids');
			}

			$excludedIds[] = $excludedId;
		}

		return $excludedIds;
	}
}
