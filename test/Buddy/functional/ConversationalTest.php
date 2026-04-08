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
	public function testCreateRagModelSuccess(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model'");

		$result = static::runSqlQuery(
			"CREATE RAG MODEL 'test_model' (
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
			'SHOW RAG MODELS',
			['test_model']
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testCreateRagModelRejectsNameFieldInBody(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'test_model' (
				name = 'Test RAG Model',
				model = 'openai:gpt-4'
			)",
			"Unsupported field 'name'"
		);
	}


	/**
	 * @throws Exception
	 */
	public function testCreateRagModelRejectsTemperatureField(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
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
	public function testCreateRagModelRejectsMaxTokensField(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
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
	public function testCreateRagModelInvalidKResults(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
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
	public function testCreateRagModelRejectsNonIntegerKResults(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
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
	public function testCreateRagModelInvalidTimeout(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
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
	public function testCreateRagModelRejectsNonIntegerTimeout(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
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
	public function testCreateRagModelInvalidMaxDocumentLength(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				max_document_length = -2
			)",
			'max_document_length must be an integer between -1 and 65536'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateRagModelRejectsNonIntegerMaxDocumentLength(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				max_document_length = 1.5
			)",
			'max_document_length must be an integer between -1 and 65536'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testShowRagModelsEmpty(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model1'");
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model2'");

		$result = static::runSqlQuery('SHOW RAG MODELS');
		$this->assertIsArray($result);
	}

	/**
	 * @throws Exception
	 */
	public function testShowRagModelsWithData(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model1'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'test_model1' (
			model = 'openai:gpt-4',
			api_key = 'sk-test-key-123456789'
		)"
		);

		$this->assertQueryResult(
			'SHOW RAG MODELS',
			['test_model1', 'openai', 'gpt-4']
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model1'");
	}

	/**
	 * @throws Exception
	 */
	public function testDescribeRagModelSuccess(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			style_prompt = 'You are a helpful assistant.',
			retrieval_limit = 5,
			max_document_length = -1
		)"
		);

		$this->assertQueryResult(
			"DESCRIBE RAG MODEL 'test_model'",
			['test_model', 'openai:gpt-4', '-1']
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testDescribeRagModelNotFound(): void {
		$this->assertQueryResultContainsError(
			"DESCRIBE RAG MODEL 'non_existent_model'",
			"RAG model 'non_existent_model' not found"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testDropRagModelSuccess(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'test_model' (
			model = 'openai:gpt-4',
			api_key = 'sk-test-key-123456789'
		)"
		);

		$result = static::runSqlQuery("DROP RAG MODEL 'test_model'");
		$this->assertIsArray($result);
	}

	/**
	 * @throws Exception
	 */
	public function testDropRagModelIfExistsNotFoundDoesNotError(): void {
		$output = static::runSqlQuery("DROP RAG MODEL IF EXISTS 'non_existent_model'");
		$this->assertStringNotContainsString('ERROR', implode(PHP_EOL, $output));

		$output = static::runSqlQuery('DROP RAG MODEL IF EXISTS non_existent_model');
		$this->assertStringNotContainsString('ERROR', implode(PHP_EOL, $output));
	}

	/**
	 * @throws Exception
	 */
	public function testDropRagModelRejectsTrailingTokens(): void {
		$this->assertQueryResultContainsError(
			'DROP RAG MODEL non_existent_model garbage',
			'Invalid DROP RAG MODEL syntax'
		);
	}

	/**
	 * @throws Exception
	 */
	public function testDropRagModelQuotedNameContainingIfExistsStillErrorsWhenMissing(): void {
		$this->assertQueryResultContainsError(
			"DROP RAG MODEL 'my IF EXISTS model'",
			"RAG model 'my IF EXISTS model' not found"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testDropRagModelNotFound(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'non_existent_model'");

		$this->assertQueryResultContainsError(
			"DROP RAG MODEL 'non_existent_model'",
			"RAG model 'non_existent_model' not found"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateDuplicateRagModel(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'duplicate_model'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'duplicate_model' (
			model = 'openai:gpt-4',
			api_key = 'sk-test-key-123456789'
		)"
		);

		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'duplicate_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key-123456789'
			)",
			"RAG model 'duplicate_model' already exists"
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'duplicate_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testFullModelLifecycle(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'lifecycle_model'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'lifecycle_model' (
			model = 'openai:gpt-4',
			api_key = 'sk-test-key-123456789',
			style_prompt = 'You are a helpful assistant.',
			retrieval_limit = 5
		)"
		);

		$this->assertQueryResult(
			'SHOW RAG MODELS',
			['lifecycle_model']
		);

		$this->assertQueryResult(
			"DESCRIBE RAG MODEL 'lifecycle_model'",
			['lifecycle_model', 'openai', 'gpt-4']
		);

		static::runSqlQuery("DROP RAG MODEL 'lifecycle_model'");

		$this->assertQueryResultContainsError(
			"DESCRIBE RAG MODEL 'lifecycle_model'",
			"RAG model 'lifecycle_model' not found"
		);
	}

	/**
	 * @throws Exception
	 */
	public function testCreateModelWithMinimalParameters(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'minimal_model'");

		$result = static::runSqlQuery(
			"CREATE RAG MODEL 'minimal_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key-123456789'
			)"
		);
		$this->assertIsArray($result);

		// Verify the model was created
		$this->assertQueryResult(
			'SHOW RAG MODELS',
			['minimal_model']
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'minimal_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testCreateModelWithAllParameters(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'full_model'");

		$result = static::runSqlQuery(
			"CREATE RAG MODEL 'full_model' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key-123456789',
				style_prompt = 'You are a helpful assistant with extensive knowledge.',
				retrieval_limit = 10
			)"
		);
		$this->assertIsArray($result);

		$this->assertQueryResult(
			"DESCRIBE RAG MODEL 'full_model'",
			['full_model', 'You are a helpful assistant with extensive knowledge.']
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'full_model'");
	}

	/**
	 * @throws Exception
	 */
	public function testRetrievalLimitBoundaryValues(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'k_min'");

		$result1 = static::runSqlQuery(
			"CREATE RAG MODEL 'k_min' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				retrieval_limit = 1
			)"
		);
		$this->assertIsArray($result1);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'k_min'");
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'k_max'");

		$result2 = static::runSqlQuery(
			"CREATE RAG MODEL 'k_max' (
				model = 'openai:gpt-4',
				api_key = 'sk-test-key',
				retrieval_limit = 50
			)"
		);
		$this->assertIsArray($result2);
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'k_max'");
	}
}
