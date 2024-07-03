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
use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;
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
			$fn = static function (string $query) use ($payload, $manticoreClient): array {
				$phrases = [$query];
				// If we have layout correction we generate for all languages given phrases
				if ($payload->layouts) {
					$phrases = KeyboardLayout::combineMany($query, $payload->layouts);
				}
				$words = [];
				foreach ($phrases as $phrase) {
					$variations = $manticoreClient->fetchFuzzyVariations(
						$phrase,
						$payload->table,
						$payload->distance
					);
					// Extend varitions for each iteration we have
					foreach ($variations as $pos => $variation) {
						$words[$pos] ??= [];
						$words[$pos] = array_merge($words[$pos], $variation);
					}
				}

				$combinations = Arrays::getPositionalCombinations($words);
				$combinations = array_map(fn($v) => implode(' ', $v), $combinations);
				return $combinations;
			};
			$request = $payload->getQueriesRequest($fn);
			$result = $manticoreClient->sendRequest($request, $payload->path)->getResult();
			return TaskResult::raw($result);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}
}
