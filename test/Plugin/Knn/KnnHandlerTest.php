<?php declare(strict_types=1);

use Manticoresearch\Buddy\Base\Plugin\Knn\Handler;
use Manticoresearch\Buddy\Base\Plugin\Knn\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\SqlQueryParser;
use PHPUnit\Framework\TestCase;
use Swoole\Event;

final class KnnHandlerTest extends TestCase {
	public static function setUpBeforeClass(): void {
		Payload::setParser(SqlQueryParser::getInstance());
	}

	public function testSqlDocIdQueryWithLimitFetchesOneExtraRowBeforeRemovingSelf(): void {
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => "P01: syntax error, unexpected integer, expecting '(' near '1'",
				'payload' => 'SELECT id, knn_dist() AS distance FROM t WHERE knn(v, 25, 1) limit 2',
				'format' => RequestFormat::SQL,
				'endpointBundle' => ManticoreEndpoint::Sql,
				'path' => 'sql?mode=raw',
			]
		);

		$this->assertTrue(Payload::hasMatch($request));
		$payload = Payload::fromRequest($request);

		$mockClient = $this->createMock(Client::class);
		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnCallback(
				function (string $query, ?string $endpoint = null): Response {
					if ($query === 'SELECT * FROM t WHERE id = 1') {
						return self::createDocResponse();
					}

					$this->assertSame(ManticoreEndpoint::Sql->value, $endpoint);
					$this->assertStringContainsString('knn(v,25,(1,0))', $query);
					$this->assertStringContainsString('LIMIT 3', $query);

					return self::createSqlKnnResponse();
				}
			);

		$handler = new Handler($payload);
		$handler->setManticoreClient($mockClient);

		go(
			function () use ($handler): void {
				$task = $handler->run();
				$task->wait(true);

				$this->assertTrue($task->isSucceed());
				$result = $task->getResult()->getStruct();
				/** @var array<int, array{total:int, data:array<int, array{id:int}>}> $result */
				$this->assertSame(2, $result[0]['total']);
				$this->assertSame(2, sizeof($result[0]['data']));
				$this->assertSame([2, 3], array_column($result[0]['data'], 'id'));
			}
		);
		Event::wait();
	}

	public function testHttpDocIdQueryWithSizeFetchesOneExtraHitBeforeRemovingSelf(): void {
		$request = Request::fromArray(
			[
				'version' => Buddy::PROTOCOL_VERSION,
				'error' => '',
				'payload' => '{"table":"t","knn":{"field":"v","k":25,"doc_id":1},"size":20}',
				'format' => RequestFormat::JSON,
				'endpointBundle' => ManticoreEndpoint::Search,
				'path' => 'search',
			]
		);

		$this->assertTrue(Payload::hasMatch($request));
		$payload = Payload::fromRequest($request);

		$mockClient = $this->createMock(Client::class);
		$mockClient->expects($this->exactly(2))
			->method('sendRequest')
			->willReturnCallback(
				function (string $query, ?string $endpoint = null): Response {
					if ($query === 'SELECT * FROM t WHERE id = 1') {
						return self::createDocResponse();
					}

					$this->assertSame(ManticoreEndpoint::Search->value, $endpoint);
					$body = json_decode($query, true, 512, JSON_THROW_ON_ERROR);
					/** @var array{size:int, knn:array{k:int, query_vector:array<int, float>}} $body */
					$this->assertSame(21, $body['size']);
					$this->assertSame(25, $body['knn']['k']);
					$this->assertEquals([1.0, 0.0], $body['knn']['query_vector']);

					return self::createHttpKnnResponse();
				}
			);

		$handler = new Handler($payload);
		$handler->setManticoreClient($mockClient);

		go(
			function () use ($handler): void {
				$task = $handler->run();
				$task->wait(true);

				$this->assertTrue($task->isSucceed());
				$result = $task->getResult()->getStruct();
				if (is_string($result)) {
					$result = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
				}

				/** @var array{hits:array{total:int, hits:array<int, array{_id:int}>}} $result */
				$this->assertSame(24, $result['hits']['total']);
				$this->assertSame([2, 3], array_column($result['hits']['hits'], '_id'));
			}
		);
		Event::wait();
	}

	private static function createDocResponse(): Response {
		return Response::fromBody(
			(string)json_encode(
				[
					[
						'total' => 1,
						'error' => '',
						'warning' => '',
						'columns' => [
							['id' => ['type' => 'long long']],
							['f' => ['type' => 'string']],
							['v' => ['type' => 'string']],
						],
						'data' => [['id' => 1, 'f' => 'doc1', 'v' => '1,0']],
					],
				]
			)
		);
	}

	private static function createSqlKnnResponse(): Response {
		return Response::fromBody(
			(string)json_encode(
				[
					[
						'total' => 3,
						'error' => '',
						'warning' => '',
						'columns' => [
							['id' => ['type' => 'long long']],
							['distance' => ['type' => 'float']],
						],
						'data' => [
							['id' => 1, 'distance' => 0],
							['id' => 2, 'distance' => 1],
							['id' => 3, 'distance' => 4],
						],
					],
				]
			)
		);
	}

	private static function createHttpKnnResponse(): Response {
		return Response::fromBody(
			(string)json_encode(
				[
					'took' => 0,
					'timed_out' => false,
					'hits' => [
						'total' => 25,
						'total_relation' => 'eq',
						'hits' => [
							[
								'_id' => 1,
								'_score' => 1,
								'_knn_dist' => 0,
								'_source' => ['f' => 'doc1', 'v' => [1, 0]],
							],
							[
								'_id' => 2,
								'_score' => 1,
								'_knn_dist' => 1,
								'_source' => ['f' => 'doc2', 'v' => [2, 0]],
							],
							[
								'_id' => 3,
								'_score' => 1,
								'_knn_dist' => 4,
								'_source' => ['f' => 'doc3', 'v' => [3, 0]],
							],
						],
					],
				]
			)
		);
	}
}
