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
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is a class to handle Manticore queries to Kibana meta tables
 */
class TableKibanaHandler extends BaseEntityHandler {

	const BASE_REQUEST_TYPE = 1;
	const AGG_REQUEST_TYPE = 2;

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
			/** @var array{aggs:array<string,mixed>} $payloadBody */
			$payloadBody = simdjson_decode($payload->body, true);
			$reqType = self::detectRequestType($payloadBody);
			$aggNames = [];
			if ($reqType === self::AGG_REQUEST_TYPE) {
				$aggNames = array_keys($payloadBody['aggs']);
				$resp = [
					'aggregations' => array_combine(
						$aggNames, array_fill(0, sizeof($aggNames), [])
					),
				];
			} else {
				$resp = [
					'_shards' => [
						'failed' => 0,
						'skipped' => 0,
						'successful' => 1,
						'total' => 1,
					],
					'hits' => [
						'hits' => [],
						'max_score' => 0.0,
						'total' => 0,
						'total_relation' => 'eq',
					],
					'status' => 200,
					'timed_out' => false,
					'took' => 0,
				];
				$query = 'SELECT * FROM ' . parent::ENTITY_TABLE;
				$searchConds = [];
				self::extractSearchConds($payloadBody, $searchConds);
				if (array_key_exists('type', $searchConds)) {
					$typeExpr = implode(' OR ', array_map(fn ($v) => "_type='{$v}'", $searchConds['type']));
					$query .= " WHERE {$typeExpr}";
				}
				$queryResult = $manticoreClient->sendRequest($query)->getResult();
				self::postprocessQueryResult($queryResult, $searchConds, $resp);
			}

			return TaskResult::raw($resp);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * Adding Elastic-specific fields required by Kibana to the result data structure
	 *
	 * @param Struct<int|string,mixed> $queryResult
	 * @param array<string,mixed> $searchConds
	 * @param array{hits:array{total:int,hits:array<mixed>}} $resp
	 * @return void
	 */
	protected static function postprocessQueryResult(Struct $queryResult, array $searchConds, array &$resp): void {
		/** @var array{data:array<array{_source:string}>} $resultData */
		$resultData = $queryResult[0];
		$resp['hits']['total'] = sizeof($resultData['data']);
		foreach ($resultData['data'] as $i => &$hit) {
			$hit = [
				'_primary_term' => 1,
				'_score' => 0.0,
				'_seq_no' => 1,
				'_type' => '_doc',
			] + $hit;
			$hit['_source'] = simdjson_decode($hit['_source'], true);
			if (!array_key_exists('filter', $searchConds)) {
				continue;
			}
			if (!self::isHitFiltered($hit['_source'], $searchConds['filter'])) {
				unset($resultData['data'][$i]);
			}
		}
		$resp['hits']['hits'] = $resultData['data'];
		// Updating total to match the size of a filtered dataset 
		$resp['hits']['total'] = sizeof($resp['hits']['hits']);
	}

	/**
	 * Checking if a result row suits all conditions from Kibana's filter
	 *
	 * @param array<int|string,array<mixed>> $hitSource
	 * @param array{fields:array<string>,query:string} $filter
	 * @return bool
	 */
	protected static function isHitFiltered(array $hitSource, array $filter): bool {
		$query = $filter['query'];
		if ($query === '*') {
			return true;
		}
		if ($query[0] === '"' && $query[-1] === '"') {
			$query = substr($filter['query'], 1, -1);
		}
		/** @var array<int|string> $props */
		$props = explode('.', $filter['fields'][0]);
		// Checking all result fields if the field wildcard is passed
		if ($props === ['*']) {
			foreach ($hitSource as $hitSourceVal) {
				if ($hitSourceVal === $query) {
					return true;
				}
			}
			return false;
		}

		// Extracting a result field to filter by
		foreach ($props as $prop) {
			/** @var array<int|string,mixed>|string $hitSource */
			$hitSource = $hitSource[$prop];
		}

		return ($hitSource === $query);
	}

	/**
	 * @param array<string,mixed> $reqBody
	 * @param array<string,mixed> $searchConds
	 * @return void
	 */
	protected static function extractSearchConds(array $reqBody, array &$searchConds): void {
		foreach ($reqBody as $k => $v) {
			if (array_key_exists('type', $searchConds) && array_key_exists('filter', $searchConds)) {
				return;
			}
			if (!is_array($v)) {
				continue;
			}
			if ($k === 'term' && array_key_exists('type', $v)) {
				if (!isset($searchConds['type'])) {
					$searchConds['type'] = [];
				}
				$searchConds['type'][] = $v['type'];
			} elseif ($k === 'simple_query_string') {
				$searchConds['filter'] = $v;
			} else {
				self::extractSearchConds($v, $searchConds);
			}
		}
	}

	/**
	 * @param array<string,mixed> $reqBody
	 * @return int
	 */
	protected static function detectRequestType(array $reqBody): int {
		return array_key_exists('aggs', $reqBody) ? self::AGG_REQUEST_TYPE : self::BASE_REQUEST_TYPE;
	}

}
