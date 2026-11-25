<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRequest;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRoute;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Handler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Payload;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\SearchContext;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\SearchEngine;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\VectorFieldInfo;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use PHPUnit\Framework\TestCase;

final class HybridSearchTest extends TestCase {
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

	public function testSqlSixthArgumentEnablesHybridSearch(): void {
		$payload = Payload::fromRequest(
			$this->sqlRequest("CALL CHAT('16x48 frame', 'docs', 'assistant', '', '', 'hybrid')")
		);
		$this->assertSame('hybrid', $payload->params['search_strategy']);
		$this->assertSame('', $payload->params['fields']);
	}

	public function testSqlSixthArgumentPreservesSelectedVectorField(): void {
		$payload = Payload::fromRequest(
			$this->sqlRequest("CALL CHAT('frame', 'docs', 'assistant', 'conv', 'title_embedding', 'hybrid')")
		);
		$this->assertSame('title_embedding', $payload->params['fields']);
		$this->assertSame('hybrid', $payload->params['search_strategy']);
	}

	public function testHttpJsonEnablesHybridSearch(): void {
		$payload = Payload::fromRequest(
			$this->jsonRequest(
				'{"chat":{"query":"16x48 frame","table":"docs","model_name":"assistant",'
					. '"search_strategy":"hybrid"}}'
			)
		);
		$this->assertSame('hybrid', $payload->params['search_strategy']);
	}

	public function testExistingCallKeepsDefaultStrategy(): void {
		$payload = Payload::fromRequest($this->sqlRequest("CALL CHAT('frame', 'docs', 'assistant')"));
		$this->assertArrayNotHasKey('search_strategy', $payload->params);
	}

	public function testUnsupportedSqlStrategyIsRejected(): void {
		$this->expectException(QueryParseError::class);
		Payload::fromRequest($this->sqlRequest("CALL CHAT('frame', 'docs', 'assistant', '', '', 'research')"));
	}

	public function testUnsupportedJsonStrategyIsRejected(): void {
		$this->expectException(QueryParseError::class);
		Payload::fromRequest(
			$this->jsonRequest(
				'{"chat":{"query":"frame","table":"docs","model_name":"assistant",'
					. '"search_strategy":"research"}}'
			)
		);
	}

	public function testHybridSearchFusesLexicalAndVectorCandidates(): void {
		$client = $this->createMock(Client::class);
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(
			Struct::fromData(
				[[
					'data' => [
						['id' => 1, 'title' => '16x48 frame', 'embedding' => '[0.1]', 'hybrid_score' => 0.03],
						['id' => 2, 'title' => 'Panoramic frame', 'embedding' => '[0.2]', 'hybrid_score' => 0.02],
					],
				]]
			)
		);
		$client->expects($this->once())->method('sendRequest')
			->with(
				$this->callback(
					static function (string $sql): bool {
						return str_contains($sql, 'SELECT *, hybrid_score() AS hybrid_score FROM docs')
							&& str_contains($sql, 'MAYBE')
							&& str_contains($sql, 'cropped length')
							&& str_contains($sql, 'high\\\\-waisted')
							&& !str_contains($sql, 'Flex Pants\\\" |')
							&& str_contains($sql, "knn(embedding, 9, '16x48 frame')")
							&& str_contains($sql, 'id NOT IN (7)')
							&& str_contains($sql, "LIMIT 3 OPTION fusion_method='rrf'")
							&& !str_contains($sql, 'knn_dist <');
					}
				)
			)->willReturn($response);

		$results = (new SearchEngine($client))->search(
			'docs', '16x48 frame', [7],
			['model' => 'openai:test', 'settings' => ['retrieval_limit' => 3]],
			0.8, new VectorFieldInfo('embedding', 'title', ['embedding']), 'hybrid',
			['Flex Pants', 'high-waisted', 'cropped length']
		);
		$this->assertCount(2, $results);
		$this->assertSame('Panoramic frame', $results[1]['title']);
		$this->assertArrayNotHasKey('embedding', $results[0]);
	}

	public function testHybridSearchKeepsBrandAndProductTypeAsSeparateTerms(): void {
		$client = $this->createMock(Client::class);
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(Struct::fromData([['data' => []]]));
		$client->expects($this->once())->method('sendRequest')->with(
			$this->callback(
				static fn (string $sql): bool => preg_match(
					'/MATCH\\(.*Under Armour.*MAYBE.*Flex Pants.*black/', $sql
				) === 1
					&& preg_match('/MATCH\\([^)]*Under Armour Flex Pants/', $sql) === 0
			)
		)->willReturn($response);
		(new SearchEngine($client))->search(
			'docs', 'Find black Under Armour Flex Pants', [],
			['model' => 'openai:test', 'settings' => ['retrieval_limit' => 3]],
			0.8, new VectorFieldInfo('embedding', 'title', ['embedding']), 'hybrid',
			['Under Armour', 'Flex Pants', 'black']
		);
	}

	public function testHybridSearchEscapesSearchText(): void {
		$client = $this->createMock(Client::class);
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(Struct::fromData([['data' => []]]));
		$client->expects($this->once())->method('sendRequest')
			->with(
				$this->callback(
					static function (string $sql): bool {
						return str_contains($sql, "MATCH('frame')")
							&& str_contains($sql, "knn(embedding, 3, 'men\\'s frame')");
					}
				)
			)->willReturn($response);

		(new SearchEngine($client))->search(
			'docs', "men's frame", [],
			['model' => 'openai:test', 'settings' => ['retrieval_limit' => 3]],
			0.8, new VectorFieldInfo('embedding', 'title', ['embedding']), 'hybrid', ['frame']
		);
	}

	public function testHybridSearchEscapesFullTextOperatorsWithoutChangingVectorQuery(): void {
		$client = $this->createMock(Client::class);
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(Struct::fromData([['data' => []]]));
		$client->expects($this->once())->method('sendRequest')
			->with(
				$this->callback(
					static function (string $sql): bool {
						return str_contains($sql, "MATCH('formal\\\\/dress MAYBE \\\\@pants')")
							&& str_contains($sql, "knn(embedding, 3, 'formal/dress @pants')");
					}
				)
			)->willReturn($response);
		(new SearchEngine($client))->search(
			'docs', 'formal/dress @pants', [],
			['model' => 'openai:test', 'settings' => ['retrieval_limit' => 3]],
			0.8, new VectorFieldInfo('embedding', 'title', ['embedding']), 'hybrid',
			['formal/dress', '@pants']
		);
	}

	public function testHybridSearchRemovesWildcardOperatorsFromLexicalTerms(): void {
		$client = $this->createMock(Client::class);
		$response = $this->createMock(Response::class);
		$response->method('hasError')->willReturn(false);
		$response->method('getResult')->willReturn(Struct::fromData([['data' => []]]));
		$client->expects($this->once())->method('sendRequest')->with(
			$this->callback(
				static fn(string $sql): bool => str_contains($sql, "MATCH('frame MAYBE (frme | 50)')")
					&& str_contains($sql, "knn(embedding, 3, 'frame* fr?me 50%')")
			)
		)->willReturn($response);
		(new SearchEngine($client))->search(
			'docs', 'frame* fr?me 50%', [],
			['model' => 'openai:test', 'settings' => ['retrieval_limit' => 3]],
			0.8, new VectorFieldInfo('embedding', 'title', ['embedding']), 'hybrid',
			['frame*', 'fr?me', '50%']
		);
	}

	public function testHybridSearchRejectsLexicalTermContainingOnlyWildcards(): void {
		$client = $this->createMock(Client::class);
		$client->expects($this->never())->method('sendRequest');
		$this->expectException(ManticoreSearchClientError::class);
		(new SearchEngine($client))->search(
			'docs', 'wildcards', [],
			['model' => 'openai:test', 'settings' => ['retrieval_limit' => 3]],
			0.8, new VectorFieldInfo('embedding', 'title', ['embedding']), 'hybrid', ['*?%']
		);
	}

	public function testHybridSearchRejectsMissingLexicalTerms(): void {
		$client = $this->createMock(Client::class);
		$client->expects($this->never())->method('sendRequest');
		$this->expectException(ManticoreSearchClientError::class);
		(new SearchEngine($client))->search(
			'docs', 'What are Flex Pants?', [],
			['model' => 'openai:test', 'settings' => ['retrieval_limit' => 3]],
			0.8, new VectorFieldInfo('embedding', 'title', ['embedding']), 'hybrid'
		);
	}

	public function testSearchEngineRejectsUnknownStrategy(): void {
		$client = $this->createMock(Client::class);
		$client->expects($this->never())->method('sendRequest');
		$this->expectException(ManticoreSearchClientError::class);
		(new SearchEngine($client))->search(
			'docs', 'frame', [],
			['model' => 'openai:test', 'settings' => ['retrieval_limit' => 3]],
			0.8, new VectorFieldInfo('embedding', 'title', ['embedding']), 'unknown'
		);
	}

	public function testSearchPlanPassesHybridStrategyToEngine(): void {
		$engine = $this->createMock(SearchEngine::class);
		$vectorField = new VectorFieldInfo('embedding', 'title', ['embedding']);
		$model = [
			'id' => '1', 'name' => 'assistant', 'model' => 'openai:test',
			'settings' => ['retrieval_limit' => 3], 'created_at' => '', 'updated_at' => '',
		];
		$engine->expects($this->once())->method('search')->with(
			'docs', '16x48 frame', [], $model, 0.8, $vectorField, 'hybrid', ['Flex Pants', 'activewear']
		)->willReturn([['id' => 1, 'title' => '16x48 frame']]);
		$context = new SearchContext(
			new ConversationRequest('16x48 frame', 'docs', 'assistant', '', '', 'hybrid'),
			$model,
			new ConversationRoute(
				ConversationRoute::SEARCH, '16x48 frame', '',
				'Search indexed data', ['Flex Pants', 'activewear']
			),
			$vectorField, []
		);
		/** @var array{array<int, array<string, mixed>>, list<string>} $result */
		$result = (new ReflectionMethod(Handler::class, 'performSearch'))->invoke(null, $context, $engine);
		$this->assertSame([[['id' => 1, 'title' => '16x48 frame']], []], $result);
	}

	public function testHybridFollowUpSearchesCurrentQuestionUsingNewTerms(): void {
		$engine = $this->createMock(SearchEngine::class);
		$vectorField = new VectorFieldInfo('embedding', 'title', ['embedding']);
		$model = [
			'id' => '1', 'name' => 'assistant', 'model' => 'openai:test',
			'settings' => ['retrieval_limit' => 3], 'created_at' => '', 'updated_at' => '',
		];
		$results = [['id' => 1, 'title' => '16x48 frame']];
		$engine->expects($this->once())->method('search')->with(
			'docs', 'Which 16x48 frame has a metal border?', [], $model, 0.8, $vectorField,
			'hybrid', ['frame', 'metal border']
		)->willReturn($results);
		$context = new SearchContext(
			new ConversationRequest('Which one has a metal border?', 'docs', 'assistant', '', '', 'hybrid'),
			$model,
			new ConversationRoute(
				ConversationRoute::SEARCH, 'Which 16x48 frame has a metal border?',
				'', 'Search current question', ['frame', 'metal border']
			),
			$vectorField, []
		);
		$result = (new ReflectionMethod(Handler::class, 'performSearch'))->invoke(null, $context, $engine);
		$this->assertIsArray($result);
		$this->assertSame([$results, []], $result);
	}

	public function testHybridFollowUpUsesCurrentPlanTermsAfterKnnHistory(): void {
		$engine = $this->createMock(SearchEngine::class);
		$vectorField = new VectorFieldInfo('embedding', 'title', ['embedding']);
		$model = [
			'id' => '1', 'name' => 'assistant', 'model' => 'openai:test',
			'settings' => ['retrieval_limit' => 3], 'created_at' => '', 'updated_at' => '',
		];
		$engine->expects($this->once())->method('search')->with(
			'docs', 'Which 16x48 frame is panoramic?', [], $model, 0.8, $vectorField,
			'hybrid', ['frame', 'panoramic']
		)->willReturn([['id' => 1, 'title' => '16x48 frame']]);
		$context = new SearchContext(
			new ConversationRequest('Which is panoramic?', 'docs', 'assistant', '', '', 'hybrid'),
			$model,
			new ConversationRoute(
				ConversationRoute::SEARCH, 'Which 16x48 frame is panoramic?',
				'', 'Search current question', ['frame', 'panoramic']
			),
			$vectorField, []
		);
		$result = (new ReflectionMethod(Handler::class, 'performSearch'))->invoke(null, $context, $engine);
		$this->assertIsArray($result);
		$this->assertSame([[['id' => 1, 'title' => '16x48 frame']], []], $result);
	}

	private function sqlRequest(string $payload): Request {
		return Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $payload,
				'format' => RequestFormat::SQL,
				'endpointBundle' => Endpoint::Sql,
				'path' => '',
			]
		);
	}

	private function jsonRequest(string $payload): Request {
		return Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => $payload,
				'format' => RequestFormat::JSON,
				'endpointBundle' => Endpoint::Search,
				'path' => '/search',
				'httpMethod' => 'POST',
			]
		);
	}
}
