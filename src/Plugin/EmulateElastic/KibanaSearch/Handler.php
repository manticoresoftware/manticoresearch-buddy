<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch;

use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Executor as RequestLogicExecutor;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Request\Factory as RequestLogicFactory;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Executor as ResponseLogicExecutor;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Factory as ResponseLogicFactory;
// @phpcs:ignore
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\Logic\Response\Sorting\Metric\CalculatorFactory
	as MetricCalculatorFactory;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\AggNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\BaseNode;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Factory as RequestNodeFactory;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\GroupFilter;
// @phpcs:ignore
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Helpers\FilterExpression\Factory
	as FilterExpressionFactory;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\KibanaSearch\RequestNode\Helpers\TimeZoneExpression;
use Manticoresearch\Buddy\Base\Plugin\EmulateElastic\Payload;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 *  Processes complex aggregation-based requests from Kibana
 */
final class Handler extends BaseHandlerWithClient {

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
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {
			$request = simdjson_decode($payload->body, true);
			if (!is_array($request)) {
				throw new \Exception('Cannot parse Kibana request');
			}

			$tableFieldInfo = new TableFieldInfo($payload->table, $manticoreClient);
			$requestNodes = self::makeRequestNodes($request, $tableFieldInfo);
			$nodeSet = new NodeSet($requestNodes);

			$result = self::buildResponse(
				self::buildResponseData($tableFieldInfo, $nodeSet, $manticoreClient),
				$nodeSet
			);

			return TaskResult::raw($result);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @param TableFieldInfo $tableFieldInfo
	 * @param NodeSet $nodeSet
	 * @param Client $manticoreClient
	 * @return array<int,array<string,mixed>>
	 */
	protected static function buildResponseData(
		TableFieldInfo $tableFieldInfo,
		NodeSet $nodeSet,
		Client $manticoreClient
	): array {
		$manticoreRequest = new SphinxQLRequest();
		$requestLogicExecutor = new RequestLogicExecutor(
			new RequestLogicFactory($nodeSet, $manticoreRequest, $tableFieldInfo)
		);
		$responseLogicExecutor = new ResponseLogicExecutor(
			new ResponseLogicFactory(
				$nodeSet,
				new MetricCalculatorFactory(SphinxQLRequest::COUNT_FIELD_EXPR),
				SphinxQLRequest::COUNT_FIELD_EXPR
			)
		);
		$responseData = [];
		foreach ($tableFieldInfo->getTables() as $table) {
			$manticoreRequest->init($table);
			if (!$requestLogicExecutor
				->init()
				->execute()
			) {
				continue;
			}

			foreach ($nodeSet->getNodes() as $node) {
				$node->setRequest($manticoreRequest)->fillInRequest();
			}

			/** @var array<int,array{data:array<int,array<string,mixed>>,total:int}> $sphinxQLResult */
			$sphinxQLResult = $manticoreClient
				->sendRequest(
					$manticoreRequest->buildQuery()
				)->getResult();
			$responseRows = $sphinxQLResult[0]['data'];
			if (!$responseRows) {
				continue;
			}

			$responseLogicExecutor
				->init()
				->setResponseRows($responseRows)
				->execute();

			$responseData = [...$responseData, ...$responseLogicExecutor->getResponseRows()];
		}

		return $responseData;
	}

	/**
	 * @param array<int,array<string,mixed>> $responseData
	 * @param NodeSet $nodeSet
	 * @return array<mixed>
	 */
	protected static function buildResponse(array $responseData, NodeSet $nodeSet): array {
		/** @var array<AggNode> $aggNodes */
		$aggNodes = $nodeSet->getNodesByClass(AggNode::class);
		/** @var array<GroupFilter> $filterNodes */
		$filterNodes = $nodeSet->getNodesByClass(GroupFilter::class);
		$response = new Response($aggNodes, $filterNodes, SphinxQLRequest::COUNT_FIELD_EXPR);

		return $response->build($responseData)->get();
	}

	/**
	 * @param array<mixed> $request
	 * @param TableFieldInfo $tableFieldInfo
	 * @return array<BaseNode>
	 */
	protected static function makeRequestNodes(array $request, TableFieldInfo $tableFieldInfo): array {
		$requestTableFieldInfo = $tableFieldInfo->get();
		$nonAggFields = array_filter(
			array_keys($requestTableFieldInfo),
			fn ($field) => !$requestTableFieldInfo[$field]
				|| !(reset($requestTableFieldInfo[$field])['aggregatable'])
		);
		$requestParser = new RequestParser(
			$request,
			new RequestNodeFactory(
				new TimeZoneExpression(),
				new FilterExpressionFactory($nonAggFields),
			)
		);

		return $requestParser->parse();
	}
}
