<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)
 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ResponsePromptBuilder;
use PHPUnit\Framework\TestCase;

final class ResponsePromptBuilderTest extends TestCase {
	private const string SEARCH_INSTRUCTION = 'Respond conversationally. Response should be based ONLY on the '
		. 'provided history and context sections'
		. "(IMPORTANT !!! You can't use your own knowledge to add anything that isn't mentioned there). "
		. 'Do not exceed the response token limit (4096), and end the answer cleanly before reaching it.';
	private const string CHAT_INSTRUCTION = 'Respond briefly and naturally in the same language to the conversational '
		. 'message unrelated to indexed data. '
		. 'Do not answer factual questions, recommend documents, or invent search results. ';

	/** @dataProvider promptCases */
	public function testPromptTextIsPreserved(bool $smallTalk, string $customPrompt, string $prefix): void {
		$history = ['turn' => ['user' => 'Earlier question', 'assistant' => 'Earlier answer']];
		$historyJson = '{"turn":{"user":"Earlier question","assistant":"Earlier answer"}}';
		$expected = $smallTalk
			? ($prefix !== '' ? $prefix . "\n" : '') . self::CHAT_INSTRUCTION
				. "<history>\n$historyJson\n</history>\n<message>Current question</message>"
			: $prefix . " <main><history>\n```json\n$historyJson\n```\n</history>\n"
				. "<context>Retrieved context</context>\n<query>Current question</query>\n</main>";

		$this->assertSame(
			$expected,
			(new ResponsePromptBuilder(4096))->build(
				'Current question', 'Retrieved context', $history, $customPrompt, $smallTalk
			)
		);
	}

	/** @return array<string, array{bool, string, string}> */
	public static function promptCases(): array {
		return [
			'search-default' => [false, '', self::SEARCH_INSTRUCTION],
			'search-custom' => [false, "\n Be concise.\t", 'Be concise.'],
			'search-blank' => [false, " \n", self::SEARCH_INSTRUCTION],
			'chat-default' => [true, '', ''],
			'chat-custom' => [true, "\n Be concise.\t", 'Be concise.'],
			'chat-blank' => [true, " \n", ''],
		];
	}

	public function testSearchPromptUsesConfiguredTokenLimit(): void {
		$prompt = (new ResponsePromptBuilder(256))->build('Question', '', []);
		$this->assertStringContainsString('Do not exceed the response token limit (256)', $prompt);
		$this->assertStringContainsString("<history>\n```json\n[]\n```\n</history>", $prompt);
	}

	/** @dataProvider promptModes */
	public function testInvalidHistoryEncodingFails(bool $smallTalk): void {
		$this->expectException(JsonException::class);
		(new ResponsePromptBuilder(4096))->build('Question', '', ['turn' => ['user' => "\xFF"]], '', $smallTalk);
	}

	/** @return array<string, array{bool}> */
	public static function promptModes(): array {
		return ['search' => [false], 'chat' => [true]];
	}
}
