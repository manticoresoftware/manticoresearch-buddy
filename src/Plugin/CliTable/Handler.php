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
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the class to return response to the '/cli' endpoint in table format
 */
final class Handler extends BaseHandlerWithClient {

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
		): TaskResult {
			$resp = $manticoreClient->sendRequest(
				$payload->query,
				$payload->path,
				disableAgentHeader: true
			);
			return TaskResult::fromResponse($resp);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @param array<mixed> $resultInfo
	 * @param array<int,array<mixed>> $data
	 * @param int $total
	 * @return void
	 */
	protected static function processResultInfo(array $resultInfo, ?array &$data = [], int &$total = -1): void {
		if (isset($resultInfo['data']) && is_array($resultInfo['data'])) {
			$data = $resultInfo['data'];
		}
		if (!isset($resultInfo['total'])) {
			return;
		}
		$total = $resultInfo['total'];
	}

}
