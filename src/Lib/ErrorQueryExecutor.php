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
use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Interface\ErrorQueryRequestInterface;
use Manticoresearch\Buddy\Interface\ManticoreHTTPClientInterface;

use Manticoresearch\Buddy\Interface\StatementInterface;

use Manticoresearch\Buddy\Network\Response;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class ErrorQueryExecutor implements CommandExecutorInterface {
	const CANCEL_EXECUTION_MSG = 'The query cannot be corrected by Buddy';
	const DEFAULT_ENDPOINT = ManticoreEndpoint::Cli;
	const DEFAULT_SERVER_URL = '127.0.0.1:9308';

	/** @var ManticoreHTTPClientInterface $manticoreClient */
	protected ?ManticoreHTTPClientInterface $manticoreClient = null;

	/**
	 *  Initialize the executor
	 *
	 * @param ?ErrorQueryRequest $request
	 * @return void
	 */
	public function __construct(public ?ErrorQueryRequestInterface $request = null) {
	}

	/**
	 * @return ?ErrorQueryRequest
	 */
	public function getRequest(): ?ErrorQueryRequest {
		return $this->request;
	}

	/**
	 * @return ?ManticoreHTTPClientInterface
	 */
	public function getManticoreClient(): ?ManticoreHTTPClientInterface {
		return $this->manticoreClient;
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		[$statements, $endpoint] = $this->prepareRequest();
		// For simplicity we use class name as id for now
		$taskId = static::class;

		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$taskFn = function (ErrorQueryExecutor $that, array $statements, ManticoreEndpoint $finalEndpoint): Response {

			if (empty($statements)) {
				return Response::fromError(new Exception($that::CANCEL_EXECUTION_MSG));
			}
			$manticoreClient = $that->getManticoreClient();
			if (!isset($manticoreClient)) {
				throw new RuntimeException('Manticore client has not been instantiated');
			}
			if (!isset($that->request)) {
				throw new RuntimeException('Error query request has not been instantiated');
			}

			while (!empty($statements)) {
				$stmt = array_shift($statements);
				// When processing the final statement we need to make sure the response to client
				// has the same format as the initial request, otherwise we just use 'cli' endpoint
				if (sizeof($statements) === 1) {
					$manticoreClient->setEndpoint($finalEndpoint);
				}
				$resp = $manticoreClient->sendRequest($stmt->getBody());
				if ($resp->hasError()) {
					return Response::fromStringAndError(
						$that->request->getOrigMsg(), new Exception((string)$resp->getError())
					);
				}
			}

			if ($stmt->hasPostprocessor()) {
				$processor = $stmt->getPostprocessor();
				if (isset($processor)) {
					$resp->postprocess($processor);
				}
			}

			return Response::fromString($resp->getBody());
		};
		return Task::create(
			$taskId, $taskFn, [$this, $statements, $endpoint]
		)->run();
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

	/**
	 * Preprocessing the request to get all data required for the task to start
	 *
	 * @return array{0:array<StatementInterface>,1:ManticoreEndpoint}
	 * @throws RuntimeException
	 */
	protected function prepareRequest(): array {
		if (!isset($this->request)) {
			throw new RuntimeException('Error query request has not been instantiated');
		}
		$this->request->generateCorrectionStatements();
		return [$this->request->getCorrectionStatements(),  $this->request->getEndpoint()];
	}
}
