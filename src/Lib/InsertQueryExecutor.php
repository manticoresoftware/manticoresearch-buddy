<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Exception;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;

use Manticoresearch\Buddy\Lib\ManticoreHTTPClient;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class InsertQueryExecutor implements CommandExecutorInterface {
	/** @var ManticoreHTTPClient */
	protected ManticoreHTTPClient $manticoreClient;

	/**
	 *  Initialize the executor
	 *
	 * @param InsertQueryRequest $request
	 * @return void
	 */
	public function __construct(public InsertQueryRequest $request) {
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
		$taskFn = function (InsertQueryRequest $request, ManticoreHTTPClient $manticoreClient): array {
			for ($i = 0, $max_i = sizeof($request->queries) - 1; $i <= $max_i; $i++) {
				$query = $request->queries[$i];
				// When processing the final query we need to make sure the response to client
				// has the same format as the initial request, otherwise we just use 'cli' default endpoint
				if ($i === $max_i) {
					$manticoreClient->setEndpoint($request->endpoint);
				}

				$resp = $manticoreClient->sendRequest($query);
			}

			if (!isset($resp)) {
				throw new Exception('Empty queries to process');
			}

			return (array)json_decode($resp->getBody(), true);
		};
		return Task::create(
			$taskFn, [$this->request, $this->manticoreClient]
		)->run();
	}

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return ['manticoreClient'];
	}

	/**
	 * Instantiating the http client to execute requests to Manticore server
	 *
	 * @param ManticoreHTTPClient $client
	 * $return ManticoreHTTPClient
	 */
	public function setManticoreClient(ManticoreHTTPClient $client): ManticoreHTTPClient {
		$this->manticoreClient = $client;
		return $this->manticoreClient;
	}
}
