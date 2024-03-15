<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\SourceHandlers;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class MultipleSourcesHandler extends BaseHandlerWithClient {

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

			if (!$manticoreClient->hasTable(SourceHandler::SOURCE_TABLE_NAME)) {
				return TaskResult::none();
			}

			$sql = /** @lang manticore */
				"SELECT name FROM ".SourceHandler::SOURCE_TABLE_NAME." GROUP BY name";
      $result = $manticoreClient->sendRequest($sql);
			if ($result->hasError()){
				throw ManticoreSearchClientError::create($result->getError());
			}

			return TaskResult::raw($result->getResult());
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}
}
