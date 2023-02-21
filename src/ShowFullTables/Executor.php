<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\ShowFullTables;

use Manticoresearch\Buddy\Base\FormattableClientQueryExecutor;
use Manticoresearch\Buddy\Lib\TableFormatter;
use Manticoresearch\Buddy\Lib\Task\Task;
use Manticoresearch\Buddy\Lib\Task\TaskResult;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use RuntimeException;
use parallel\Runtime;

/**
 * This is the parent class to handle erroneous Manticore queries
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
			TableFormatter $tableFormatter
		): TaskResult {
			$time0 = hrtime(true);
			// First, get response from the manticore
			$query = 'SHOW TABLES';
			if ($request->like) {
				$query .= " LIKE '{$request->like}'";
			}
			$resp = $manticoreClient->sendRequest($query);
			/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:int,columns?:string}> $result */
			$result = $resp->getResult();
			$total = $result[0]['total'] ?? -1;
			if ($request->hasCliEndpoint) {
				return new TaskResult($tableFormatter->getTable($time0, $result[0]['data'], $total));
			}
			return new TaskResult($result);
		};

		return Task::createInRuntime(
			$runtime, $taskFn, [$this->request, $this->manticoreClient, $this->tableFormatter]
		)->run();
	}
}
