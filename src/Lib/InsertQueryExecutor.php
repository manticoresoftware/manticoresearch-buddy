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
use Manticoresearch\Buddy\Interface\ManticoreHTTPClientInterface;

use Manticoresearch\Buddy\Network\Response;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class InsertQueryExecutor implements CommandExecutorInterface {
	/** @var ManticoreHTTPClientInterface */
	protected ManticoreHTTPClientInterface $manticoreClient;

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
		$taskFn = function (InsertQueryRequest $request, ManticoreHTTPClientInterface $manticoreClient): Response {
			for ($i = 0, $max_i = sizeof($request->queries) - 1; $i <= $max_i; $i++) {
				$query = $request->queries[$i];
				$resp = $manticoreClient->sendRequest($query);
				if ($resp->hasError()) {
					return Response::fromError(new Exception((string)$resp->getError()));
				}
			}

			if (!isset($resp)) {
				return Response::fromError(new Exception('Empty queries to process'));
			}

			return Response::fromString($resp->getBody());
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
	 * @param ManticoreHTTPClientInterface $client
	 * $return ManticoreHTTPClientInterface
	 */
	public function setManticoreClient(ManticoreHTTPClientInterface $client): ManticoreHTTPClientInterface {
		$this->manticoreClient = $client;
		return $this->manticoreClient;
	}
}
