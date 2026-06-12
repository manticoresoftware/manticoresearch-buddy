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
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class CreateViewHandler extends BaseHandlerWithClient {
	/**
	 * Initialize the executor
	 *
	 * @param Payload<array{
	 *        CREATE: array{
	 *            base_expr: string,
	 *            sub_tree: array{
	 *                 expr_type: string,
	 *                 base_expr: string
	 *             }[]
	 *        },
	 *        VIEW: array{
	 *            base_expr: string,
	 *            name: string,
	 *            no_quotes: array{
	 *                delim: bool,
	 *                parts: string[]
	 *            },
	 *            create-def: bool,
	 *            options: bool,
	 *            to: array{
	 *                expr_type: string,
	 *                table: string,
	 *                base_expr: string,
	 *                no_quotes: array{
	 *                    delim: bool,
	 *                    parts: string[]
	 *                }
	 *            }
	 *        },
	 *        SELECT: array{
	 *            array{
	 *                expr_type: string,
	 *                alias: bool|array{
	 *                    as: bool,
	 *                    name: string,
	 *                    base_expr: string,
	 *                    no_quotes: array{
	 *                        delim: bool,
	 *                        parts: string[]
	 *                    }
	 *                },
	 *                base_expr: string,
	 *                no_quotes: array{
	 *                    delim: bool,
	 *                    parts: string[]
	 *                },
	 *                sub_tree: mixed,
	 *                delim: bool|string
	 *            }[]
	 *        },
	 *        FROM: array{
	 *            array{
	 *                expr_type: string,
	 *                table: string,
	 *                no_quotes: array{
	 *                    delim: bool,
	 *                    parts: string[]
	 *                },
	 *                alias: bool,
	 *                hints: bool,
	 *                join_type: string,
	 *                ref_type: bool,
	 *                ref_clause: bool,
	 *                base_expr: string,
	 *                sub_tree: bool|array{}
	 *            }[]
	 *        },
	 *        LIMIT?: string
	 *    }> $payload
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
		 * @throws ManticoreSearchClientError|GenericError
		 */
		$taskFn = function (): TaskResult {
			$payload = $this->payload;

			$parsedPayload = $payload->model->getPayload();

			/**
			 * @var string $tableName
			 */
			$tableName = $parsedPayload['FROM'][0]['table'];
			$sourceName = strtolower($tableName);
			$viewName = strtolower($parsedPayload['VIEW']['no_quotes']['parts'][0]);
			$destinationTableName = strtolower($parsedPayload['VIEW']['to']['no_quotes']['parts'][0]);

			if (isset($parsedPayload['LIMIT'])) {
				throw GenericError::create("Can't use query with limit");
			}


			$this->checkViewName($viewName);

			if (!$this->manticoreClient->hasTable($destinationTableName)) {
				return TaskResult::withError('Destination table non exist');
			}


			$sql = /** @lang ManticoreSearch */
				'SELECT * FROM ' . ResourceTable::name(ResourceTable::RESOURCE_SOURCE, $sourceName);

			$result = $this->manticoreClient->sendRequest($sql)->getResult();

			if (is_array($result[0]) && empty($result[0]['data'])) {
				throw ManticoreSearchClientError::create('Chosen source not exist');
			}

			$this->createViewsTable($viewName);

			unset($parsedPayload['CREATE'], $parsedPayload['VIEW']);
			/** @var array{data:array<int,array<string,string>>} $resultStruct */
			$resultStruct = $result[0];
			$sourceRecords = $resultStruct['data'];

			$newViews = (new ViewRecordCreator($this->manticoreClient))->create(
				$viewName,
				$parsedPayload,
				$sourceName,
				$payload->originQuery,
				$destinationTableName,
				sizeof($sourceRecords)
			);

			foreach ($sourceRecords as $source) {
				$source['destination_name'] = $newViews[$source['full_name']]['destination_name'];
				$source['query'] = $newViews[$source['full_name']]['query'];
				$this->payload::$processor->execute('runWorker', [$source]);
			}


			return TaskResult::none();
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function checkViewName(string $viewName): void {
		if ($this->manticoreClient->hasTable(
			ResourceTable::name(ResourceTable::RESOURCE_MATERIALIZED_VIEW, $viewName)
		)) {
			throw ManticoreSearchClientError::create("View $viewName already exist");
		}
	}

	/**
	 * @param string $viewName
	 *
	 * @return void
	 * @throws ManticoreSearchClientError
	 */
	private function createViewsTable(string $viewName): void {
		$tableName = ResourceTable::name(ResourceTable::RESOURCE_MATERIALIZED_VIEW, $viewName);
		$sql = /** @lang ManticoreSearch */
			'CREATE TABLE ' . $tableName .
			' (id bigint, name text attribute indexed, source_name text, destination_name text, ' .
			'query text, original_query text, suspended bool)';

		$request = $this->manticoreClient->sendRequest($sql);
		if ($request->hasError()) {
			throw ManticoreSearchClientError::create((string)$request->getError());
		}
	}
}
