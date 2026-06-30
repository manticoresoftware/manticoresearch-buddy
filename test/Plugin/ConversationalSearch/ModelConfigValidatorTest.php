<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ModelConfigValidator;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use PHPUnit\Framework\TestCase;

class ModelConfigValidatorTest extends TestCase {
	/**
	 * @dataProvider invalidCustomPromptProvider
	 */
	public function testRejectsBlankCustomPrompt(string $customPrompt): void {
		$validator = new ModelConfigValidator();

		try {
			$validator->validate(
				[
					'identifier' => 'assistant',
					'model' => 'openai:gpt-4o-mini',
					'custom_prompt' => $customPrompt,
				]
			);

			$this->fail('Expected QueryParseError');
		} catch (QueryParseError $e) {
			$this->assertSame('custom_prompt must be a non-empty string', $e->getResponseError());
		}
	}

	public function testRejectsCustomPromptAboveMaximumLength(): void {
		$validator = new ModelConfigValidator();

		try {
			$validator->validate(
				[
					'identifier' => 'assistant',
					'model' => 'openai:gpt-4o-mini',
					'custom_prompt' => str_repeat('a', 32769),
				]
			);

			$this->fail('Expected QueryParseError');
		} catch (QueryParseError $e) {
			$this->assertSame('custom_prompt must be at most 32768 bytes', $e->getResponseError());
		}
	}

	/**
	 * @dataProvider validCustomPromptProvider
	 */
	public function testAcceptsNonBlankCustomPromptWithinMaximumLength(string $customPrompt): void {
		$validator = new ModelConfigValidator();

		$config = $validator->validate(
			[
				'identifier' => 'assistant',
				'model' => 'openai:gpt-4o-mini',
				'custom_prompt' => $customPrompt,
			]
		);

		$this->assertArrayHasKey('custom_prompt', $config);
		/** @var array{custom_prompt: string} $config */
		$this->assertSame($customPrompt, $config['custom_prompt']);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function invalidCustomPromptProvider(): array {
		return [
			'empty string' => [''],
			'spaces only' => ['   '],
			'tabs only' => ["\t\t"],
			'newlines only' => ["\n\n"],
			'mixed whitespace only' => [" \t\n\r\0\x0B"],
		];
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function validCustomPromptProvider(): array {
		return [
			'single character' => ['x'],
			'padded text' => ['  answer using source ids  '],
			'maximum length' => [str_repeat('a', 32768)],
		];
	}
}
