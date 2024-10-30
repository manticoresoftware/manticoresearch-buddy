<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Show;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class CreateTableHandler extends BaseHandlerWithClient {
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
			Client $manticoreClient
		): TaskResult {
			// First, get response from the manticore
			$query = "SHOW CREATE TABLE {$payload->table}";
			$resp = $manticoreClient->sendRequest($query);

			// It's important to have ` and 2 spaces for Apache Superset
			$resp->mapData(
				static function (array $row): array {
				/** @var array{'Create Table':string} $row */
					$lines = explode("\n", $row['Create Table']);
					$lastN = sizeof($lines) - 1;
					foreach ($lines as $n => &$line) {
						if ($n === 0 || $n === $lastN) {
							continue;
						}
						$parts = explode(' ', $line);
						$parts[0] = '`' . trim($parts[0], '`') . '`';
						$line = '  ' . trim(implode(' ', $parts));
					}
					$row['Create Table'] = implode("\n", $lines);
					return $row;
				}
			);

			return TaskResult::fromResponse($resp);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}
}
