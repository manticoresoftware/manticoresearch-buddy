<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)
 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationHistory;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRequest;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\ConversationRoute;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\Handler;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\SearchContext;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\SearchEngine;
use Manticoresearch\Buddy\Base\Plugin\ConversationalSearch\VectorFieldInfo;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Struct;
use PHPUnit\Framework\TestCase;

final class ConversationExclusionsTest extends TestCase {
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

	public function testExclusionSnapshotIsScopedByConversationAndTable(): void {
		$client = $this->createMock(Client::class);
		$sql = [];
		$client->method('sendRequest')->willReturnCallback(
			function (string $query) use (&$sql): Response {
				$sql[] = $query;
				$response = $this->createMock(Response::class);
				$response->method('hasError')->willReturn(false);
				$rows = [];
				if (str_contains($query, "conversation_uuid = 'conv'")) {
					$rows = [
						['role' => 'assistant', 'message' => 'Here are shows.'],
						['role' => 'excluded_ids:films', 'message' => '["99"]'],
						['role' => 'excluded_ids:shows', 'message' => '["12","9007199254740993"]'],
						['role' => 'user', 'message' => 'Show me alternatives'],
						['role' => 'excluded_ids:shows', 'message' => '["12"]'],
					];
				}
				$response->method('getResult')->willReturn(Struct::fromData([['data' => $rows]]));
				return $response;
			}
		);
		$manager = new ConversationManager($client, 'assistant');
		$shows = $manager->getConversationMessages('conv', indexName: 'shows');
		$this->assertSame(['12', '9007199254740993'], $shows->excludedIds());
		$this->assertSame("user: Show me alternatives\nassistant: Here are shows.\n", $shows->format());
		$this->assertSame(['99'], $manager->getConversationMessages('conv', indexName: 'films')->excludedIds());
		$this->assertSame([], $manager->getConversationMessages('another', indexName: 'shows')->excludedIds());
		$manager->saveTurn(
			'conv', 'assistant', 'Which shows?', 'Here are shows.', 'shows', ['12', '9007199254740993']
		);
		$this->assertStringContainsString("conversation_uuid = 'conv'", $sql[1]);
		$this->assertSame($sql[1], $sql[2]);
		$this->assertStringNotContainsString('AND role', $sql[1]);
		$this->assertStringContainsString('ORDER BY created_at DESC, id DESC LIMIT 100', $sql[1]);
		$this->assertStringContainsString("'excluded_ids:shows'", $sql[4]);
		$this->assertStringContainsString("'assistant'", $sql[4]);
		$this->assertStringContainsString("'user', 'Which shows?'", $sql[4]);
		$this->assertSame(3, substr_count($sql[4], "'conv', 'assistant'"));
		$this->assertStringContainsString('[\\"12\\",\\"9007199254740993\\"]', $sql[4]);
	}

	public function testNoSnapshotInTheHistoryWindowReturnsEmptyList(): void {
		$client = $this->createMock(Client::class);
		$client->method('sendRequest')->willReturnCallback(
			function (): Response {
				$response = $this->createMock(Response::class);
				$response->method('hasError')->willReturn(false);
				$response->method('getResult')->willReturn(
					Struct::fromData([['data' => [['role' => 'user', 'message' => 'New question']]]])
				);
				return $response;
			}
		);
		$history = (new ConversationManager($client, 'assistant'))->getConversationMessages('conv', 1, 'shows');
		$this->assertSame([], $history->excludedIds());
		$this->assertSame("user: New question\n", $history->format());
	}

	/** @dataProvider searchStrategies */
	public function testNewExclusionCombinesWithStoredIdsAndSearchesNow(string $strategy): void {
		$engine = $this->createMock(SearchEngine::class);
		$model = ['id' => '1', 'name' => 'assistant', 'model' => 'openai:test',
			'settings' => [], 'created_at' => '', 'updated_at' => ''];
		$field = new VectorFieldInfo('embedding', 'content', ['embedding']);
		$terms = $strategy === 'hybrid' ? ['TV shows', 'high rating'] : [];
		$engine->expects($this->once())->method('getExcludedIds')
			->with('shows', 'Stranger Things', 'embedding')->willReturn([20]);
		$engine->expects($this->once())->method('search')->with(
			'shows', 'Recommend well-rated TV shows', ['10', '20'], $model, 0.8, $field,
			$strategy, $terms
		)->willReturn([['id' => 30, 'title' => 'Severance']]);
		$context = new SearchContext(
			new ConversationRequest('I do not like Stranger Things', 'shows', 'assistant', 'conv', '', $strategy),
			new ConversationHistory([]), $model,
			new ConversationRoute(
				ConversationRoute::SEARCH, 'Recommend well-rated TV shows',
				'Stranger Things', 'Search alternatives', $terms
			),
			$field, ['10']
		);
		/** @var array{array<int, array<string, mixed>>, array<string, string>, list<string>} $result */
		$result = (new ReflectionMethod(Handler::class, 'performSearch'))->invoke(null, $context, $engine);
		$this->assertSame([['id' => 30, 'title' => 'Severance']], $result[0]);
		$this->assertSame(['10', '20'], $result[2]);
	}

	/** @dataProvider searchStrategies */
	public function testStoredExclusionsSurviveUnrelatedSearchWithoutNewNegativeTerms(string $strategy): void {
		$engine = $this->createMock(SearchEngine::class);
		$model = ['id' => '1', 'name' => 'assistant', 'model' => 'openai:test',
			'settings' => [], 'created_at' => '', 'updated_at' => ''];
		$field = new VectorFieldInfo('embedding', 'content', ['embedding']);
		$terms = $strategy === 'hybrid' ? ['TV series'] : [];
		$engine->expects($this->never())->method('getExcludedIds');
		$engine->expects($this->once())->method('search')->with(
			'shows', 'Recommend TV series', ['10', '20'], $model, 0.8, $field,
			$strategy, $terms
		)->willReturn([['id' => 30, 'title' => 'Severance']]);
		$context = new SearchContext(
			new ConversationRequest('Recommend TV series', 'shows', 'assistant', 'conv', '', $strategy),
			new ConversationHistory([]), $model,
			new ConversationRoute(
				ConversationRoute::SEARCH, 'Recommend TV series',
				'', 'Search indexed data', $terms
			),
			$field, ['10', '20']
		);
		/** @var array{array<int, array<string, mixed>>, array<string, string>, list<string>} $result */
		$result = (new ReflectionMethod(Handler::class, 'performSearch'))->invoke(null, $context, $engine);
		$this->assertSame([['id' => 30, 'title' => 'Severance']], $result[0]);
		$this->assertSame(['10', '20'], $result[2]);
	}

	/** @return array<string, array{string}> */
	public static function searchStrategies(): array {
		return ['knn' => ['knn'], 'hybrid' => ['hybrid']];
	}
}
