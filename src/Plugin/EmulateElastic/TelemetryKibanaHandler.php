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
 * This is the parent class to handle erroneous Manticore queries
 */
class TelemetryKibanaHandler extends BaseHandler {

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
					'_primary_term' => 1,
					'_seq_no' => 0,
					'updated' => 1,
					'_id' => 'telemetry:telemetry',
					'_index' => '.kibana',
					'_source' => [
						'references' => [],
						'telemetry' => [
							'userHasSeenNotice' => true,
						],
						'type' => 'telemetry',
						'updated_at' => '2024-05-28T11:23:42.444Z',
					],
					'_type' => '_doc',
					'_version' => 1,
					'found' => true,
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
