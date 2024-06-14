<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Fuzzy;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Arrays;
use RuntimeException;

final class Handler extends BaseHandlerWithClient {


	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

  /**
	 * Process the request
	 *
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {
			$query = trim($payload->query, '"');
			$words = $manticoreClient->fetchFuzzyVariations(
				$query,
				$payload->table,
				$payload->distance
			);

			// Build a regexp that matches any of the words from vartions
			// If we work with phrase, we make phrase combinations
			if ($payload->query[0] === '"') {
				$combinations = Arrays::getPositionalCombinations($words);
				$combinations = array_map(fn($v) => implode(' ', $v), $combinations);
				$match = '"' . implode('"|"', $combinations) . '"';
			} else {
				$sentences = [];
				foreach ($words as $choices) {
					$sentences[] = '(' . implode('|', $choices) . ')';
				}
				$match = implode(' ', $sentences);
			}
			$query = sprintf($payload->template, $match);
			$result = $manticoreClient->sendRequest($query)->getResult();
			return TaskResult::raw($result);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}
}
