<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Knn;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class Handler extends BaseHandlerWithClient {
	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {
			$queryVector = self::getQueryVectorValue($manticoreClient, $payload);

			if ($queryVector === false) {
				return TaskResult::none();
			}
			$resp = self::getKnnResult($manticoreClient, $payload, $queryVector);
			return TaskResult::fromResponse($resp);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @param Client $client
	 * @param Payload $payload
	 *
	 * @return string|false
	 * @throws ManticoreSearchClientError|GenericError
	 */
	private static function getQueryVectorValue(Client $client, Payload $payload): string|false {
		$request = $client
			->sendRequest(
				'SELECT * FROM ' . $payload->table .
				' WHERE id = ' . $payload->docId
			);

		if ($request->hasError()) {
			ManticoreSearchResponseError::throw((string)$request->getError());
		}
		$document = $request->getResult();

		if (!isset($document[0])) {
			return false;
		}
		/** @var array{data:array<int,array<string,string>>} $documentStruct */
		$documentStruct = $document[0];
		if (!empty($documentStruct['data'])) {
			if (!empty($documentStruct['data'][0][$payload->field])) {
				return $documentStruct['data'][0][$payload->field];
			}
			return false;
		}

		return false;
	}


	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @param string $queryVector
	 * @return Response
	 * @throws ManticoreSearchClientError|QueryParseError
	 */
	private static function getKnnResult(Client $manticoreClient, Payload $payload, string $queryVector): Response {
		if ($payload->endpointBundle === Endpoint::Search) {
			return self::knnHttpQuery($manticoreClient, $payload, $queryVector);
		}

		return self::knnSqlQuery($manticoreClient, $payload, $queryVector);
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @param string $queryVector
	 *
	 * @return Response
	 * @throws ManticoreSearchClientError|GenericError
	 */
	private static function knnHttpQuery(Client $manticoreClient, Payload $payload, string $queryVector): Response {
		$query = [
			'table' => $payload->table,
			'knn' => [
				'field' => $payload->field,
				'k' => (int)$payload->k,
				'query_vector' => array_map(
					function ($val) {
						return (float)$val;
					}, explode(',', $queryVector)
				),
			],
		];

		if ($payload->select !== []) {
			$query['_source'] = $payload->select;
		}

		if ($payload->condition) {
			$query['knn']['filter'] = $payload->condition;
		}

		$resp = $manticoreClient
			->sendRequest((string)json_encode($query), Endpoint::Search->value);

		if ($resp->hasError()) {
			ManticoreSearchResponseError::throw((string)$resp->getError());
		}

		/** @var array{hits:array{hits:array<array{_id?:int}>}} $result */
		$result = $resp->getResult()->toArray();
		$docId = (int)$payload->docId;
		$hits = [];
		foreach ($result['hits']['hits'] as $v) {
			if (isset($v['_id']) && $v['_id'] === $docId) {
				continue;
			}

			$hits[] = $v;
		}
		$result['hits']['hits'] = $hits;
		return Response::fromBody((string)json_encode($result));
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @param string $queryVector
	 * @return Response
	 * @throws QueryParseError|ManticoreSearchClientError|GenericError
	 */
	private static function knnSqlQuery(Client $manticoreClient, Payload $payload, string $queryVector): Response {

		self::substituteParsedQuery($payload, $queryVector);

		$resp = $manticoreClient
				->sendRequest($payload::$sqlQueryParser::getCompletedPayload());

		if ($resp->hasError()) {
			ManticoreSearchResponseError::throw((string)$resp->getError());
		}

		$result = $resp->getResult()->toArray();
		if (is_array($result[0])) {
			$docId = (int)$payload->docId;
			foreach ($result[0]['data'] as $k => $v) {
				if (!isset($v['id']) || $v['id'] !== $docId) {
					continue;
				}

				unset($result[0]['data'][$k]);
			}
			$resp = Response::fromBody((string)json_encode($result));
		}

		return $resp;
	}

	/**
	 * This method updates SQL parsed payload.
	 * For example this method allows to modify queries from
	 * SELECT * FROM tbl WHERE knn(query_vector, 5, 1)
	 * To
	 * SELECT * FROM tbl WHERE knn(query_vector, 5, (-0.9999,-0.9999,-0.9999,-0.9999))
	 *
	 * @param Payload $payload
	 * @param string $queryVector
	 * @return void
	 */
	private static function substituteParsedQuery(Payload $payload, string $queryVector): void {

		$parsedQuery = $payload::$sqlQueryParser::getParsedPayload();
		if ($parsedQuery === null) {
			return;
		}

		foreach ($parsedQuery['WHERE'] as $k => $condition) {
			if ($condition['base_expr'] !== 'knn') {
				continue;
			}

			$parsedQuery['WHERE'][$k]['sub_tree'][2] = [
			'expr_type' => 'const',
			'base_expr' => "($queryVector)",
			'sub_tree' => false,
			];
		}
		$payload::$sqlQueryParser::setParsedPayload($parsedQuery);
	}
}
