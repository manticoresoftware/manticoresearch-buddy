<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Base\Plugin\Queue\QueueProcess;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;

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
		 * @param Payload $payload
		 * @param Client $manticoreClient
		 * @return TaskResult
		 * @throws ManticoreSearchClientError
		 */
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {

			$viewsTable = Payload::VIEWS_TABLE_NAME;

			if (!$manticoreClient->hasTable($viewsTable)) {
				return TaskResult::none();
			}

			$name = $payload->parsedPayload['VIEW']['no_quotes']['parts'][0] ?? '';

			$option = $payload->parsedPayload['VIEW']['options'][0]['sub_tree'][0]['base_expr'];
			$value = $payload->parsedPayload['VIEW']['options'][0]['sub_tree'][2]['base_expr'];

			$sql = /** @lang manticore */
				"SELECT * FROM $viewsTable WHERE match('@name \"$name\"')";
			$rawResult = $manticoreClient->sendRequest($sql);
			if ($rawResult->hasError()) {
				throw ManticoreSearchClientError::create($rawResult->getError());
			}

			$ids = [];

			foreach ($rawResult->getResult()[0]['data'] as $row) {
				$ids[] = $row['id'];


				$sourceQuery = 'SELECT * FROM ' . Payload::SOURCE_TABLE_NAME . " WHERE match('@full_name \"{$row['source_name']}\"')";
				$instance = $manticoreClient->sendRequest($sourceQuery);
				if ($instance->hasError()) {
					throw ManticoreSearchClientError::create($instance->getError());
				}

				if (empty($instance->getResult()[0]['data'])){
					return TaskResult::withError("Can't ALTER view without referred source. Create source for current view first");
				}

				if ($value === '0') {
						$instance = $instance->getResult()[0]['data'][0];
						$instance['destination_name'] = $row['destination_name'];
						$instance['query'] = $row['query'];

					QueueProcess::getInstance()
						->getProcess()
						->execute('runWorker', [$instance]);
				} else {
					QueueProcess::getInstance()
						->getProcess()
						->execute('stopWorkerById', [$row['source_name']]);
				}
			}

			if ($ids !== []) {
				$stringIds = implode(',', $ids);
				$sql = /** @lang manticore */
					"UPDATE $viewsTable SET $option = $value WHERE id in ($stringIds)";
				$rawResult = $manticoreClient->sendRequest($sql);
				if ($rawResult->hasError()) {
					throw ManticoreSearchClientError::create($rawResult->getError());
				}
			}

			return TaskResult::withTotal(sizeof($ids));
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}
}
