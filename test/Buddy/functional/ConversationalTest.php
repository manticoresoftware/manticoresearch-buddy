<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class ConversationalTest extends TestCase {

	use TestFunctionalTrait;

	/**
	 * @throws Exception
	 */
	public function testCreateChatModelSuccess(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'test_model'");

		$result = static::runSqlQuery(
			"CREATE CHAT MODEL 'test_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key-123456789',
				style_prompt = 'You are a helpful assistant.',
				retrieval_limit = 5,
				max_document_length = 3000
			)"
		);
		$this->assertIsArray($result);

		// Verify the model was created by checking it exists
		$this->assertQueryResult(
			'SHOW CHAT MODELS',
			['test_model']
		);

		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'test_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testCreateChatModelRejectsNameFieldInBody(): void {
		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'test_model' (
				name = 'Test Chat Model',
				model = 'openai:gpt-4'
			)",
			"Unsupported field 'name'"
		);
	}


	/**
	 * @throws Exception
	 */
	public function testCreateChatModelRejectsTemperatureField(): void {
		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				temperature = 0.3
			)",
			"Unsupported field 'temperature'"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateChatModelRejectsMaxTokensField(): void {
		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				max_tokens = 500
			)",
			"Unsupported field 'max_tokens'"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateChatModelInvalidKResults(): void {
		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				retrieval_limit = 100
			)",
			'retrieval_limit must be an integer between 1 and 50'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateChatModelRejectsNonIntegerKResults(): void {
		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				retrieval_limit = 1.5
			)",
			'retrieval_limit must be an integer between 1 and 50'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateChatModelInvalidTimeout(): void {
		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				timeout = 65537
			)",
			'timeout must be an integer between 1 and 65536'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateChatModelRejectsNonIntegerTimeout(): void {
		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				timeout = 1.5
			)",
			'timeout must be an integer between 1 and 65536'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateChatModelInvalidMaxDocumentLength(): void {
		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				max_document_length = 99
			)",
			'max_document_length must be 0 or an integer between 100 and 65536'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateChatModelRejectsNonIntegerMaxDocumentLength(): void {
		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				max_document_length = 1.5
			)",
			'max_document_length must be 0 or an integer between 100 and 65536'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testShowChatModelsEmpty(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'test_model1'");
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'test_model2'");

		$result = static::runSqlQuery('SHOW CHAT MODELS');
		$this->assertIsArray($result);
	}

	/**
	 * @throws Exception
	 */
	public function testShowChatModelsWithData(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'test_model1'");

		static::runSqlQuery(
			"CREATE CHAT MODEL 'test_model1' (
			model = 'openai:gpt-4',
			api_key = 'sk-test-key-123456789'
		)"
		);

		$this->assertQueryResult(
			'SHOW CHAT MODELS',
			['test_model1', 'openai', 'gpt-4']
		);

		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'test_model1'");
	}

	/**
	 * @throws Exception
	 */
	public function testDescribeChatModelSuccess(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'test_model'");

		static::runSqlQuery(
			"CREATE CHAT MODEL 'test_model' (
			model = 'openai:gpt-4',
			style_prompt = 'You are a helpful assistant.',
			retrieval_limit = 5,
			max_document_length = 0
		)"
		);

		$this->assertQueryResult(
			"DESCRIBE CHAT MODEL 'test_model'",
			['test_model', 'openai:gpt-4', '0']
		);

		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'test_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testDescribeChatModelNotFound(): void {
		$this->assertQueryResultContainsError(
			"DESCRIBE CHAT MODEL 'non_existent_model'",
			"chat model 'non_existent_model' not found"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testDropChatModelSuccess(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'test_model'");

		static::runSqlQuery(
			"CREATE CHAT MODEL 'test_model' (
			model = 'openai:gpt-4',
			api_key = 'sk-test-key-123456789'
		)"
		);

		$result = static::runSqlQuery("DROP CHAT MODEL 'test_model'");
		$this->assertIsArray($result);
	}

	/**
	 * @throws Exception
	 */
	public function testDropChatModelIfExistsNotFoundDoesNotError(): void {
		$output = static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'non_existent_model'");
		$this->assertStringNotContainsString('ERROR', implode(PHP_EOL, $output));

		$output = static::runSqlQuery('DROP CHAT MODEL IF EXISTS non_existent_model');
		$this->assertStringNotContainsString('ERROR', implode(PHP_EOL, $output));
	}

	/**
	 * @throws Exception
	 */
	public function testDropChatModelRejectsTrailingTokens(): void {
		$this->assertQueryResultContainsError(
			'DROP CHAT MODEL non_existent_model garbage',
			'Invalid DROP CHAT MODEL syntax'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testDropChatModelQuotedNameContainingIfExistsStillErrorsWhenMissing(): void {
		$this->assertQueryResultContainsError(
			"DROP CHAT MODEL 'my IF EXISTS model'",
			"chat model 'my IF EXISTS model' not found"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testDropChatModelNotFound(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'non_existent_model'");

		$this->assertQueryResultContainsError(
			"DROP CHAT MODEL 'non_existent_model'",
			"chat model 'non_existent_model' not found"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateDuplicateChatModel(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'duplicate_model'");

		static::runSqlQuery(
			"CREATE CHAT MODEL 'duplicate_model' (
			model = 'openai:gpt-4',
			api_key = 'sk-test-key-123456789'
		)"
		);

		$this->assertQueryResultContainsError(
			"CREATE CHAT MODEL 'duplicate_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key-123456789'
			)",
			"chat model 'duplicate_model' already exists"
		);

		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'duplicate_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testFullModelLifecycle(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'lifecycle_model'");

		static::runSqlQuery(
			"CREATE CHAT MODEL 'lifecycle_model' (
			model = 'openai:gpt-4',
			api_key = 'sk-test-key-123456789',
			style_prompt = 'You are a helpful assistant.',
			retrieval_limit = 5
		)"
		);

		$this->assertQueryResult(
			'SHOW CHAT MODELS',
			['lifecycle_model']
		);

		$this->assertQueryResult(
			"DESCRIBE CHAT MODEL 'lifecycle_model'",
			['lifecycle_model', 'openai', 'gpt-4']
		);

		static::runSqlQuery("DROP CHAT MODEL 'lifecycle_model'");

		$this->assertQueryResultContainsError(
			"DESCRIBE CHAT MODEL 'lifecycle_model'",
			"chat model 'lifecycle_model' not found"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateModelWithMinimalParameters(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'minimal_model'");

		$result = static::runSqlQuery(
			"CREATE CHAT MODEL 'minimal_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key-123456789'
			)"
		);
		$this->assertIsArray($result);

		// Verify the model was created
		$this->assertQueryResult(
			'SHOW CHAT MODELS',
			['minimal_model']
		);

		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'minimal_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testCreateModelWithAllParameters(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'full_model'");

		$result = static::runSqlQuery(
			"CREATE CHAT MODEL 'full_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key-123456789',
				style_prompt = 'You are a helpful assistant with extensive knowledge.',
				retrieval_limit = 10
			)"
		);
		$this->assertIsArray($result);

		$this->assertQueryResult(
			"DESCRIBE CHAT MODEL 'full_model'",
			['full_model', 'You are a helpful assistant with extensive knowledge.']
		);

		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'full_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testRetrievalLimitBoundaryValues(): void {
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'k_min'");

		$result1 = static::runSqlQuery(
			"CREATE CHAT MODEL 'k_min' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				retrieval_limit = 1
			)"
		);
		$this->assertIsArray($result1);

		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'k_min'");
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'k_max'");

		$result2 = static::runSqlQuery(
			"CREATE CHAT MODEL 'k_max' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				retrieval_limit = 50
			)"
		);
		$this->assertIsArray($result2);
		static::runSqlQuery("DROP CHAT MODEL IF EXISTS 'k_max'");
	}
}
