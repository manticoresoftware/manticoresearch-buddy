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

	public function testCreateRagModelSuccess(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model'");

		$result = static::runSqlQuery(
			"CREATE RAG MODEL 'test_model' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key-123456789',
				llm_base_url = 'https://api.openai.com/v1',
				style_prompt = 'You are a helpful assistant.',
				temperature = 0.7,
				max_tokens = 1000,
				k_results = 5
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


	public function testCreateRagModelInvalidProvider(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
				llm_provider = 'invalid_provider',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key'
			)",
			"Invalid LLM provider: invalid_provider. Only 'openai' is supported."
		);
	}

	public function testCreateRagModelInvalidTemperature(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key',
				temperature = 3.0
			)",
			'Temperature must be between 0 and 2'
		);
	}

	public function testCreateRagModelInvalidMaxTokens(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key',
				max_tokens = 50000
			)",
			'max_tokens must be between 1 and 32768'
		);
	}

	public function testCreateRagModelInvalidKResults(): void {
		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'bad_model' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key',
				k_results = 100
			)",
			'k_results must be between 1 and 50'
		);
	}

	public function testShowRagModelsEmpty(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model1'");
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model2'");

		$result = static::runSqlQuery('SHOW RAG MODELS');
		$this->assertIsArray($result);
	}

	public function testShowRagModelsWithData(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model1'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'test_model1' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			llm_api_key = 'sk-test-key-123456789'
		)"
		);

		$this->assertQueryResult(
			'SHOW RAG MODELS',
			['test_model1', 'openai', 'gpt-4']
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model1'");
	}

	public function testDescribeRagModelSuccess(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			llm_base_url = 'https://api.openai.com/v1',
			style_prompt = 'You are a helpful assistant.',
			temperature = 0.7,
			max_tokens = 1000,
			k_results = 5
		)"
		);

		$this->assertQueryResult(
			"DESCRIBE RAG MODEL 'test_model'",
			['test_model', 'openai', 'gpt-4', 'https://api.openai.com/v1']
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model'");
	}

	public function testDescribeRagModelNotFound(): void {
		$this->assertQueryResultContainsError(
			"DESCRIBE RAG MODEL 'non_existent_model'",
			"RAG model 'non_existent_model' not found"
		);
	}

	public function testDropRagModelSuccess(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'test_model'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'test_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			llm_api_key = 'sk-test-key-123456789'
		)"
		);

		$result = static::runSqlQuery("DROP RAG MODEL 'test_model'");
		$this->assertIsArray($result);
	}

	public function testDropRagModelNotFound(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'non_existent_model'");

		$this->assertQueryResultContainsError(
			"DROP RAG MODEL 'non_existent_model'",
			"RAG model 'non_existent_model' not found"
		);
	}

	public function testCreateDuplicateRagModel(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'duplicate_model'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'duplicate_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			llm_api_key = 'sk-test-key-123456789'
		)"
		);

		$this->assertQueryResultContainsError(
			"CREATE RAG MODEL 'duplicate_model' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key-123456789'
			)",
			"RAG model 'duplicate_model' already exists"
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'duplicate_model'");
	}

	public function testFullModelLifecycle(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'lifecycle_model'");

		static::runSqlQuery(
			"CREATE RAG MODEL 'lifecycle_model' (
			llm_provider = 'openai',
			llm_model = 'gpt-4',
			llm_api_key = 'sk-test-key-123456789',
			llm_base_url = 'https://api.openai.com/v1',
			style_prompt = 'You are a helpful assistant.',
			temperature = 0.7,
			max_tokens = 1000,
			k_results = 5
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

	public function testCreateModelWithMinimalParameters(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'minimal_model'");

		$result = static::runSqlQuery(
			"CREATE RAG MODEL 'minimal_model' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key-123456789'
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

	public function testCreateModelWithAllParameters(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'full_model'");

		$result = static::runSqlQuery(
			"CREATE RAG MODEL 'full_model' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key-123456789',
				llm_base_url = 'https://api.openai.com/v1',
				style_prompt = 'You are a helpful assistant with extensive knowledge.',
				temperature = 1.5,
				max_tokens = 2000,
				k_results = 10
			)"
		);
		$this->assertIsArray($result);

		$this->assertQueryResult(
			"DESCRIBE RAG MODEL 'full_model'",
			['full_model', 'You are a helpful assistant with extensive knowledge.']
		);

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'full_model'");
	}

	public function testTemperatureBoundaryValues(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'temp_min'");

		$result1 = static::runSqlQuery(
			"CREATE RAG MODEL 'temp_min' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key',
				temperature = 0
			)"
		);
		$this->assertIsArray($result1);
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'temp_min'");

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'temp_max'");

		$result2 = static::runSqlQuery(
			"CREATE RAG MODEL 'temp_max' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key',
				temperature = 2
			)"
		);
		$this->assertIsArray($result2);
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'temp_max'");
	}

	public function testMaxTokensBoundaryValues(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'tokens_min'");

		$result1 = static::runSqlQuery(
			"CREATE RAG MODEL 'tokens_min' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key',
				max_tokens = 1
			)"
		);
		$this->assertIsArray($result1);
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'tokens_min'");

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'tokens_max'");

		$result2 = static::runSqlQuery(
			"CREATE RAG MODEL 'tokens_max' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key',
				max_tokens = 32768
			)"
		);
		$this->assertIsArray($result2);
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'tokens_max'");
	}

	public function testKResultsBoundaryValues(): void {
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'k_min'");

		$result1 = static::runSqlQuery(
			"CREATE RAG MODEL 'k_min' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key',
				k_results = 1
			)"
		);
		$this->assertIsArray($result1);
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'k_min'");

		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'k_max'");

		$result2 = static::runSqlQuery(
			"CREATE RAG MODEL 'k_max' (
				llm_provider = 'openai',
				llm_model = 'gpt-4',
				llm_api_key = 'sk-test-key',
				k_results = 50
			)"
		);
		$this->assertIsArray($result2);
		static::runSqlQuery("DROP RAG MODEL IF EXISTS 'k_max'");
	}
}
