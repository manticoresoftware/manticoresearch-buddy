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
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class Handler extends BaseHandlerWithClient
{
	/**
	 * Initialize the executor
	 *
	 * @param  Payload  $payload
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
			$knnField = self::getKnnField($manticoreClient, $payload);
			$queryVector = self::getQueryVectorValue($manticoreClient, $payload, $knnField);

			if ($queryVector === false) {
				return TaskResult::none();
			}
			return TaskResult::raw(self::getKnnResult($manticoreClient, $payload, $queryVector));
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function getKnnField(Client $manticoreClient, Payload $payload): string {
		$descResult = $manticoreClient
			->sendRequest('DESC '.$payload->table)
			->getResult();

		$knnField = false;

		if (!is_array($descResult) || empty($descResult[0]['data'])) {
			throw ManticoreSearchClientError::create('Manticore didn\'t answer');
		}

		foreach ($descResult[0]['data'] as $field) {
			if ($field['Type'] !== 'float_vector') {
				continue;
			}

			$knnField = $field['Field'];
		}

		if (!$knnField) {
			throw ManticoreSearchResponseError::create('Table '.$payload->table.' didnt have any KNN fields');
		}

		return $knnField;
	}

	private static function getQueryVectorValue(Client $client, Payload $payload, string $knnField): string|false {
		$document = $client
			->sendRequest('SELECT * FROM '.$payload->table.' WHERE id = '.$payload->docId)
			->getResult();

		if (is_array($document) && !empty($document[0]['data'])) {
			return $document[0]['data'][0][$knnField];
		}

		return false;
	}


	/**
	 * @param  Client  $manticoreClient
	 * @param  Payload  $payload
	 * @param  string  $queryVector
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
	 * @param  Client  $manticoreClient
	 * @param  Payload  $payload
	 * @param  string  $queryVector
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
	 * @param  Client  $manticoreClient
	 * @param  Payload  $payload
	 * @param  string  $queryVector
	 * @return array <string, string>
	 * @throws ManticoreSearchClientError
	 */
	private static function knnSqlQuery(Client $manticoreClient, Payload $payload, string $queryVector): array {
		$query = 'SELECT '.implode(',', $payload->select).' FROM '.$payload->table.' WHERE '.
			'knn ('.$payload->field.", $payload->k, ($queryVector))";

		$result = $manticoreClient
			->sendRequest($query)
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
}
