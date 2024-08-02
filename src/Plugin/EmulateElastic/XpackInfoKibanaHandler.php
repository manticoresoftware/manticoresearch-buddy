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
class XpackInfoKibanaHandler extends BaseHandler {

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
					'build' => [
						'date' => '2020-02-06T00:09:00.449973Z',
						'hash' => '7f634e9f44834fbc12724506cc1da681b0c3b1e3',
					],
					'features' => [
						'analytics' => [
							'available ' => true,
							'enabled' => true,
						],
						'ccr' => [
							'available' => false,
							'enabled' => true,
						],
						'enrich' => [
							'available' => true,
							'enabled' => true,
						],
						'flattened' => [
							'available' => true,
							'enabled' => true,
						],
						'frozen_indices' => [
							'available' => true,
							'enabled' => true,
						],
						'graph' => [
							'available' => false,
							'enabled' => true,
						],
						'ilm' => [
							'available' => true,
							'enabled' => true,
						],
						'logstash' => [
							'available' => false,
							'enabled' => true,
						],
						'ml' => [
							'available' => false,
							'enabled' => false,
							'native_code_info' => [
								'build_hash' => 'N/A',
								'version' => 'N/A',
							],
						],
						'monitoring' => [
							'available' => true,
							'enabled' => true,
						],
						'rollup' => [
							'available' => true,
							'enabled' => true,
						],
						'security' => [
							'available' => true,
							'enabled' => false,
						],
						'slm' => [
							'available' => true,
							'enabled' => true,
						],
						'spatial' => [
							'available' => true,
							'enabled' => true,
						],
						'sql' => [
							'available' => true,
							'enabled' => true,
						],
						'transform' => [
							'available' => true,
							'enabled' => true,
						],
						'vectors' => [
							'available' => true,
							'enabled' => true,
						],
						'voting_only' => [
							'available' => true,
							'enabled' => true,
						],
						'watcher' => [
							'available' => false,
							'enabled' => true,
						],
					],
					'license' => [
						'mode' => 'basic',
						'status' => 'active',
						'type' => 'basic',
						'uid' => 'f517b738-4fbd-4820-aca3-0710c1017f1a',
					],
					'tagline' => 'You know, for X',
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
