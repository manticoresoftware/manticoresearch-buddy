<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Handler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\SourceContextBuilder;
use PHPUnit\Framework\TestCase;

class ContentFieldsTest extends TestCase {
	public static function setUpBeforeClass(): void {
		if (getenv('SEARCHD_CONFIG')) {
			return;
		}
		if (!is_dir('/etc/manticore')) {
			mkdir('/etc/manticore', 0755, true);
		}
		touch('/etc/manticore/manticore.conf');
		putenv('SEARCHD_CONFIG=/etc/manticore/manticore.conf');
	}

	public function testBuildContextWithMultipleFields(): void {
		$searchResults = [
			[
				'id' => 1,
				'title' => 'Database Basics',
				'content' => 'This is the main content.',
				'summary' => 'A quick overview of databases.',
				'vector_field' => [0.1, 0.2, 0.3], // This should be filtered out
			],
			[
				'id' => 2,
				'title' => 'Advanced Queries',
				'content' => 'Complex SQL queries explained.',
				'summary' => 'Learn advanced database techniques.',
				'vector_field' => [0.4, 0.5, 0.6],
			],
		];
		// Test single field (backward compatibility)
		$context = $this->buildContext($searchResults, 'content', 1000);
		$this->assertIsString($context);
		$sources = $this->decodeSources($context);
		$this->assertSame('1', $sources[0]['id']);
		$this->assertSame('This is the main content.', $sources[0]['content']);
		$this->assertSame('Complex SQL queries explained.', $sources[1]['content']);
		$this->assertArrayNotHasKey('vector_field', $sources[0]);
		$this->assertArrayNotHasKey('title', $sources[0]);

		// Test multiple fields with comma separator
		$context = $this->buildContext($searchResults, 'title,content', 1000);
		$sources = $this->decodeSources($context);
		$this->assertSame('Database Basics', $sources[0]['title']);
		$this->assertSame('This is the main content.', $sources[0]['content']);

		// Test three fields
		$context = $this->buildContext($searchResults, 'title,summary,content', 1000);
		$sources = $this->decodeSources($context);
		$this->assertSame('A quick overview of databases.', $sources[0]['summary']);
		$this->assertSame('Learn advanced database techniques.', $sources[1]['summary']);

		// Test missing field (should skip gracefully)
		$context = $this->buildContext($searchResults, 'title,nonexistent,content', 1000);
		$sources = $this->decodeSources($context);
		$this->assertSame('Database Basics', $sources[0]['title']);
		$this->assertSame('This is the main content.', $sources[0]['content']);
	}

	public function testBuildContextWithEmptyFields(): void {
		$searchResults = [
			[
				'id' => 1,
				'title' => 'Test',
				'content' => '',  // Empty content should be skipped
				'summary' => 'Summary text',
			],
		];

		$context = $this->buildContext($searchResults, 'title,content,summary', 1000);
		$sources = $this->decodeSources($context);
		$this->assertSame('Test', $sources[0]['title']);
		$this->assertSame('Summary text', $sources[0]['summary']);
		$this->assertArrayNotHasKey('content', $sources[0]);
	}

	public function testBuildContextWithWhitespaceFields(): void {
		$searchResults = [
			[
				'id' => 1,
				'title' => 'Test Title',
				'content' => '   ',  // Whitespace-only content should be skipped
				'summary' => 'Valid summary',
			],
		];

		$context = $this->buildContext($searchResults, 'title,content,summary', 1000);
		$sources = $this->decodeSources($context);
		$this->assertSame('Test Title', $sources[0]['title']);
		$this->assertSame('Valid summary', $sources[0]['summary']);
		$this->assertArrayNotHasKey('content', $sources[0]);
	}

	public function testBuildContextWithEmptyResults(): void {
		$searchResults = [];
		$context = $this->buildContext($searchResults, 'title,content', 1000);
		$this->assertEquals('', $context);
	}

	public function testBuildContextWithSingleField(): void {
		$searchResults = [
			[
				'id' => 1,
				'content' => 'Single content field',
			],
		];

		// Test explicit single field
		$context = $this->buildContext($searchResults, 'content', 1000);
		$sources = $this->decodeSources($context);
		$this->assertSame('1', $sources[0]['id']);
		$this->assertSame('Single content field', $sources[0]['content']);
	}

	public function testBuildContextWithTruncation(): void {
		$searchResults = [
			[
				'id' => 1,
				'title' => 'Short Title',
				'content' => str_repeat('A very long content string. ', 120), // Exceeds the internal 2000-char limit
			],
		];

		$context = $this->buildContext($searchResults, 'title,content', 80);
		$this->assertIsString($context);
		$sources = $this->decodeSources($context);
		$this->assertIsString($sources[0]['content']);
		$this->assertStringEndsWith('...', $sources[0]['content']);
		$this->assertLessThanOrEqual(80, strlen((string)json_encode($sources[0], JSON_THROW_ON_ERROR)));
	}

	public function testBuildContextReturnsValidJsonAndCropsLongestSourceFields(): void {
		$searchResults = [
			[
				'id' => 7713940850845155334,
				'content' => str_repeat('Breaking Bad crime drama. ', 20),
				'title' => 'Breaking Bad',
				'knn_dist' => 0.69800115,
			],
			[
				'id' => 7713940850845155336,
				'content' => str_repeat('Stranger Things television series. ', 20),
				'title' => 'Stranger Things',
				'knn_dist' => 0.77477241,
			],
		];

		$context = $this->buildContext($searchResults, 'content', 120);

		$this->assertIsString($context);
		$sources = $this->decodeSources($context);
		$this->assertCount(2, $sources);
		$this->assertSame('7713940850845155334', $sources[0]['id']);
		$this->assertArrayNotHasKey('title', $sources[0]);
		$this->assertArrayNotHasKey('knn_dist', $sources[0]);
		$this->assertIsString($sources[0]['content']);
		$this->assertStringEndsWith('...', $sources[0]['content']);
		$this->assertLessThanOrEqual(120, strlen((string)json_encode($sources[0], JSON_THROW_ON_ERROR)));
		$this->assertLessThanOrEqual(120, strlen((string)json_encode($sources[1], JSON_THROW_ON_ERROR)));
	}

	public function testBuildContextStopsCroppingWhenJsonCannotFitBudget(): void {
		$context = $this->buildContext(
			[
				[
					'id' => 123456789,
					'content' => 'Text that cannot fit next to id in a tiny JSON budget.',
				],
			],
			'content',
			1
		);

		$sources = $this->decodeSources($context);
		$this->assertSame('123456789', $sources[0]['id']);
		$this->assertSame('', $sources[0]['content']);
	}

	public function testBuildContextRemovesNonStringFieldsFromLlmContext(): void {
		$searchResults = [
			[
				'id' => 42,
				'content' => 'Visible source text',
				'title' => 'Not part of requested vector source fields',
				'year' => 2024,
				'knn_dist' => 0.15,
				'active' => true,
				'payload' => ['nested' => 'value'],
			],
		];

		$context = $this->buildContext($searchResults, 'content', 1000);
		$sources = $this->decodeSources($context);

		$this->assertSame(['id', 'content'], array_keys($sources[0]));
		$this->assertSame('42', $sources[0]['id']);
		$this->assertSame('Visible source text', $sources[0]['content']);
	}

	public function testBuildContextWithoutTruncationWhenDisabled(): void {
		$searchResults = [
			[
				'id' => 1,
				'title' => 'Short Title',
				'content' => str_repeat('A very long content string. ', 120),
			],
		];

		$context = $this->buildContext($searchResults, 'title,content', 0);
		$this->assertIsString($context);
		$sources = $this->decodeSources($context);
		$this->assertIsString($sources[0]['content']);
		$this->assertStringNotContainsString('...', $sources[0]['content']);
	}

	public function testBuildContextCropsLongerFieldsBeforeShorterFields(): void {
		$searchResults = [
			[
				'id' => 1,
				'a' => str_repeat('a', 2000),
				'b' => str_repeat('b', 500),
				'c' => str_repeat('c', 10),
			],
		];

		$context = $this->buildContext($searchResults, 'a,b,c', 800);
		$sources = $this->decodeSources($context);

		$this->assertLessThanOrEqual(800, strlen((string)json_encode($sources[0], JSON_THROW_ON_ERROR)));
		$this->assertIsString($sources[0]['a']);
		$this->assertIsString($sources[0]['b']);
		$this->assertIsString($sources[0]['c']);
		$this->assertSame(str_repeat('c', 10), $sources[0]['c']);
		$this->assertStringEndsWith('...', $sources[0]['a']);
		$this->assertStringEndsWith('...', $sources[0]['b']);
	}

	/**
	 * @param array<int, array<string, mixed>> $searchResults
	 */
	private function buildContext(array $searchResults, string $contentFields, int $maxDocumentLength): string {
		return (new SourceContextBuilder())->build($searchResults, $contentFields, $maxDocumentLength);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function decodeSources(string $context): array {
		/** @var array<int, array<string, mixed>> $sources */
		$sources = simdjson_decode($context, true);

		return $sources;
	}

	public function testGetLlmRequestOptionsUsesFixedResponseLimit(): void {
		$reflection = new ReflectionClass(Handler::class);
		$method = $reflection->getMethod('getLlmRequestOptions');
		$method->setAccessible(true);

		/** @var array<string, int|float> $options */
		$options = $method->invoke(null);
		$this->assertSame(4096, $options['max_tokens']);
	}
}
