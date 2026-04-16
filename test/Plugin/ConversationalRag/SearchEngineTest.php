<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\SearchEngine;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\TableSchema;
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

	public function testInspectTableSchemaDetectsFloatVector(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = new SearchEngine($mockClient);

		$mockResponse = $this->createSchemaResponse(
			"CREATE TABLE test_table (\nid bigint,\ncontent text,\n"
			. "embedding float_vector from='content'\n)"
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('SHOW CREATE TABLE test_table')
			->willReturn($mockResponse);

		$result = $searchEngine->inspectTableSchema('test_table');

		$this->assertEquals('embedding', $result->vectorField);
	}

	private function createSchemaResponse(string $createTable): Response {
		return $this->createDataResponse(
			[
				[
					'Table' => 'test_table',
					'Create Table' => $createTable,
				],
			]
		);
	}

	public function testInspectTableSchemaDetectsCommonVectorNames(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = new SearchEngine($mockClient);

		$mockResponse = $this->createSchemaResponse(
			"CREATE TABLE test_table (\nid bigint,\ncontent text,\n"
			. "content_embedding float_vector from='content'\n)"
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('SHOW CREATE TABLE test_table')
			->willReturn($mockResponse);

		$result = $searchEngine->inspectTableSchema('test_table');

		$this->assertEquals('content_embedding', $result->vectorField);
	}

	public function testInspectTableSchemaFailsWithoutVectorFields(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = new SearchEngine($mockClient);

		$mockResponse = $this->createSchemaResponse(
			"CREATE TABLE test_table (\nid bigint,\ncontent text,\ntitle string\n)"
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('SHOW CREATE TABLE test_table')
			->willReturn($mockResponse);

		$this->expectException(ManticoreSearchClientError::class);
		$searchEngine->inspectTableSchema('test_table');
	}

	public function testPerformVectorSearchSuccessful(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

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

	private function createSearchEngine(HTTPClient $client): SearchEngine {
		return new SearchEngine($client);
	}

	private function createDefaultVectorSchemaResponse(): Response {
		return $this->createSchemaResponse(
			"CREATE TABLE test_table (\nid bigint,\ncontent text,\n"
			. "embedding float_vector from='content'\n)"
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

	private function createErrorResponse(string $error): Response {
		$mockResponse = $this->createMock(Response::class);
		$mockResponse->method('hasError')->willReturn(true);
		$mockResponse->method('getError')->willReturn($error);

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
		$mockClient->expects($this->exactly(3))
			->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				$schemaResponse,
				$exclusionResponse,
				$searchResponse
			);

		$excludedIds = $searchEngine->getExcludedIds(
			'test_table',
			'exclude this'
		);

		return $searchEngine->search(
			'test_table',
			'test search query',
			$excludedIds,
			$this->createDefaultModelConfig(),
			0.8
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
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

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
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

		// Mock schema response without vector fields
		$schemaResponse = $this->createSchemaResponse(
			"CREATE TABLE test_table (\nid bigint,\ncontent text\n)"
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->willReturn($schemaResponse);

		$modelConfig = ['model' => 'openai:gpt-3.5-turbo', 'settings' => ['retrieval_limit' => 5]];
		$this->expectException(ManticoreSearchClientError::class);
		$searchEngine->search(
			'test_table',
			'test search query',
			[],
			$modelConfig,
			0.8
		);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testPerformVectorSearchWithKResultsOneAddsLimit(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

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
		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnCallback(
				function (string $sql) use ($schemaResponse, $searchResponse, &$sqlQueries): Response {
					$sqlQueries[] = $sql;

					return match (sizeof($sqlQueries)) {
						1 => $schemaResponse,
						2 => $searchResponse,
						default => throw new Exception('Unexpected sendRequest call count'),
					};
				}
			);

		$modelConfig = [
			'model' => 'openai:gpt-3.5-turbo',
			'settings' => ['retrieval_limit' => 1],
		];

		$result = $searchEngine->search(
			'test_table',
			'test search query',
			[],
			$modelConfig,
			0.8
		);

		$this->assertSame('SHOW CREATE TABLE test_table', $sqlQueries[0]);
		$this->assertStringContainsString('LIMIT 1', $sqlQueries[1]);
		$this->assertCount(2, $result);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testPerformSearchWithMultipleGeneratedTermsBuildsHybridKnnQuery(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);
		$schemaResponse = $this->createSchemaResponse(
			"CREATE TABLE docs (\nembedding_vector float_vector from='title'\n)"
		);
		$searchResponse = $this->createDataResponse(
			[
				['id' => 1, 'title' => 'Market cap article', 'knn_dist' => 0.1],
			]
		);

		$sqlQueries = [];
		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnCallback(
				function (string $sql) use ($schemaResponse, $searchResponse, &$sqlQueries): Response {
					$sqlQueries[] = $sql;

					return match (sizeof($sqlQueries)) {
						1 => $schemaResponse,
						2 => $searchResponse,
						default => throw new Exception('Unexpected sendRequest call count'),
					};
				}
			);

		$result = $searchEngine->search(
			'docs',
			'Market Capitalization vs Net Asset Value, difference between market cap and NAV, ' .
			'how to calculate Market Cap and NAV',
			[],
			$this->createDefaultModelConfig(),
			0.8
		);

		$searchSql = $sqlQueries[1];
		$this->assertStringContainsString(
			"WHERE knn(embedding_vector, 5, 'Market Capitalization vs Net Asset Value') "
			. "AND knn(embedding_vector, 5, 'difference between market cap and NAV') "
			. "AND knn(embedding_vector, 5, 'how to calculate Market Cap and NAV')",
			$searchSql
		);
		$this->assertStringContainsString("OPTION fusion_method='rrf'", $searchSql);
		$this->assertCount(1, $result);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testInspectTableSchemaFindsVectorFields(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

		// Mock response with multiple vector fields
		$mockResponse = $this->createSchemaResponse(
			"CREATE TABLE test_table (\nid bigint,\ncontent text,\n"
			. "embedding float_vector from='content',\n"
			. "title_embedding float_vector from='content'\n)"
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('SHOW CREATE TABLE test_table')
			->willReturn($mockResponse);

		$result = $searchEngine->inspectTableSchema('test_table');

		$this->assertSame('embedding', $result->vectorField);
		$this->assertCount(2, $result->vectorFields);
		$this->assertContains('embedding', $result->vectorFields);
		$this->assertContains('title_embedding', $result->vectorFields);
	}

	/**
	 * @throws ReflectionException
	 */
	public function testFilterVectorFieldsRemovesEmbeddings(): void {
		$searchEngine = $this->createSearchEngine($this->createMock(HTTPClient::class));

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

		$result = $method->invoke($searchEngine, $testResults, new TableSchema('embedding', ['embedding'], 'content'));

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
		$searchEngine = $this->createSearchEngine($this->createMock(HTTPClient::class));

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
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

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
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

		$result = $searchEngine->getExcludedIds(
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
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

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

		$mockClient->expects($this->exactly(2)) // schema, search
			->method('sendRequest')
			->willReturnOnConsecutiveCalls(
				$schemaResponse,
				$searchResponse
			);

		$modelConfig = $this->createDefaultModelConfig();
		$result = $searchEngine->search(
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
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);
		$schemaResponse = $this->createDefaultVectorSchemaResponse();
		$exclusionResponse = $this->createExclusionResponse([]);

		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnCallback(
				function ($sql) use ($schemaResponse, $exclusionResponse) {
					if (str_contains($sql, 'DESCRIBE')) {
						throw new Exception("Unexpected SQL: $sql");
					}
					if (str_contains($sql, 'SHOW CREATE TABLE')) {
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
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

		$schemaResponse = $this->createSchemaResponse(
			"CREATE TABLE docs (\nembedding_vector float_vector from='title'\n)"
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
					if (str_contains($sql, 'SHOW CREATE TABLE')) {
						return $schemaResponse;
					}
					$actualSql = $sql;
					return $exclusionResponse;
				}
			);

		$result = $searchEngine->getExcludedIds('docs', 'Stranger Things');

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
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);
		$model = [
			'model' => 'openai:gpt-4',
			'settings' => ['retrieval_limit' => 5],
		];

		$schemaResponse = $this->createSchemaResponse(
			"CREATE TABLE docs (\nembedding_vector float_vector from='title'\n)"
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
		$mockClient->expects($this->exactly(3)) // schema, exclusion, search
			->method('sendRequest')
			->willReturnCallback(
				function ($sql) use ($schemaResponse, $exclusionResponse, $searchResponse, &$sqlQueries) {
					$sqlQueries[] = $sql;
					if (str_contains($sql, 'SHOW CREATE TABLE')) {
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
		$excludedIds = $searchEngine->getExcludedIds('docs', 'Stranger Things');
		$this->assertEquals([1156395647918669832], $excludedIds);

		$result = $searchEngine->search(
			'docs', 'horror shows', $excludedIds, $model, 0.8
		);

		// Verify exclusion SQL was generated correctly
		$exclusionSql = $sqlQueries[1];
		$this->assertStringContainsString(
			/** @lang manticore */            'SELECT id, knn_dist() as knn_dist FROM docs',
			$exclusionSql
		);

		// Verify search SQL excludes the found ID
		$searchSql = $sqlQueries[2];
		$this->assertStringContainsString('id NOT IN (1156395647918669832)', $searchSql);

		// Verify results don't contain the excluded ID
		$this->assertCount(2, $result);
		$this->assertEquals(1001, $result[0]['id']);
		$this->assertEquals(1002, $result[1]['id']);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function testInspectTableSchemaFindsAutoEmbeddingSourceFields(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

		$schemaResponse = $this->createSchemaResponse(
			"CREATE TABLE docs (\ncontent text,\ntitle text,\n"
			. "embedding_vector FLOAT_VECTOR from='content,title'\n)"
		);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('SHOW CREATE TABLE docs')
			->willReturn($schemaResponse);

		$this->assertEquals('content,title', $searchEngine->inspectTableSchema('docs')->contentFields);
	}

	public function testPerformSearchPropagatesMissingTableSchemaError(): void {
		$mockClient = $this->createMock(HTTPClient::class);
		$searchEngine = $this->createSearchEngine($mockClient);

		$mockClient->expects($this->once())
			->method('sendRequest')
			->with('SHOW CREATE TABLE missing_table')
			->willReturn($this->createErrorResponse('table not found'));

		$this->expectException(ManticoreSearchResponseError::class);

		$searchEngine->search(
			'missing_table',
			'test search query',
			[],
			$this->createDefaultModelConfig(),
			0.8
		);
	}
}
