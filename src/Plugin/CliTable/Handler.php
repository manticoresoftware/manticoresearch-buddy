<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\CliTable;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithTableFormatter;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the class to return response to the '/cli' endpoint in table format
 */
final class Handler extends BaseHandlerWithTableFormatter {

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
		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$taskFn = static function (
			Payload $payload,
			Client $manticoreClient,
			?TableFormatter $tableFormatter
		): TaskResult {
			$time0 = hrtime(true);
			$resp = $manticoreClient->sendRequest(
				$payload->query,
				$payload->path,
				disableAgentHeader: true
			);
			$data = null;
			$total = -1;
			$respBody = $resp->getBody();
			$result = (array)json_decode($respBody, true);
			if ($tableFormatter === null
			 || (!isset($result['error']) && (!isset($result[0]) || !is_array($result[0])))
			) {
				return TaskResult::raw($result);
			}
			// Convert JSON response from Manticore to table format
			if (isset($result['error'])) {
				/** @var string $errorMsg */
				$errorMsg = $result['error'];
				if ($errorMsg !== '') {
					return TaskResult::raw($tableFormatter->getTable($time0, $data, $total, $errorMsg));
				}
			}
			if (isset($result[0]['data']) && is_array($result[0]['data'])) {
				$data = $result[0]['data'];
			}
			if (isset($result[0]['total'])) {
				$total = $result[0]['total'];
			}
			return TaskResult::raw($tableFormatter->getTable($time0, $data, $total));
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient, $this->tableFormatter]
		)->run();
	}
}
