<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Insert;

use Exception;
use Manticoresearch\Buddy\Base\Plugin\Insert\Error\AutoSchemaDisabledError;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Network\Struct;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class Handler extends BaseHandlerWithClient {
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
		// Check that we run it in rt mode because it will not work in plain
		$settings = $this->payload->getSettings();
		if (!$settings->isRtMode()) {
			throw GenericError::create(
				'Cannot create the table automatically in Plain mode.'
				. ' Make sure the table exists before inserting into it'
			);
		}

		if (!$settings->searchdAutoSchema) {
			throw AutoSchemaDisabledError::create(
				'Auto schema is disabled',
				true
			);
		}

		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {
			for ($i = 0, $maxI = sizeof($payload->queries) - 1; $i <= $maxI; $i++) {
				$query = $payload->queries[$i];
				$resp = $manticoreClient->sendRequest($query, $i === 0 ? null : $payload->path);
			}

			if (!isset($resp)) {
				throw new Exception('Empty queries to process');
			}

			$struct = Struct::fromJson(
				$resp->getBody()
			);
			return TaskResult::raw($struct);
		};
		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}
}
