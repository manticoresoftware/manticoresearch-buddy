<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Handler;
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

		$settings = ['max_document_length' => 1000];

		// Test reflection to access private method
		$reflection = new ReflectionClass(Handler::class);
		$method = $reflection->getMethod('buildContext');
		$method->setAccessible(true);

		// Test single field (backward compatibility)
		$context = $method->invoke(null, $searchResults, $settings, 'content');
		$this->assertIsString($context);
		$this->assertStringContainsString('This is the main content.', $context);
		$this->assertStringContainsString('Complex SQL queries explained.', $context);

		// Test multiple fields with comma separator
		$context = $method->invoke(null, $searchResults, $settings, 'title,content');
		$expected = "Database Basics, This is the main content.\nAdvanced Queries, Complex SQL queries explained.";
		$this->assertEquals($expected, $context);

		// Test three fields
		$context = $method->invoke(null, $searchResults, $settings, 'title,summary,content');
		$expected = "Database Basics, A quick overview of databases., This is the main content.\n" .
			'Advanced Queries, Learn advanced database techniques., Complex SQL queries explained.';
		$this->assertEquals($expected, $context);

		// Test missing field (should skip gracefully)
		$context = $method->invoke(null, $searchResults, $settings, 'title,nonexistent,content');
		$expected = "Database Basics, This is the main content.\nAdvanced Queries, Complex SQL queries explained.";
		$this->assertEquals($expected, $context);
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

		$settings = [];

		$reflection = new ReflectionClass(Handler::class);
		$method = $reflection->getMethod('buildContext');
		$method->setAccessible(true);

		$context = $method->invoke(null, $searchResults, $settings, 'title,content,summary');
		$expected = 'Test, Summary text';  // Empty content should be excluded
		$this->assertEquals($expected, $context);
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

		$settings = [];

		$reflection = new ReflectionClass(Handler::class);
		$method = $reflection->getMethod('buildContext');
		$method->setAccessible(true);

		$context = $method->invoke(null, $searchResults, $settings, 'title,content,summary');
		$expected = 'Test Title, Valid summary';  // Whitespace-only content should be excluded
		$this->assertEquals($expected, $context);
	}

	public function testBuildContextWithEmptyResults(): void {
		$searchResults = [];
		$settings = [];

		$reflection = new ReflectionClass(Handler::class);
		$method = $reflection->getMethod('buildContext');
		$method->setAccessible(true);

		$context = $method->invoke(null, $searchResults, $settings, 'title,content');
		$this->assertEquals('', $context);
	}

	public function testBuildContextWithSingleField(): void {
		$searchResults = [
			[
				'id' => 1,
				'content' => 'Single content field',
			],
		];

		$settings = [];

		$reflection = new ReflectionClass(Handler::class);
		$method = $reflection->getMethod('buildContext');
		$method->setAccessible(true);

		// Test explicit single field
		$context = $method->invoke(null, $searchResults, $settings, 'content');
		$expected = 'Single content field';
		$this->assertEquals($expected, $context);
	}

	public function testBuildContextWithTruncation(): void {
		$searchResults = [
			[
				'id' => 1,
				'title' => 'Short Title',
				'content' => str_repeat('A very long content string. ', 50), // Create long content
			],
		];

		$settings = ['max_document_length' => 50];

		$reflection = new ReflectionClass(Handler::class);
		$method = $reflection->getMethod('buildContext');
		$method->setAccessible(true);

		$context = $method->invoke(null, $searchResults, $settings, 'title,content');
		$this->assertIsString($context);
		$this->assertStringEndsWith('...', $context);
		$this->assertLessThanOrEqual(53, strlen($context)); // Allow for "..." and separator
	}
}
