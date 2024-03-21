<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\BaseGetHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class AlterViewHandler extends BaseHandlerWithClient
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
	 * @return Task
	 */
	public function run(): Task {


		/**
		 * @param Client $manticoreClient
		 * @return TaskResult
		 * @throws ManticoreSearchClientError
		 */
		$taskFn = static function (Client $manticoreClient): TaskResult {

			if (!$manticoreClient->hasTable($tableName)) {
				return TaskResult::none();
			}

			$fields[] = 'original_query';
			$stringFields = implode(',', $fields);
			$sql = /** @lang manticore */
				"SELECT $stringFields FROM $tableName WHERE match('@name \"$name\"') LIMIT 1";
			$rawResult = $manticoreClient->sendRequest($sql);
			if ($rawResult->hasError()) {
				throw ManticoreSearchClientError::create($rawResult->getError());
			}

			$rawResult = $rawResult->getResult();

			if (empty($rawResult[0]['data'])) {
				return TaskResult::none();
			}

			$formattedResult = static::formatResult($rawResult[0]['data'][0]['original_query']);

			$resultData = [
				$type => $name,
				'Create Table' => $formattedResult,
			];

			foreach ($fields as $field) {
				if ($field === 'original_query') {
					continue;
				}
				$resultData[$field] = $rawResult[0]['data'][0][$field];
			}

			$taskResult = TaskResult::withData([$resultData])
				->column($type, Column::String)
				->column('Create Table', Column::String);

			foreach ($fields as $field) {
				if ($field === 'original_query') {
					continue;
				}
				$taskResult = $taskResult->column($field, Column::String);
			}

			return $taskResult;
		};

		return Task::create(
			$taskFn,
			[$name, $type, $fields, $tableName, $this->manticoreClient]
		)->run();
	}
}
