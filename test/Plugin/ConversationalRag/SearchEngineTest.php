<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\SearchEngine;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\MockObject\MockObject;
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

	/**
	 * @throws ReflectionException
	 */
	public function testDetectVectorFieldWithFloatVector(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$mockResponse = $this->createSchemaResponse(
			[
				['Field' => 'id', 'Type' => 'bigint'],
				['Field' => 'content', 'Type' => 'text'],
				['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
			]
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($mockResponse);

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('detectVectorField');

		$result = $method->invoke($searchEngine, $mockClient, 'test_table');

		$this->assertEquals('embedding', $result);
	}

	/**
	 * @param array<int, array{Field:string, Type:string}> $rows
	 */
	private function createSchemaResponse(array $rows): Response {
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
					[
						'data' => $rows,
					],
				]
			)
		);

		return $mockResponse;
	}

	/**
	 * @throws ReflectionException
	 */
	public function testDetectVectorFieldWithCommonNames(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$mockResponse = $this->createSchemaResponse(
			[
				['Field' => 'id', 'Type' => 'bigint'],
				['Field' => 'content', 'Type' => 'text'],
				['Field' => 'content_embedding', 'Type' => 'float_vector(768)'],
			]
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($mockResponse);

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('detectVectorField');

		$result = $method->invoke($searchEngine, $mockClient, 'test_table');

		$this->assertEquals('content_embedding', $result);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testDetectVectorFieldNoVectorFields(): void {
		$searchEngine = new SearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$mockResponse = $this->createSchemaResponse(
			[
				['Field' => 'id', 'Type' => 'bigint'],
				['Field' => 'content', 'Type' => 'text'],
				['Field' => 'title', 'Type' => 'string'],
			]
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($mockResponse);

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('detectVectorField');

		$result = $method->invoke($searchEngine, $mockClient, 'test_table');

		$this->assertNull($result);
	}

	public function testPerformVectorSearchSuccessful(): void {
		$searchEngine = $this->createSearchEngine();

		$mockClient = $this->createMock(HTTPClient::class);

		$schemaResponse = $this->createDefaultVectorSchemaResponse();

		$exclusionResponse = $this->createExclusionResponse(
			[
				['id' => 1, 'knn_dist' => 0.1],
				['id' => 2, 'knn_dist' => 0.2],
			]
		);

		$searchResponse = $this->createDataResponse(
			[
				[
					'id' => 3,
					'content' => 'Test content',
					'embedding' => '[0.1, 0.2, 0.3]',
					'knn_dist' => 0.05,
				],
			]
		);

		$result = $this->performDefaultSearch(
			$searchEngine,
			$mockClient,
			$schemaResponse,
			$exclusionResponse,
			$searchResponse
		);

		$this->assertSingleFilteredSearchResult($result);
	}

	private function createSearchEngine(): SearchEngine {
		return new SearchEngine();
	}

	private function createDefaultVectorSchemaResponse(): Response {
		return $this->createSchemaResponse(
			[
				['Field' => 'id', 'Type' => 'bigint'],
				['Field' => 'content', 'Type' => 'text'],
				['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
			]
		);
	}

	/**
	 * @param array<int, array{id:int, knn_dist:float}> $rows
	 */
	private function createExclusionResponse(array $rows): Response {
		return $this->createDataResponse($rows);
	}

	/**
	 * @param array<int, array<string, int|float|string>> $rows
	 */
	private function createDataResponse(array $rows): Response {
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(false);
		$mockResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
					[
						'data' => $rows,
					],
				]
			)
		);

		return $mockResponse;
	}

	/**
	 * @param HTTPClient&MockObject $mockClient
	 * @return array<int, array<string, mixed>>
	 */
	private function performDefaultSearch(
		SearchEngine $searchEngine,
		HTTPClient $mockClient,
		Response $schemaResponse,
		Response $exclusionResponse,
		Response $searchResponse
	): array {
		$mockClient->expects($this->exactly(5))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				$schemaResponse,
				$exclusionResponse,
				$schemaResponse,
				$searchResponse,
				$schemaResponse
			);

		return $searchEngine->performSearch(
			$mockClient,
			'test_table',
			'test search query',
			'exclude this',
			$this->createDefaultModelConfig()
		);
	}

	/**
	 * @return array{model:string, settings:array{retrieval_limit:int}}
	 */
	private function createDefaultModelConfig(): array {
		return [
			'model' => 'openai:gpt-3.5-turbo',
			'settings' => ['retrieval_limit' => 5],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $result
	 */
	private function assertSingleFilteredSearchResult(array $result): void {
		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertEquals(3, $result[0]['id']);
		$this->assertEquals('Test content', $result[0]['content']);
		$this->assertArrayNotHasKey('embedding', $result[0]);
	}

	public function testPerformVectorSearchWithExclusions(): void {
		$searchEngine = $this->createSearchEngine();

		$mockClient = $this->createMock(HTTPClient::class);

		$schemaResponse = $this->createDefaultVectorSchemaResponse();

		$exclusionResponse = $this->createExclusionResponse(
			[
				['id' => 1, 'knn_dist' => 0.1],
				['id' => 2, 'knn_dist' => 0.2],
				['id' => 4, 'knn_dist' => 0.15],
			]
		);

		$searchResponse = $this->createDataResponse(
			[
				[
					'id' => 3,
					'content' => 'Test content',
					'embedding' => '[0.1, 0.2, 0.3]',
					'knn_dist' => 0.05,
				],
			]
		);

		$result = $this->performDefaultSearch(
			$searchEngine,
			$mockClient,
			$schemaResponse,
			$exclusionResponse,
			$searchResponse
		);

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
	}

	public function testPerformVectorSearchNoVectorFields(): void {
		$searchEngine = $this->createSearchEngine();

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

		$modelConfig = ['model' => 'openai:gpt-3.5-turbo', 'settings' => ['retrieval_limit' => 5]];
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

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testPerformVectorSearchWithKResultsOneAddsLimit(): void {
		$searchEngine = $this->createSearchEngine();

		$mockClient = $this->createMock(HTTPClient::class);

		$schemaResponse = $this->createDefaultVectorSchemaResponse();

		$searchResponse = $this->createMock(Response::class);
		$searchResponse->method('getResult')->willReturn(
			Struct::fromData(
				[
					[
						'data' => [
							[
								'id' => 1,
								'content' => 'Test content',
								'embedding' => '[0.1, 0.2, 0.3]',
								'knn_dist' => 0.05,
							],
							[
								'id' => 2,
								'content' => 'Another content',
								'embedding' => '[0.4, 0.5, 0.6]',
								'knn_dist' => 0.06,
							],
						],
					],
				]
			)
		);

		$sqlQueries = [];
		$mockClient->expects($this->exactly(3))
			->method('sendRequest')
			->willReturnCallback(
				function (string $sql) use ($schemaResponse, $searchResponse, &$sqlQueries): Response {
					$sqlQueries[] = $sql;

					return match (sizeof($sqlQueries)) {
						1, 3 => $schemaResponse,
						2 => $searchResponse,
						default => throw new Exception('Unexpected sendRequest call count'),
					};
				}
			);

		$modelConfig = [
			'model' => 'openai:gpt-3.5-turbo',
			'settings' => ['retrieval_limit' => 1],
		];

		$result = $searchEngine->performSearchWithExcludedIds(
			$mockClient,
			'test_table',
			'test search query',
			[],
			$modelConfig,
			0.8
		);

		$this->assertSame('DESCRIBE test_table', $sqlQueries[0]);
		$this->assertStringContainsString('LIMIT 1', $sqlQueries[1]);
		$this->assertSame('DESCRIBE test_table', $sqlQueries[2]);
		$this->assertCount(2, $result);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testGetVectorFieldsSuccessful(): void {
		$searchEngine = $this->createSearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with multiple vector fields
		$mockResponse = $this->createSchemaResponse(
			[
				['Field' => 'id', 'Type' => 'bigint'],
				['Field' => 'content', 'Type' => 'text'],
				['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
				['Field' => 'title_embedding', 'Type' => 'float_vector(768)'],
			]
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('DESCRIBE test_table')
			->willReturn($mockResponse);

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('getVectorFields');

		$result = $method->invoke($searchEngine, $mockClient, 'test_table');

		$this->assertIsArray($result);
		$this->assertCount(2, $result);
		$this->assertContains('embedding', $result);
		$this->assertContains('title_embedding', $result);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testFilterVectorFieldsRemovesEmbeddings(): void {
		$searchEngine = $this->createSearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		// Mock response with vector fields
		$mockResponse = $this->createSchemaResponse(
			[
				['Field' => 'embedding', 'Type' => 'float_vector(1536)'],
			]
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

		$result = $method->invoke($searchEngine, $testResults, 'test_table', $mockClient);

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertArrayHasKey('id', $result[0]);
		$this->assertArrayHasKey('content', $result[0]);
		$this->assertArrayHasKey('title', $result[0]);
		$this->assertArrayNotHasKey('embedding', $result[0]);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testEscapeStringHandlesSpecialChars(): void {
		$searchEngine = $this->createSearchEngine();

		// Use reflection to access private method
		$reflection = new ReflectionClass($searchEngine);
		$method = $reflection->getMethod('escapeString');

		$result = $method->invoke($searchEngine, "test's string");
		$this->assertEquals("test\'s string", $result);

		$result = $method->invoke($searchEngine, 'normal string');
		$this->assertEquals('normal string', $result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetExcludedIdsReturnsCorrectIds(): void {
		$searchEngine = $this->createSearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$schemaResponse = $this->createDefaultVectorSchemaResponse();

		$exclusionResponse = $this->createExclusionResponse(
			[
				['id' => 1, 'knn_dist' => 0.1],
				['id' => 2, 'knn_dist' => 0.2],
				['id' => 5, 'knn_dist' => 0.15],
			]
		);

		$mockClient->expects($this->exactly(2)) // schema, exclusion
		->method('sendRequest')
			->willReturnOnConsecutiveCalls($schemaResponse, $exclusionResponse);

		$result = $searchEngine->getExcludedIds(
			$mockClient,
			'test_table',
			'exclude Star Wars'
		);

		$this->assertIsArray($result);
		$this->assertEquals([1, 2, 5], $result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetExcludedIdsNoExclusions(): void {
		$searchEngine = $this->createSearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$result = $searchEngine->getExcludedIds(
			$mockClient,
			'test_table',
			'none'
		);

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testPerformSearchWithExcludedIdsSkipsExclusionSearch(): void {
		$searchEngine = $this->createSearchEngine();

		// Mock HTTP client
		$mockClient = $this->createMock(HTTPClient::class);

		$schemaResponse = $this->createDefaultVectorSchemaResponse();

		$searchResponse = $this->createDataResponse(
			[
				[
					'id' => 3,
					'content' => 'Test content',
					'embedding' => '[0.1, 0.2, 0.3]',
					'knn_dist' => 0.05,
				],
			]
		);

		$mockClient->expects($this->exactly(3)) // schema, search, schema
		->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				$schemaResponse,
				$searchResponse,
				$schemaResponse
			);

		$modelConfig = $this->createDefaultModelConfig();
		$result = $searchEngine->performSearchWithExcludedIds(
			$mockClient,
			'test_table',
			'test search query',
			[1, 2, 5], // pre-computed excluded IDs
			$modelConfig,
			0.8
		);

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertEquals(3, $result[0]['id']);
		$this->assertArrayNotHasKey('embedding', $result[0]); // Should be filtered out
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetExcludedIdsBuildsCorrectSQL(): void {
		$searchEngine = $this->createSearchEngine();
		$mockClient = $this->createMock(HTTPClient::class);
		$schemaResponse = $this->createDefaultVectorSchemaResponse();
		$exclusionResponse = $this->createExclusionResponse([]);

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnCallback(
				function ($sql) use ($schemaResponse, $exclusionResponse) {
					if (str_contains($sql, 'DESCRIBE')) {
						return $schemaResponse;
					}
					// Verify the exclusion SQL matches actual implementation
					if (str_contains($sql, /** @lang manticore */ 'SELECT id, knn_dist() as knn_dist FROM test_table')
						&& str_contains($sql, "WHERE knn(embedding, 15, 'exclude query')")
						&& str_contains($sql, 'AND knn_dist < 0.75')
					) {
						return $exclusionResponse;
					}
					throw new Exception("Unexpected SQL: $sql");
				}
			);

		$result = $searchEngine->getExcludedIds(
			$mockClient,
			'test_table',
			'exclude query'
		);

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testGetExcludedIdsGeneratesCorrectSqlWithKnnDist(): void {
		$searchEngine = $this->createSearchEngine();
		$mockClient = $this->createMock(HTTPClient::class);

		$schemaResponse = $this->createSchemaResponse(
			[
				['Field' => 'embedding_vector', 'Type' => 'float_vector'],
			]
		);

		$exclusionResponse = $this->createDataResponse(
			[
				['id' => 1156395647918669832],
			]
		);

		$actualSql = '';
		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnCallback(
				function ($sql) use ($schemaResponse, $exclusionResponse, &$actualSql) {
					if (str_contains($sql, 'DESCRIBE')) {
						return $schemaResponse;
					}
					$actualSql = $sql;
					return $exclusionResponse;
				}
			);

		$result = $searchEngine->getExcludedIds($mockClient, 'docs', 'Stranger Things');

		// Verify the SQL contains knn_dist() in SELECT clause
		$this->assertStringContainsString(
			/** @lang manticore */            'SELECT id, knn_dist() as knn_dist FROM docs',
			$actualSql
		);
		$this->assertStringContainsString("WHERE knn(embedding_vector, 15, 'Stranger Things')", $actualSql);
		$this->assertStringContainsString('AND knn_dist < 0.75', $actualSql);

		// Verify results
		$this->assertEquals([1156395647918669832], $result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testPerformSearchWithExcludedIdsActuallyExcludes(): void {
		$searchEngine = $this->createSearchEngine();
		$mockClient = $this->createMock(HTTPClient::class);
		$model = [
			'model' => 'openai:gpt-4',
			'settings' => ['retrieval_limit' => 5],
		];

		$schemaResponse = $this->createSchemaResponse(
			[
				['Field' => 'embedding_vector', 'Type' => 'float_vector'],
			]
		);

		$exclusionResponse = $this->createDataResponse(
			[
				['id' => 1156395647918669832],
			]
		);

		$searchResponse = $this->createDataResponse(
			[
				['id' => 1001, 'title' => 'Other Show', 'knn_dist' => 0.5],
				['id' => 1002, 'title' => 'Another Show', 'knn_dist' => 0.6],
			]
		);

		$sqlQueries = [];
		$mockClient->expects($this->exactly(5)) // schema, exclusion, schema, search, schema
		->method('sendRequest')
			->willReturnCallback(
				function ($sql) use ($schemaResponse, $exclusionResponse, $searchResponse, &$sqlQueries) {
					$sqlQueries[] = $sql;
					if (str_contains($sql, 'DESCRIBE')) {
						return $schemaResponse;
					}
					if (str_contains($sql, 'SELECT id, knn_dist() as knn_dist')) {
						return $exclusionResponse;
					}
					if (str_contains($sql, 'id NOT IN (1156395647918669832)')) {
						return $searchResponse;
					}
					throw new Exception("Unexpected SQL: $sql");
				}
			);

		// First get excluded IDs
		$excludedIds = $searchEngine->getExcludedIds($mockClient, 'docs', 'Stranger Things');
		$this->assertEquals([1156395647918669832], $excludedIds);

		$result = $searchEngine->performSearchWithExcludedIds(
			$mockClient, 'docs', 'horror shows', $excludedIds, $model, 0.8
		);

		// Verify exclusion SQL was generated correctly
		$exclusionSql = $sqlQueries[1]; // Second query should be exclusion
		$this->assertStringContainsString(
			/** @lang manticore */            'SELECT id, knn_dist() as knn_dist FROM docs',
			$exclusionSql
		);

		// Verify search SQL excludes the found ID
		$searchSql = $sqlQueries[3]; // Fourth query should be search
		$this->assertStringContainsString('id NOT IN (1156395647918669832)', $searchSql);

		// Verify results don't contain the excluded ID
		$this->assertCount(2, $result);
		$this->assertEquals(1001, $result[0]['id']);
		$this->assertEquals(1002, $result[1]['id']);
	}
}
