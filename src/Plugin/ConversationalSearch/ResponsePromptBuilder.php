<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalSearch;

use JsonException;

final class ResponsePromptBuilder {
	public function __construct(private readonly int $maxTokens) {
	}

	/**
	 * @param array<string, array{user?: string, assistant?: string}> $history
	 * @throws JsonException
	 */
	public function build(
		string $query,
		string $context,
		array $history,
		string $customPrompt = '',
		bool $smallTalk = false
	): string {
		$customPrompt = trim($customPrompt);
		$historyJson = json_encode($history, JSON_THROW_ON_ERROR);
		if ($smallTalk) {
			return ($customPrompt !== '' ? $customPrompt . "\n" : '')
				. 'Respond briefly and naturally in the same language to the conversational message '
				. 'unrelated to indexed data. '
				. 'Do not answer factual questions, recommend documents, or invent search results. '
				. "<history>\n$historyJson\n</history>\n"
				. "<message>$query</message>";
		}

		$prompt = $customPrompt !== ''
			? $customPrompt
			: 'Respond conversationally. Response should be based ONLY on the provided history and context sections' .
				"(IMPORTANT !!! You can't use your own knowledge to add anything that isn't mentioned there). " .
				'Do not exceed the response token limit (' .
				$this->maxTokens .
				'), and end the answer cleanly before reaching it.';

		return $prompt . ' ' .
			'<main>' .
			"<history>\n" .
			"```json\n" .
			$historyJson .
			"\n```\n" .
			"</history>\n" .
			"<context>$context</context>\n" .
			"<query>$query</query>\n" .
			'</main>';
	}
}
