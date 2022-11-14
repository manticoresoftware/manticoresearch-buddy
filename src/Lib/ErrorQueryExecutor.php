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
use Manticoresearch\Buddy\Interface\BuddyLocatorClientInterface;
use Manticoresearch\Buddy\Interface\BuddyLocatorInterface;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Interface\ErrorQueryRequestInterface;
use Manticoresearch\Buddy\Interface\ManticoreHTTPClientInterface;
use Manticoresearch\Buddy\Interface\ManticoreResponseBuilderInterface;
use Manticoresearch\Buddy\Interface\StatementInterface;
use Manticoresearch\Buddy\Network\Response;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class ErrorQueryExecutor implements CommandExecutorInterface, BuddyLocatorClientInterface {
	const CANCEL_EXECUTION_MSG = 'The query cannot be corrected by Buddy';
	const DEFAULT_ENDPOINT = ManticoreEndpoint::Cli;
	const DEFAULT_SERVER_URL = '127.0.0.1:9308';

	/** @var BuddyLocatorInterface $locator */
	protected BuddyLocatorInterface $locator;

	/** @var ManticoreHTTPClientInterface $manticoreClient */
	protected ?ManticoreHTTPClientInterface $manticoreClient = null;

	/**
	 *  Initialize the executor
	 *
	 * @param ErrorQueryRequest $request
	 * @return void
	 */
	public function __construct(protected ErrorQueryRequestInterface $request) {
		$this->setLocator(new BuddyLocator());
	}

	/**
	 * @return ErrorQueryRequest
	 */
	public function getRequest(): ErrorQueryRequest {
		return $this->request;
	}

	/**
	 * @return ManticoreHTTPClientInterface
	 */
	public function getManticoreClient(): ManticoreHTTPClientInterface {
		return $this->manticoreClient ?? $this->setManticoreClient();
	}

	/**
	 * Process the request and return self for chaining
	 *
	 * @return Task
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
						$that->getRequest()->getOrigMsg(), new Exception((string)$resp->getError())
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
	 * @param BuddyLocatorInterface $locator
	 * @return void
	 */
	public function setLocator(BuddyLocatorInterface $locator): void {
		$this->locator = $locator;
	}

	/**
	 * Instantiating the http client to execute requests to Manticore server
	 *
	 * @param ?string $url
	 * @return ManticoreHTTPClientInterface
	 * @throws RuntimeException
	 */
	public function setManticoreClient(string $url = null): ManticoreHTTPClientInterface {
		$manticoreClient = $this->locator->getByInterface(ManticoreHTTPClientInterface::class);
		if (!$manticoreClient instanceof ManticoreHTTPClientInterface) {
			throw new RuntimeException('Http client is missing');
		}
		$this->manticoreClient = $manticoreClient;
		$this->locator->getByInterface(
			ManticoreResponseBuilderInterface::class, [], $this->manticoreClient, 'setResponseBuilder'
		);
		$this->manticoreClient->setServerUrl($url ?? self::DEFAULT_SERVER_URL);
		$this->manticoreClient->setEndpoint(self::DEFAULT_ENDPOINT);
		return $this->manticoreClient;
	}

	/**
	 * Preprocessing the request to get all data required for the task to start
	 *
	 * @return array{0:array<StatementInterface>,1:ManticoreEndpoint}
	 */
	protected function prepareRequest(): array {
		$this->request->setLocator($this->locator);
		$this->request->generateCorrectionStatements();
		return [$this->request->getCorrectionStatements(),  $this->request->getEndpoint()];
	}
}
