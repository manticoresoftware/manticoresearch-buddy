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
use Manticoresearch\Buddy\Enum\MntEndpoint;
use Manticoresearch\Buddy\Interface\BuddyLocatorClientInterface;
use Manticoresearch\Buddy\Interface\BuddyLocatorInterface;
use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Interface\ErrorQueryRequestInterface;
use Manticoresearch\Buddy\Interface\MntHTTPClientInterface;
// @codingStandardsIgnoreStart
use Manticoresearch\Buddy\Interface\MntResponseBuilderInterface;
use Manticoresearch\Buddy\Interface\StatementInterface;
// @codingStandardsIgnoreEnd
use Manticoresearch\Buddy\Network\Response;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class ErrorQueryExecutor implements CommandExecutorInterface, BuddyLocatorClientInterface {
	const CANCEL_EXECUTION_MSG = 'The query cannot be corrected by Buddy';
	const DEFAULT_ENDPOINT = MntEndpoint::Cli;
	const DEFAULT_SERVER_URL = '127.0.0.1:9308';

	/** @var BuddyLocatorInterface $locator */
	protected BuddyLocatorInterface $locator;

	/** @var MntHTTPClientInterface $mntClient */
	protected ?MntHTTPClientInterface $mntClient = null;

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
	 * @return MntHTTPClientInterface
	 */
	public function getMntClient(): MntHTTPClientInterface {
		return $this->mntClient ?? $this->setMntClient();
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
		$Task = Task::create(
			$taskId, function (ErrorQueryExecutor $that, array $statements, MntEndpoint $finalEndpoint): Response {
				if (empty($statements)) {
					return Response::fromError(new Exception($that::CANCEL_EXECUTION_MSG));
				}
				$mntClient = $that->getMntClient();
				while (!empty($statements)) {
					$stmt = array_shift($statements);
					// When processing the final statement we need to make sure the response to client
					// has the same format as the initial request, otherwise we just use 'cli' endpoint
					if (sizeof($statements) === 1) {
						$mntClient->setEndpoint($finalEndpoint);
					}
					$resp = $mntClient->sendRequest($stmt->getBody());
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
			}, [$this, $statements, $endpoint]
		);

		return $Task->run();
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
	 * @return MntHTTPClientInterface
	 * @throws RuntimeException
	 */
	public function setMntClient(string $url = null): MntHTTPClientInterface {
		$mntClient = $this->locator->getByInterface(MntHTTPClientInterface::class);
		if (!$mntClient instanceof MntHTTPClientInterface) {
			throw new RuntimeException('Http client is missing');
		}
		$this->mntClient = $mntClient;
		$this->locator->getByInterface(
			MntResponseBuilderInterface::class, [], $this->mntClient, 'setResponseBuilder'
		);
		$this->mntClient->setServerUrl($url ?? self::DEFAULT_SERVER_URL);
		$this->mntClient->setEndpoint(self::DEFAULT_ENDPOINT);
		return $this->mntClient;
	}

	/**
	 * Preprocessing the request to get all data required for the task to start
	 *
	 * @return array{0:array<StatementInterface>,1:MntEndpoint}
	 */
	protected function prepareRequest(): array {
		$this->request->setLocator($this->locator);
		$this->request->generateCorrectionStatements();
		return [$this->request->getCorrectionStatements(),  $this->request->getEndpoint()];
	}
}
