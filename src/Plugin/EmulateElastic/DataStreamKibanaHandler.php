<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Manticoresearch\Buddy\Core\Plugin\BaseHandler;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * Handles Data Stream requests in Opensearch requests
 */
class DataStreamKibanaHandler extends BaseHandler {

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
		$taskFn = static function (): TaskResult {
			return TaskResult::raw(
				[
					'data_streams' => [],
				]
			);
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return [];
	}

}
