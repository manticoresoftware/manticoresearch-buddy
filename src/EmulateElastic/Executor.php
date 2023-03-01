<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\EmulateElastic;

use Manticoresearch\Buddy\Interface\CommandExecutorInterface;
use Manticoresearch\Buddy\Lib\Task\Task;
use Manticoresearch\Buddy\Lib\Task\TaskResult;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use RuntimeException;
use parallel\Runtime;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class Executor implements CommandExecutorInterface {

	/** @var HTTPClient $manticoreClient */
	protected HTTPClient $manticoreClient;

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

		$taskFn = static function (): TaskResult {
			return new TaskResult(
				[
					'license' => [
						'status' => 'active',
						'uid' => 'no_license',
						'type' => 'basic',
						'issue_date' => '2023-01-01T00:00:000.000Z',
						'issue_date_in_millis' => 0,
						'max_nodes' => 1000,
						'issued_to' => 'docker-cluster',
						'issuer' => 'elasticsearch',
						'start_date_in_millis' => -1,
					],
				]
			);
		};

		return Task::createInRuntime($runtime, $taskFn)->run();
	}

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return [];
	}

}
