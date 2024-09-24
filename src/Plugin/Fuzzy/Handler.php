<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Fuzzy;

use Manticoresearch\Buddy\Core\Error\QueryValidationError;
use Manticoresearch\Buddy\Core\ManticoreSearch\TableValidator;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithFlagCache;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Arrays;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;
use RuntimeException;

final class Handler extends BaseHandlerWithFlagCache {

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
		$taskFn = function (): TaskResult {
			$fn = function (string $query): array {
				$this->validate();
				$phrases = [$query];
				// If we have layout correction we generate for all languages given phrases
				if ($this->payload->layouts) {
					$phrases = KeyboardLayout::combineMany($query, $this->payload->layouts);
				}
				$words = [];
				$scoreMap = [];
				foreach ($phrases as $phrase) {
					[$variations, $variationScores] = $this->manticoreClient->fetchFuzzyVariations(
						$phrase,
						$this->payload->table,
						$this->payload->distance
					);
					Buddy::debug("Fuzzy: variations for '$phrase': " . json_encode($variations));
					// Extend varitions for each iteration we have
					foreach ($variations as $pos => $variation) {
						$words[$pos] ??= [];
						$blend = Arrays::blend($words[$pos], $variation);
						$words[$pos] = array_values(array_unique($blend));
						$scoreMap = Arrays::getMapSum($scoreMap, $variationScores);
					}
				}

				// If no words found, we just add the original phrase as fallback
				if (!$words) {
					return ["$query"];
				}

				/** @var array<array<string>> $words */
				$combinations = Arrays::getPositionalCombinations($words, $scoreMap);
				$combinations = array_map(fn($v) => implode(' ', $v), $combinations);
				/** @var array<string> $combinations */
				// If the original phrase in the list, we add it to the beginning to boost weight
				$combinations = Arrays::boostListValues($combinations, $phrases);
				return $combinations;
			};
			$request = $this->payload->getQueriesRequest($fn);
			$result = $this->manticoreClient->sendRequest($request, $this->payload->path)->getResult();
			return TaskResult::raw($result);
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * Perform validation, method should be run inside coroutine
	 * @return void
	 * @throws QueryValidationError
	 */
	protected function validate(): void {
		$validator = new TableValidator($this->manticoreClient, $this->flagCache, 30);
		if ($validator->hasMinInfixLen($this->payload->table)) {
			return;
		}

		QueryValidationError::throw('Fuzzy search requires min_infix_len to be set');
	}

}
