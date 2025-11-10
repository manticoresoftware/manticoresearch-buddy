<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\SearchEngine;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\TestCase;

class SearchEngineTest extends TestCase {
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

	public function testDetectVectorFieldWithFloatVector(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with FLOAT_VECTOR field
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
					],
				],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($mockResponse);

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('detectVectorField');
		$method->setAccessible(true);

		$result = $method->invoke($searchEngine, $mockClient, 'test_table');

		$this->assertEquals('embedding', $result);
	}

	public function testDetectVectorFieldWithCommonNames(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with common vector field name
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'content_embedding', 'Type' => 'float_vector(768)'],
					],
				],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($mockResponse);

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('detectVectorField');
		$method->setAccessible(true);

		$result = $method->invoke($searchEngine, $mockClient, 'test_table');

		$this->assertEquals('content_embedding', $result);
	}

	public function testDetectVectorFieldNoVectorFields(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response without vector fields
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'title', 'Type' => 'string'],
					],
				],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($mockResponse);

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('detectVectorField');
		$method->setAccessible(true);

		$result = $method->invoke($searchEngine, $mockClient, 'test_table');

		$this->assertNull($result);
	}

	public function testPerformVectorSearchSuccessful(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock schema response
		$schemaResponse = $this->createMock(Response::class);
		$schemaResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
					],
				],
				]
			)
		);

		// Mock exclusion response
		$exclusionResponse = $this->createMock(Response::class);
		$exclusionResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['id' => 1, 'knn_dist' => 0.1],
						['id' => 2, 'knn_dist' => 0.2],
					],
				],
				]
			)
		);

		// Mock search response
		$searchResponse = $this->createMock(Response::class);
		$searchResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						[
							'id' => 3,
							'content' => 'Test content',
							'embedding' => '[0.1, 0.2, 0.3]',
							'knn_dist' => 0.05,
						],
					],
				],
				]
			)
		);

		$mockClient->expects($this->exactly(5)) // schema, exclusion, schema, search, schema
			->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				$schemaResponse,
				$exclusionResponse,
				$schemaResponse,
				$searchResponse,
				$schemaResponse
			);

		$modelConfig = ['k_results' => 5, 'settings' => ['similarity_threshold' => 0.8]];
		$result = $searchEngine->performSearch(
			$mockClient,
			'test_table',
			'test search query',
			'exclude this',
			$modelConfig
		);

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertEquals(3, $result[0]['id']);
		$this->assertEquals('Test content', $result[0]['content']);
		$this->assertArrayNotHasKey('embedding', $result[0]); // Should be filtered out
	}

	public function testPerformVectorSearchWithExclusions(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock schema response
		$schemaResponse = $this->createMock(Response::class);
		$schemaResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
					],
				],
				]
			)
		);

		// Mock exclusion response with multiple exclusions
		$exclusionResponse = $this->createMock(Response::class);
		$exclusionResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['id' => 1, 'knn_dist' => 0.1],
						['id' => 2, 'knn_dist' => 0.2],
						['id' => 4, 'knn_dist' => 0.15],
					],
				],
				]
			)
		);

		// Mock search response
		$searchResponse = $this->createMock(Response::class);
		$searchResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						[
							'id' => 3,
							'content' => 'Test content',
							'embedding' => '[0.1, 0.2, 0.3]',
							'knn_dist' => 0.05,
						],
					],
				],
				]
			)
		);

		$mockClient->expects($this->exactly(5)) // schema, exclusion, schema, search, schema
			->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				$schemaResponse,
				$exclusionResponse,
				$schemaResponse,
				$searchResponse,
				$schemaResponse
			);

		$modelConfig = ['k_results' => 5, 'settings' => ['similarity_threshold' => 0.8]];
		$result = $searchEngine->performSearch(
			$mockClient,
			'test_table',
			'test search query',
			'exclude this',
			$modelConfig
		);

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
	}

	public function testPerformVectorSearchNoVectorFields(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock schema response without vector fields
		$schemaResponse = $this->createMock(Response::class);
		$schemaResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
					],
				],
				]
			)
		);

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($schemaResponse);

		$modelConfig = ['k_results' => 5];
		$result = $searchEngine->performSearch(
			$mockClient,
			'test_table',
			'test search query',
			'exclude this',
			$modelConfig
		);

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testGetVectorFieldsSuccessful(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with multiple vector fields
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
						['Field' => 'title_embedding', 'Type' => 'float_vector(768)'],
					],
				],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($mockResponse);

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('getVectorFields');
		$method->setAccessible(true);

		$result = $method->invoke($searchEngine, $mockClient, 'test_table');

		$this->assertIsArray($result);
		$this->assertCount(2, $result);
		$this->assertContains('embedding', $result);
		$this->assertContains('title_embedding', $result);
	}

	public function testFilterVectorFieldsRemovesEmbeddings(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with vector fields
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
					],
				],
				]
			)
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($mockResponse);

		$testResults = [
			[
				'id' => 1,
				'content' => 'Test content',
				'embedding' => '[0.1, 0.2, 0.3]',
				'title' => 'Test title',
			],
		];

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('filterVectorFields');
		$method->setAccessible(true);

		$result = $method->invoke($searchEngine, $testResults, 'test_table', $mockClient);

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertArrayHasKey('id', $result[0]);
		$this->assertArrayHasKey('content', $result[0]);
		$this->assertArrayHasKey('title', $result[0]);
		$this->assertArrayNotHasKey('embedding', $result[0]);
	}

	public function testEscapeStringHandlesSpecialChars(): void {
		$searchEngine = new SearchEngine();

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('escapeString');
		$method->setAccessible(true);

		$result = $method->invoke($searchEngine, "test's string");
		$this->assertEquals("test''s string", $result);

		$result = $method->invoke($searchEngine, 'normal string');
		$this->assertEquals('normal string', $result);
	}

	public function testGetExcludedIdsReturnsCorrectIds(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock schema response
		$schemaResponse = $this->createMock(Response::class);
		$schemaResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
					],
				],
				]
			)
		);

		// Mock exclusion response
		$exclusionResponse = $this->createMock(Response::class);
		$exclusionResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['id' => 1, 'knn_dist' => 0.1],
						['id' => 2, 'knn_dist' => 0.2],
						['id' => 5, 'knn_dist' => 0.15],
					],
				],
				]
			)
		);

		$mockClient->expects($this->exactly(2)) // schema, exclusion
			->method('sendRequest')
			->willReturnOnConsecutiveCalls($schemaResponse, $exclusionResponse);

		$modelConfig = ['k_results' => 5];
		$result = $searchEngine->getExcludedIds(
			$mockClient,
			'test_table',
			'exclude Star Wars',
			$modelConfig
		);

		$this->assertIsArray($result);
		$this->assertEquals([1, 2, 5], $result);
	}

	public function testGetExcludedIdsNoExclusions(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$modelConfig = ['k_results' => 5];
		$result = $searchEngine->getExcludedIds(
			$mockClient,
			'test_table',
			'none',
			$modelConfig
		);

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testPerformSearchWithExcludedIdsSkipsExclusionSearch(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock schema response
		$schemaResponse = $this->createMock(Response::class);
		$schemaResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
					],
				],
				]
			)
		);

		// Mock search response
		$searchResponse = $this->createMock(Response::class);
		$searchResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						[
							'id' => 3,
							'content' => 'Test content',
							'embedding' => '[0.1, 0.2, 0.3]',
							'knn_dist' => 0.05,
						],
					],
				],
				]
			)
		);

		$mockClient->expects($this->exactly(3)) // schema, search, schema
			->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				$schemaResponse,
				$searchResponse,
				$schemaResponse
			);

		$modelConfig = ['k_results' => 5, 'settings' => ['similarity_threshold' => 0.8]];
		$result = $searchEngine->performSearchWithExcludedIds(
			$mockClient,
			'test_table',
			'test search query',
			[1, 2, 5], // pre-computed excluded IDs
			$modelConfig,
			[],
			0.8
		);

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertEquals(3, $result[0]['id']);
		$this->assertArrayNotHasKey('embedding', $result[0]); // Should be filtered out
	}

	public function testGetExcludedIdsBuildsCorrectSQL(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock schema response
		$schemaResponse = $this->createMock(Response::class);
		$schemaResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
				[
					'data' => [
						['Field' => 'id', 'Type' => 'bigint'],
						['Field' => 'content', 'Type' => 'text'],
						['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
					],
				],
				]
			)
		);

		// Mock exclusion response
		$exclusionResponse = $this->createMock(Response::class);
		$exclusionResponse->method('getResult')->willReturn(
			Struct::fromData([['data' => []]])
		);

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnCallback(
				function ($sql) use ($schemaResponse, $exclusionResponse) {
					if (strpos($sql, 'DESCRIBE') !== false) {
						return $schemaResponse;
					}
				// Verify the exclusion SQL matches actual implementation
					if (str_contains($sql, 'SELECT id FROM test_table')
					&& str_contains($sql, "WHERE knn(embedding, 15, 'exclude query')")
					&& str_contains($sql, 'AND knn_dist < 0.75')
					) {
						return $exclusionResponse;
					}
					throw new \Exception("Unexpected SQL: $sql");
				}
			);

		$modelConfig = ['k_results' => 5];
		$result = $searchEngine->getExcludedIds(
			$mockClient,
			'test_table',
			'exclude query',
			$modelConfig
		);

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}
}
