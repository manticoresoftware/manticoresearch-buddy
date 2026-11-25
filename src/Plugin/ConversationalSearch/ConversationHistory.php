<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

final readonly class ConversationHistory {
	/**
	 * @param list<array{role: string, message: string}> $messages
	 */
	public function __construct(private array $messages, private ?string $excludedIdsJson = null) {
	}

	/**
	 * @return list<array{role: string, message: string}>
	 */
	public function messages(): array {
		return $this->messages;
	}

	/** @return list<string> */
	public function excludedIds(): array {
		if ($this->excludedIdsJson === null) {
			return [];
		}
		/** @var list<string> $ids */
		$ids = simdjson_decode($this->excludedIdsJson, true);
		return $ids;
	}

	public function format(): string {
		$history = '';
		foreach ($this->messages as $message) {
			$history .= $message['role'] . ': ' . $message['message'] . "\n";
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
			if ($message['role'] === 'user') {
				$currentTimestamp = $this->formatHistoryTimestamp(sizeof($turns));
				$turns[$currentTimestamp] = [
					'user' => $message['message'],
				];
				continue;
			}

			if ($message['role'] !== 'assistant') {
				continue;
			}

			if ($currentTimestamp === null) {
				$currentTimestamp = $this->formatHistoryTimestamp(sizeof($turns));
				$turns[$currentTimestamp] = [];
			}

			$turns[$currentTimestamp]['assistant'] = $message['message'];
		}

		return $turns;
	}

	private function formatHistoryTimestamp(int $turnIndex): string {
		return gmdate('Y-m-d\TH:i:s', $turnIndex) . '.000000Z';
	}
}
