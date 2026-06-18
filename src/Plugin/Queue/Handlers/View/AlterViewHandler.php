<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View;

use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class AlterViewHandler extends BaseHandlerWithClient {

	/**
	 * Initialize the executor
	 *
	 * @param Payload<array{
	 *       ALTER: array{
	 *           base_expr: string,
	 *           sub_tree: mixed[]
	 *       },
	 *       VIEW: array{
	 *           base_expr: string,
	 *           name: string,
	 *           no_quotes: array{
	 *               delim: bool,
	 *               parts: string[]
	 *           },
	 *           create-def: bool,
	 *           options: array{
	 *               expr_type: string,
	 *               base_expr: string,
	 *               delim: string,
	 *               sub_tree: array{
	 *                   expr_type: string,
	 *                   base_expr: string,
	 *                   delim: string,
	 *                   sub_tree: array{
	 *                       expr_type: string,
	 *                       base_expr: string
	 *                   }[]
	 *               }[]
	 *           }[]
	 *       }
	 *   }> $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request
	 * @return Task
	 */
	public function run(): Task {

		/**
		 * @return TaskResult
		 * @throws ManticoreSearchClientError
		 */
		$taskFn = function (): TaskResult {


			$parsedPayload = $this->payload->model->getPayload();
			$name = strtolower($parsedPayload['VIEW']['no_quotes']['parts'][0] ?? '');
			$viewsTable = ResourceTable::name(ResourceTable::RESOURCE_MATERIALIZED_VIEW, $name);

				$sql = /** @lang manticore */
					"SELECT * FROM $viewsTable";
			$viewRowsResponse = $this->manticoreClient->sendRequest($sql);
			if ($viewRowsResponse->hasError()) {
				throw ManticoreSearchClientError::create((string)$viewRowsResponse->getError());
			}

			return $this->processAlter($viewRowsResponse);
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * @param Response $viewRowsResponse
	 * @return TaskResult
	 * @throws ManticoreSearchClientError
	 */
	private function processAlter(Response $viewRowsResponse): TaskResult {

		$ids = [];
		$result = $viewRowsResponse->getResult();
		if (isset($result[0])) {
			/** @var array{data:array<int,array<string,string>>} $resultStruct */
			$resultStruct = $result[0];
			$sourceInstances = $this->getSourceInstancesByFullName($resultStruct['data']);
			foreach ($resultStruct['data'] as $row) {
				$ids[] = $row['id'];

				if (!isset($sourceInstances[$row['source_name']])) {
					return TaskResult::withError(
						"Can't ALTER view without referred source. Create source ({$row['source_name']}) first"
					);
				}
				$this->handleProcessWorkers($sourceInstances[$row['source_name']], $row);
			}

			if ($ids !== []) {
				$this->updateViewsStatus($ids);
			}
		}
		return TaskResult::withTotal(sizeof($ids));
	}

	/**
	 * @param array<int,array<string,string>> $viewRows
	 * @return array<string,array<string,string>>
	 * @throws ManticoreSearchClientError
	 */
	private function getSourceInstancesByFullName(array $viewRows): array {
		if ($viewRows === []) {
			return [];
		}

		$sourceTable = ResourceTable::name(
			ResourceTable::RESOURCE_SOURCE,
			$this->getSourceNameFromFullName($viewRows[0]['source_name'])
		);
		$sql = /** @lang ManticoreSearch */
			"SELECT * FROM $sourceTable LIMIT 99999";
		$response = $this->manticoreClient->sendRequest($sql);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create((string)$response->getError());
		}

		$instances = [];
		$result = $response->getResult();
		/** @var array{data:array<int,array<string,string>>} $resultStruct */
		$resultStruct = $result[0];
		foreach ($resultStruct['data'] as $source) {
			$instances[$source['full_name']] = $source;
		}

		return $instances;
	}

	private function getSourceNameFromFullName(string $sourceFullName): string {
		return (string)preg_replace('/_\d+$/', '', $sourceFullName);
	}


	/**
	 * @return array{
	 *       ALTER: array{
	 *           base_expr: string,
	 *           sub_tree: mixed[]
	 *       },
	 *       VIEW: array{
	 *           base_expr: string,
	 *           name: string,
	 *           no_quotes: array{
	 *               delim: bool,
	 *               parts: string[]
	 *           },
	 *           create-def: bool,
	 *           options: array{
	 *               expr_type: string,
	 *               base_expr: string,
	 *               delim: string,
	 *               sub_tree: array{
	 *                   expr_type: string,
	 *                   base_expr: string,
	 *                   delim: string,
	 *                   sub_tree: array{
	 *                       expr_type: string,
	 *                       base_expr: string
	 *                   }[]
	 *               }[]
	 *           }[]
	 *       }
	 *   }
	 */
	private function getParsedPayload(): array {

		/** @var Model<array{ALTER: array{base_expr: string,sub_tree: mixed[]},
		 *   VIEW: array{base_expr: string,name: string,no_quotes: array{delim: bool,parts: string[]},
		 *   create-def: bool,options: array{expr_type: string,base_expr: string,delim: string,
		 *   sub_tree: array{expr_type: string,base_expr: string,delim: string,
		 *   sub_tree: array{expr_type: string,base_expr: string}[]}[]}[]}}> $model
		 */
		$model = $this->payload->model;
		return $model->getPayload();
	}

	/**
	 * @param array<string, string> $instance
	 * @param array<string, string> $row
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
*/
	private function handleProcessWorkers(array $instance, array $row): void {
		$value = $this->getParsedPayload()['VIEW']['options'][0]['sub_tree'][2]['base_expr'];

		if ((string)$row['suspended'] === $value) {
			throw ManticoreSearchClientError::create(
				'Selected materialized view has already '.
				($value === '0' ? 'resumed' : 'suspended')
			);
		}

		if ($value === '0') {
			$instance['destination_name'] = $row['destination_name'];
			$instance['query'] = $row['query'];

			$this->payload::$processor->execute('runWorker', [$instance]);
		} else {
			$this->payload::$processor->execute('stopWorkerById', [$row['source_name']]);
		}
	}

	/**
	 * @param array<string> $ids
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function updateViewsStatus(array $ids): void {
		$option = strtolower($this->getParsedPayload()['VIEW']['options'][0]['sub_tree'][0]['base_expr']);
		$value = $this->getParsedPayload()['VIEW']['options'][0]['sub_tree'][2]['base_expr'];
		$viewName = strtolower($this->getParsedPayload()['VIEW']['no_quotes']['parts'][0]);
		$viewsTable = ResourceTable::name(ResourceTable::RESOURCE_MATERIALIZED_VIEW, $viewName);

		$stringIds = implode(',', $ids);
		$sql = /** @lang manticore */
			"UPDATE $viewsTable SET $option = $value WHERE id in ($stringIds)";
		$rawResult = $this->manticoreClient->sendRequest($sql);
		if ($rawResult->hasError()) {
			throw ManticoreSearchClientError::create((string)$rawResult->getError());
		}
	}
}
