<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\CliTable;

use Manticoresearch\Buddy\Base\FormattableClientQueryExecutor;
use Manticoresearch\Buddy\Lib\TableFormatter;
use Manticoresearch\Buddy\Lib\Task;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use RuntimeException;
use parallel\Runtime;

/**
 * This is the class to return response to the '/cli' endpoint in table format
 */
class Executor extends FormattableClientQueryExecutor {

	/**
	 *  Initialize the executor
	 *
	 * @param Request $request
	 * @return void
	 */
	public function __construct(public Request $request) {
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(Runtime $runtime): Task {
		$this->manticoreClient->setEndpoint($this->request->endpoint);
		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$taskFn = static function (
			Request $request,
			HTTPClient $manticoreClient,
			?TableFormatter $tableFormatter
		): mixed {
			$time0 = hrtime(true);
			$resp = $manticoreClient->sendRequest($request->query, null, true);
			$data = $total = null;
			$respBody = $resp->getBody();
			$result = (array)json_decode($respBody, true);
			if ($tableFormatter === null || !isset($result[0]) || !is_array($result[0])) {
				return $result;
			}
			// Convert JSON response from Manticore to table format
			if (isset($result[0]['error']) && $result[0]['error'] !== '') {
				return $tableFormatter->getTable($time0, $data, $total, $result[0]['error']);
			}
			if (isset($result[0]['data']) && is_array($result[0]['data'])) {
				$data = $result[0]['data'];
			}
			if (isset($result[0]['total'])) {
				$total = $result[0]['total'];
			}
			return $tableFormatter->getTable($time0, $data, $total);
		};

		return Task::createInRuntime(
			$runtime,
			$taskFn,
			[$this->request, $this->manticoreClient, $this->tableFormatter]
		)->run();
	}
}
