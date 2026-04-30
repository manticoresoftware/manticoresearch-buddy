<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Tool;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;

final class LlmToolCallArgumentsReader {
	/**
	 * @param array<int, mixed> $toolCalls
	 * @return array<string, mixed>
	 * @throws ManticoreSearchClientError
	 */
	public function read(array $toolCalls, string $errorPrefix): array {
		if ($toolCalls === []) {
			throw ManticoreSearchClientError::create("$errorPrefix returned no tool calls");
		}

		$toolCall = $toolCalls[0];
		if (!$toolCall instanceof \ToolCall) {
			throw ManticoreSearchClientError::create("$errorPrefix returned invalid tool call");
		}

		$arguments = $toolCall->getArguments();
		if (is_array($arguments)) {
			return $arguments;
		}

		if (!is_string($arguments)) {
			throw ManticoreSearchClientError::create("$errorPrefix returned invalid tool arguments");
		}

		try {
			/** @var mixed $decoded */
			$decoded = simdjson_decode($arguments, true);
		} catch (\Throwable $e) {
			throw ManticoreSearchClientError::create(
				"$errorPrefix returned invalid tool arguments: " . $e->getMessage()
			);
		}

		if (!is_array($decoded)) {
			throw ManticoreSearchClientError::create("$errorPrefix returned invalid tool arguments");
		}

		return $decoded;
	}
}
