<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\ModelManager;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\TestCase;

class ModelManagerTest extends TestCase {
	/**
	 * @return void
	 */
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

	public function testInitializeTablesCreatesModelsTable(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock successful response
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains('CREATE TABLE IF NOT EXISTS system.rag_models'))
			->willReturn($mockResponse);

		$modelManager->initializeTables($mockClient);
	}

	public function testCreateModelSuccessful(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock responses for modelExists check and insert
		$modelExistsResponse = $this->createMock(Response::class);
		$modelExistsResponse->method('hasError')->willReturn(false);
		$modelExistsResponse->method('getResult')->willReturn(
			Struct::fromData([['data' => [['count' => 0]]]])
		);

		$insertResponse = $this->createMock(Response::class);
		$insertResponse->method('hasError')->willReturn(false);

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($modelExistsResponse, $insertResponse);

		$config = [
			'name' => 'test_model',
			'llm_provider' => 'openai',
			'llm_model' => 'gpt-4',
			'style_prompt' => 'You are helpful',
			'temperature' => '0.7',
			'max_tokens' => '1000',
			'k_results' => '5',
		];

		$result = $modelManager->createModel($mockClient, $config);

		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	public function testCreateModelDuplicateName(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response showing model already exists
		$modelExistsResponse = $this->createMock(Response::class);
		$modelExistsResponse->method('hasError')->willReturn(false);
		$modelExistsResponse->method('getResult')->willReturn(
			Struct::fromData([['data' => [['count' => 1]]]])
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($modelExistsResponse);

		$config = [
			'name' => 'existing_model',
			'llm_provider' => 'openai',
			'llm_model' => 'gpt-4',
		];

		$this->expectException(ManticoreSearchClientError::class);

		$modelManager->createModel($mockClient, $config);
	}

	public function testGetModelByUuidOrNameFoundByName(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with model data
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						[
							'uuid' => 'test-uuid-123',
							'name' => 'test_model',
							'llm_provider' => 'openai',
							'llm_model' => 'gpt-4',
							'style_prompt' => 'You are helpful',
							'settings' => '{"temperature":0.7,"max_tokens":1000}',
							'created_at' => 1234567890,
						],
					],
				],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains('WHERE (name = \'test_model\' OR uuid = \'test_model\')'))
			->willReturn($mockResponse);

		$result = $modelManager->getModelByUuidOrName($mockClient, 'test_model');

		$this->assertIsArray($result);
		$this->assertEquals('test-uuid-123', $result['uuid']);
		$this->assertEquals('test_model', $result['name']);
		$this->assertEquals('openai', $result['llm_provider']);
	}

	public function testGetModelByUuidOrNameFoundByUuid(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with model data
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						[
							'uuid' => 'test-uuid-123',
							'name' => 'test_model',
							'llm_provider' => 'openai',
						],
					],
				],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains('WHERE (name = \'test-uuid-123\' OR uuid = \'test-uuid-123\')'))
			->willReturn($mockResponse);

		$result = $modelManager->getModelByUuidOrName($mockClient, 'test-uuid-123');

		$this->assertIsArray($result);
		$this->assertEquals('test-uuid-123', $result['uuid']);
	}

	public function testGetModelByUuidOrNameNotFound(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with no data
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData([['data' => []]])
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($mockResponse);

		$this->expectException(ManticoreSearchClientError::class);
		$modelManager->getModelByUuidOrName($mockClient, 'nonexistent');
	}

	public function testDeleteModelByUuidOrNameSuccessful(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock getModelByUuidOrName response
		$getModelResponse = $this->createMock(Response::class);
		$getModelResponse->method('hasError')->willReturn(false);
		$getModelResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						[
							'uuid' => 'test-uuid-123',
							'name' => 'test_model',
						],
					],
				],
				]
			)
		);

		// Mock delete response
		$deleteResponse = $this->createMock(Response::class);
		$deleteResponse->method('hasError')->willReturn(false);

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($getModelResponse, $deleteResponse);

		$modelManager->deleteModelByUuidOrName($mockClient, 'test_model');
	}

	public function testDeleteModelByUuidOrNameModelNotFound(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock getModelByUuidOrName response with no data
		$getModelResponse = $this->createMock(Response::class);
		$getModelResponse->method('hasError')->willReturn(false);
		$getModelResponse->method('getResult')->willReturn(
			Struct::fromData([['data' => []]])
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($getModelResponse);

		$this->expectException(ManticoreSearchClientError::class);

		$modelManager->deleteModelByUuidOrName($mockClient, 'nonexistent');
	}

	public function testGetAllModelsSuccessful(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with multiple models
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						[
							'id' => 1,
							'uuid' => 'uuid-1',
							'name' => 'model1',
							'llm_provider' => 'openai',
							'llm_model' => 'gpt-4',
							'created_at' => 1234567890,
						],
						[
							'id' => 2,
							'uuid' => 'uuid-2',
							'name' => 'model2',
							'llm_provider' => 'openai',
							'llm_model' => 'gpt-3.5-turbo',
							'created_at' => 1234567891,
						],
					],
				],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains('SELECT id, uuid, name, llm_provider, llm_model, created_at'))
			->willReturn($mockResponse);

		$result = $modelManager->getAllModels($mockClient);

		$this->assertIsArray($result);
		$this->assertCount(2, $result);
		$this->assertEquals('uuid-1', $result[0]['uuid']);
		$this->assertEquals('model1', $result[0]['name']);
		$this->assertEquals('uuid-2', $result[1]['uuid']);
		$this->assertEquals('model2', $result[1]['name']);
	}

	public function testExtractSettingsFromJsonString(): void {
		$modelManager = new ModelManager();

		$config = [
			'name' => 'test_model',
			'llm_provider' => 'openai',
			'llm_model' => 'gpt-4',
			'settings' => '{"temperature":0.8,"max_tokens":2000,"k_results":10}',
			'custom_field' => 'custom_value',
		];

		// Use reflection to access private method
		$reflection = new ReflectionClass($modelManager);
		$method = $reflection->getMethod('extractSettings');
		$method->setAccessible(true);

		$result = $method->invoke($modelManager, $config);

		$this->assertIsArray($result);
		$this->assertEquals(0.8, $result['temperature']);
		$this->assertEquals(2000, $result['max_tokens']);
		$this->assertEquals(10, $result['k_results']);
		$this->assertEquals('custom_value', $result['custom_field']);
	}

	public function testExtractSettingsFromArray(): void {
		$modelManager = new ModelManager();

		$config = [
			'name' => 'test_model',
			'llm_provider' => 'openai',
			'llm_model' => 'gpt-4',
			'settings' => ['temperature' => 0.9, 'max_tokens' => 1500],
			'k_results' => 8,
		];

		// Use reflection to access private method
		$reflection = new ReflectionClass($modelManager);
		$method = $reflection->getMethod('extractSettings');
		$method->setAccessible(true);

		$result = $method->invoke($modelManager, $config);

		$this->assertIsArray($result);
		$this->assertEquals(0.9, $result['temperature']);
		$this->assertEquals(1500, $result['max_tokens']);
		$this->assertEquals(8, $result['k_results']);
	}

	public function testModelExistsCaseSensitivity(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response showing model exists
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData([['data' => [['count' => 1]]]])
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains('WHERE name = \'TestModel\''))
			->willReturn($mockResponse);

		// Use reflection to access private method
		$reflection = new ReflectionClass($modelManager);
		$method = $reflection->getMethod('modelExists');
		$method->setAccessible(true);

		$result = $method->invoke($modelManager, $mockClient, 'TestModel');

		$this->assertTrue($result);
	}
}
