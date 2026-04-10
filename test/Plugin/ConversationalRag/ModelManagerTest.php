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
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\TestCase;
use Random\RandomException;

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

	/**
	 * @throws ManticoreSearchClientError
	 */
	public function testInitializeTablesCreatesModelsTable(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$createResponse = $this->createMock(Response::class);
		$createResponse->method('hasError')->willReturn(false);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($createResponse);

		$modelManager->initializeTables($mockClient);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 * @throws RandomException
	 */
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
			'model' => 'openai:gpt-4',
			'style_prompt' => 'You are helpful',
			'api_key' => 'sk-test',
			'base_url' => 'http://host.docker.internal:8787/v1',
			'retrieval_limit' => '5',
		];

		$result = $modelManager->createModel($mockClient, $config);

		$this->assertIsString($result);
		$this->assertNotEmpty($result);
	}

	/**
	 * @throws ManticoreSearchResponseError
	 * @throws RandomException
	 */
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
			'model' => 'openai:gpt-4',
		];

		$this->expectException(ManticoreSearchClientError::class);

		$modelManager->createModel($mockClient, $config);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
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
								'description' => 'Test RAG Model',
								'model' => 'openai:gpt-4',
								'style_prompt' => 'You are helpful',
								'settings' => json_encode(
									[
										'api_key' => 'sk-test',
										'base_url' => 'http://host.docker.internal:8787/v1',
										'retrieval_limit' => 5,
										'max_document_length' => 2000,
									]
								),
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

		/** @var array{id:string, uuid:string, name:string,model:string,
		 * style_prompt:string,
		 * description:string,
		 * settings:array{api_key:string, base_url:string, retrieval_limit:int, max_document_length:int},
		 * created_at:string,updated_at:string} $result
		 */
		$result = $modelManager->getModelByUuidOrName($mockClient, 'test_model');

		$this->assertIsArray($result);
		$this->assertEquals('test-uuid-123', $result['uuid']);
		$this->assertEquals('test_model', $result['name']);
		$this->assertEquals('Test RAG Model', $result['description']);
		$this->assertEquals('openai:gpt-4', $result['model']);
		// Verify settings were properly parsed from JSON
		$this->assertIsArray($result['settings']);
		$this->assertEquals('sk-test', $result['settings']['api_key']);
		$this->assertEquals('http://host.docker.internal:8787/v1', $result['settings']['base_url']);
		$this->assertEquals(5, $result['settings']['retrieval_limit']);
		$this->assertEquals(2000, $result['settings']['max_document_length']);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
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
								'settings' => '{}',
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

	/**
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetModelByUuidOrNameNotFound(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$mockResponse = $this->createEmptyModelLookupResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($mockResponse);

		$this->expectException(ManticoreSearchClientError::class);
		$modelManager->getModelByUuidOrName($mockClient, 'nonexistent');
	}

	private function createEmptyModelLookupResponse(): Response {
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData([['data' => []]])
		);

		return $mockResponse;
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
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
								'settings' => '{}',
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

	/**
	 * @throws ManticoreSearchResponseError
	 */
	public function testDeleteModelByUuidOrNameModelNotFound(): void {
		$modelManager = new ModelManager();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$getModelResponse = $this->createEmptyModelLookupResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($getModelResponse);

		$this->expectException(ManticoreSearchClientError::class);

		$modelManager->deleteModelByUuidOrName($mockClient, 'nonexistent');
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testDeleteModelByUuidOrNameIfExistsDoesNotThrowWhenModelMissing(): void {
		$modelManager = new ModelManager();

		$mockClient = $this->createMock(HTTPClient::class);

		$getModelResponse = $this->createEmptyModelLookupResponse();

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($getModelResponse);

		$modelManager->deleteModelByUuidOrName($mockClient, 'nonexistent', true);
		$this->addToAssertionCount(1);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
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
								'description' => 'Model One',
								'model' => 'openai:gpt-4',
								'created_at' => 1234567890,
							],
							[
								'id' => 2,
								'uuid' => 'uuid-2',
								'name' => 'model2',
								'description' => 'Model Two',
								'model' => 'openai:gpt-3.5-turbo',
								'created_at' => 1234567891,
							],
						],
					],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with($this->stringContains('SELECT id, uuid, name, description, model, created_at'))
			->willReturn($mockResponse);

		$result = $modelManager->getAllModels($mockClient);

		$this->assertIsArray($result);
		$this->assertCount(2, $result);
		$this->assertEquals('uuid-1', $result[0]['uuid']);
		$this->assertEquals('model1', $result[0]['name']);
		$this->assertEquals('uuid-2', $result[1]['uuid']);
		$this->assertEquals('model2', $result[1]['name']);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testExtractSettingsFromFlatFields(): void {
		$modelManager = new ModelManager();

		$config = [
			'name' => 'test_model',
			'model' => 'openai:gpt-4',
			'description' => 'Test RAG Model',
			'api_key' => 'sk-test',
			'base_url' => 'http://host.docker.internal:8787/v1',
			'timeout' => 60,
			'retrieval_limit' => 10,
		];

		// Use reflection to access private method
		$reflection = new ReflectionClass($modelManager);
		$method = $reflection->getMethod('extractSettings');

		$result = $method->invoke($modelManager, $config);

		$this->assertIsArray($result);
		$this->assertEquals('sk-test', $result['api_key']);
		$this->assertEquals('http://host.docker.internal:8787/v1', $result['base_url']);
		$this->assertEquals(60, $result['timeout']);
		$this->assertEquals(10, $result['retrieval_limit']);
		$this->assertEquals(2000, $result['max_document_length']);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testExtractSettingsKeepsFlatFieldsOnly(): void {
		$modelManager = new ModelManager();

		$config = [
			'name' => 'test_model',
			'model' => 'openai:gpt-4',
			'api_key' => 'sk-test',
			'base_url' => 'http://host.docker.internal:8787/v1',
			'retrieval_limit' => 8,
		];

		// Use reflection to access private method
		$reflection = new ReflectionClass($modelManager);
		$method = $reflection->getMethod('extractSettings');

		$result = $method->invoke($modelManager, $config);

		$this->assertIsArray($result);
		$this->assertEquals('sk-test', $result['api_key']);
		$this->assertEquals('http://host.docker.internal:8787/v1', $result['base_url']);
		$this->assertEquals(8, $result['retrieval_limit']);
		$this->assertEquals(2000, $result['max_document_length']);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testExtractSettingsExcludesCoreFields(): void {
		$modelManager = new ModelManager();

		$config = [
			'name' => 'test_model',
			'model' => 'openai:gpt-4',
			'style_prompt' => 'You are helpful',
			'api_key' => 'sk-test',
			'base_url' => 'http://host.docker.internal:8787/v1',
			'retrieval_limit' => 8,
		];

		$reflection = new ReflectionClass($modelManager);
		$method = $reflection->getMethod('extractSettings');

		$result = $method->invoke($modelManager, $config);

		$this->assertIsArray($result);
		$this->assertEquals('sk-test', $result['api_key']);
		$this->assertEquals('http://host.docker.internal:8787/v1', $result['base_url']);
		$this->assertEquals(8, $result['retrieval_limit']);
		$this->assertEquals(2000, $result['max_document_length']);
		$this->assertArrayNotHasKey('name', $result);
		$this->assertArrayNotHasKey('model', $result);
		$this->assertArrayNotHasKey('style_prompt', $result);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testExtractSettingsNormalizesMaxDocumentLength(): void {
		$modelManager = new ModelManager();

		$reflection = new ReflectionClass($modelManager);
		$method = $reflection->getMethod('extractSettings');

		/** @var array{max_document_length:int} $defaulted */
		$defaulted = $method->invoke(
			$modelManager,
			[
				'name' => 'test_model',
				'model' => 'openai:gpt-4',
			]
		);
		$this->assertEquals(2000, $defaulted['max_document_length']);

		/** @var array{max_document_length:int} $disabled */
		$disabled = $method->invoke(
			$modelManager,
			[
				'name' => 'test_model',
				'model' => 'openai:gpt-4',
				'max_document_length' => 0,
			]
		);
		$this->assertEquals(0, $disabled['max_document_length']);

		/** @var array{max_document_length:int} $invalid */
		$invalid = $method->invoke(
			$modelManager,
			[
				'name' => 'test_model',
				'model' => 'openai:gpt-4',
				'max_document_length' => 99,
			]
		);
		$this->assertEquals(2000, $invalid['max_document_length']);

		/** @var array{max_document_length:int} $tooLarge */
		$tooLarge = $method->invoke(
			$modelManager,
			[
				'name' => 'test_model',
				'model' => 'openai:gpt-4',
				'max_document_length' => 65537,
			]
		);
		$this->assertEquals(2000, $tooLarge['max_document_length']);
	}

	/**
	 * @throws ReflectionException
	 */
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

		$result = $method->invoke($modelManager, $mockClient, 'TestModel');

		$this->assertTrue($result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetModelByUuidOrNameWithNullSettingsFailsFast(): void {
		$modelManager = new ModelManager();
		$this->expectException(ManticoreSearchClientError::class);

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with null settings
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
								'model' => 'openai:gpt-4',
								'style_prompt' => 'You are helpful',
								'settings' => null,
								'created_at' => 1234567890,
							],
						],
					],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($mockResponse);

		$modelManager->getModelByUuidOrName($mockClient, 'test_model');
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetModelByUuidOrNameWithEmptyStringSettingsFailsFast(): void {
		$modelManager = new ModelManager();
		$this->expectException(ManticoreSearchClientError::class);

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with empty string settings
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
								'model' => 'openai:gpt-4',
								'settings' => '',
								'created_at' => 1234567890,
							],
						],
					],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($mockResponse);

		$modelManager->getModelByUuidOrName($mockClient, 'test_model');
	}

	/**
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetModelByUuidOrNameWithInvalidJsonSettings(): void {
		$modelManager = new ModelManager();

		$this->expectException(ManticoreSearchClientError::class);

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with invalid JSON settings
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
								'model' => 'openai:gpt-4',
								'settings' => '{invalid json',
								'created_at' => 1234567890,
							],
						],
					],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($mockResponse);

		$modelManager->getModelByUuidOrName($mockClient, 'test_model');
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetModelByUuidOrNameWithStringNullSettingsFailsFast(): void {
		$modelManager = new ModelManager();
		$this->expectException(ManticoreSearchClientError::class);

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with string 'NULL' settings
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
								'model' => 'openai:gpt-4',
								'settings' => 'NULL',
								'created_at' => 1234567890,
							],
						],
					],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($mockResponse);

		$modelManager->getModelByUuidOrName($mockClient, 'test_model');
	}
}
