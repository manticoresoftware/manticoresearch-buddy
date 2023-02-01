<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\InsertQuery;

use Exception;
use Manticoresearch\Buddy\Base\ClientQueryExecutor;
use Manticoresearch\Buddy\Exception\GenericError;
use Manticoresearch\Buddy\Lib\Task;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use RuntimeException;
use parallel\Runtime;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class Executor extends ClientQueryExecutor {
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
	 * @param Runtime $runtime
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(Runtime $runtime): Task {
		// Check that we run it in rt mode because it will not work in plain
		$settings = $this->request->getManticoreSettings();
		if (!$settings->isRtMode()) {
			throw GenericError::create(
				'Cannot create the table automatically in Plain mode.'
				. ' Make sure the table exists before inserting into it'
			);
		}

		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$taskFn = function (Request $request, HTTPClient $manticoreClient): array {
			for ($i = 0, $maxI = sizeof($request->queries) - 1; $i <= $maxI; $i++) {
				$query = $request->queries[$i];
				// When processing the final query we need to make sure the response to client
				// has the same format as the initial request, otherwise we just use 'sql' default endpoint
				if ($i === $maxI) {
					$manticoreClient->setEndpoint($request->endpoint);
				}

				$resp = $manticoreClient->sendRequest($query);
			}

			if (!isset($resp)) {
				throw new Exception('Empty queries to process');
			}

			return (array)json_decode($resp->getBody(), true);
		};
		return Task::createInRuntime(
			$runtime, $taskFn, [$this->request, $this->manticoreClient]
		)->run();
	}
}
