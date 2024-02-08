<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Knn;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use PHPSQLParser\exceptions\UnsupportedFeatureException;
use RuntimeException;

final class Handler extends BaseHandlerWithClient
{
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
			return TaskResult::raw(self::getKnnResult($manticoreClient, $payload, $queryVector));
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	private static function getQueryVectorValue(Client $client, Payload $payload): string|false {
		$document = $client
			->sendRequest('SELECT * FROM ' . $payload->table . ' WHERE id = ' . $payload->docId)
			->getResult();

		if (is_array($document) && !empty($document[0]['data'])) {
			return $document[0]['data'][0][$payload->field] ?? false;
		}

		return false;
	}


	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @param string $queryVector
	 * @return array <string, string>
	 * @throws ManticoreSearchClientError
	 */
	private static function getKnnResult(Client $manticoreClient, Payload $payload, string $queryVector): array {
		if ($payload->endpointBundle === Endpoint::Search) {
			return self::knnHttpQuery($manticoreClient, $payload, $queryVector);
		}

		return self::knnSqlQuery($manticoreClient, $payload, $queryVector);
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @param string $queryVector
	 * @return array <string, string>
	 * @throws ManticoreSearchClientError
	 */
	private static function knnHttpQuery(Client $manticoreClient, Payload $payload, string $queryVector): array {
		$query = [
			'index' => $payload->table,
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

		if ($payload->select !== ['*']) {
			$query['_source'] = $payload->select;
		}

		if ($payload->condition) {
			$query['knn']['filter'] = $payload->condition;
		}

		$result = $manticoreClient
			->sendRequest((string)json_encode($query), Endpoint::Search->value)
			->getResult();

		if (is_array($result['hits']) && isset($result['hits']['hits'])) {
			foreach ($result['hits']['hits'] as $k => $v) {
				if ($v['_id'] !== $payload->docId) {
					continue;
				}

				unset($result['hits']['hits'][$k]);
			}
		}

		return $result;
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @param string $queryVector
	 * @return array <string, string>
	 * @throws ManticoreSearchClientError|UnsupportedFeatureException
	 */
	private static function knnSqlQuery(Client $manticoreClient, Payload $payload, string $queryVector): array {

		self::substituteParsedQuery($payload, $queryVector);
			$result = $manticoreClient
				->sendRequest($payload::$sqlQueryParser::getCompletedPayload())
				->getResult();

		if (is_array($result[0])) {
			foreach ($result[0]['data'] as $k => $v) {
				if ($v['id'] !== (int)$payload->docId) {
					continue;
				}

				unset($result[0]['data'][$k]);
			}
		}


		return $result;
	}

	private static function substituteParsedQuery(Payload $payload, string $queryVector): void {

		$parsedQuery = $payload::$sqlQueryParser::getParsedPayload();
		if (!is_array($parsedQuery)) {
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
