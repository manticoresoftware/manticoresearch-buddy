<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the class to get info on a requested Kibana entity
 */
class InitKibanaHandler extends BaseEntityHandler {

	use Traits\EntityAliasTrait;
	use Traits\KibanaVersionTrait;
	use Traits\QueryMapLoaderTrait;

	const DEFAULT_KIBANA_INDEX = '.kibana_1';
	const DEFAULT_KIBANA_INDEX_ALIAS = '.kibana';

	/**
	 *  Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, HTTPClient $manticoreClient): TaskResult {
			$alias = $payload->path;
			$entityQuery = 'SELECT _id, _index, _source FROM `'
				. self::ENTITY_TABLE . "` WHERE _index_alias='{$alias}' AND _type='settings'";
			/** @var array{error?:string,0:array{data?:array<array{_index:string,_source:string}>}} $queryResult */
			$queryResult = $manticoreClient->sendRequest($entityQuery)->getResult();
			if (isset($queryResult['error']) || !isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
				throw self::errorResponse($alias);
			}

			$resp = [];
			foreach ($queryResult[0]['data'] as $entity) {
				$resp[$entity['_index']] = [
					'aliases' => [
						$alias => [],
					],
				] + simdjson_decode($entity['_source'], true);
			}

			return TaskResult::raw($resp);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 *
	 * @param string $alias
	 * @return GenericError
	 */
	protected static function errorResponse(string $alias): GenericError {
		$resp = [
			'error' => [
				'index' => $alias,
				'index_uuid' => '_na_',
				'reason' => "no such index [{$alias}]",
				'resource.id' => $alias,
				'resource.type' => 'index_or_alias',
				'root_cause' => [
					[
						'index' => $alias,
						'index_uuid' => '_na_',
						'reason' => "no such index [{$alias}]",
						'resource.id' => $alias,
						'resource.type' => 'index_or_alias',
						'type' => 'index_not_found_exception',
					],
				],
				'type' => 'index_not_found_exception',
			],
			'status' => 404,
		];
		$customError = GenericError::create('', false);
		$customError->setResponseErrorBody($resp);
		$customError->setResponseErrorCode(404);

		return $customError;
	}
}
