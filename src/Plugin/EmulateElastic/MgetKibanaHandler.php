<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the class to get info on a requested Kibana entity
 */
class MgetKibanaHandler extends BaseEntityHandler {

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
			/** @var array{docs:array<array<string,string>>} $payloadBody */
			$payloadBody = json_decode($payload->body, true);
			$entityInfo = $payloadBody['docs'];
			$getEntitiesCond = self::buildEntitiesCond($entityInfo);
			$query = 'SELECT _id, _index, _source FROM `' . self::ENTITY_TABLE . "` WHERE {$getEntitiesCond}";
			/** @var array{
			 * error?:string,
			 * 0:array{data?:array<array{_source:string,_id:string,_index:string}>}
			 * } $queryResult
			 */
			$queryResult = $manticoreClient->sendRequest($query)->getResult();
			if (isset($queryResult['error']) || !isset($queryResult[0]['data']) || !$queryResult[0]['data']) {
				$respDocs = [];
			} else {
				foreach ($queryResult[0]['data'] as $entity) {
					$respDocs[] = [
						'_id' => $entity['_id'],
						'_index' => $entity['_index'],
						'_primary_term' => 1,
						'_seq_no' => 0,
						'_source' => json_decode($entity['_source'], true),
						'_type' => '_doc',
						'_version' => 1,
						'found' => true,
					];
				}
			}
			$resp = [
				'docs' => $respDocs,
			];

			return TaskResult::raw($resp);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @param array<array<string,string>> $entitiesInfo
	 * @return string
	 */
	protected static function buildEntitiesCond(array $entitiesInfo): string {
		$condExprs = [];
		foreach ($entitiesInfo as $entityInfo) {
			$conds = [];
			foreach ($entityInfo as $k => $v) {
				$condKey = ($k === '_index') ? '_index_alias' : $k;
				$conds[] = "{$condKey}='{$v}'";
			}
			$condExprs[] = implode(' AND ', $conds);
		}

		return implode(' OR ', $condExprs);
	}
}
