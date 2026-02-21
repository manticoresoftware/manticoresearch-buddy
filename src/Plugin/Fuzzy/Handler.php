<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Fuzzy;

use Closure;
use Exception;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
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
			$fn = $this->getHandlerFn();
			$request = $this->payload->getQueriesRequest($fn);
			$response = $this->manticoreClient->sendRequest($request, $this->payload->path);

			return TaskResult::fromResponse($response);
		};

		return Task::create($taskFn)->run();
	}

	/**
	 * Get handler function that we use to process the query
	 * @return Closure
	 */
	protected function getHandlerFn(): Closure {
		// In case fuzzy set to false, we just return the original query
		if (!$this->payload->fuzzy) {
			return fn($query) => [[$query]];
		}
		// Otherwise we process the query
		return function (string $query): array {
			return $this->processFuzzyQuery($query);
		};
	}

	/**
	 * @param string $query
	 * @return array<array<string>>
	 * @throws QueryValidationError
	 * @throws Exception
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	protected function processFuzzyQuery(string $query): array {
		$this->validate();
		$phrases = [$query];
		// If we have layout correction we generate for all languages given phrases
		if ($this->payload->layouts) {
			$phrases = KeyboardLayout::combineMany($query, $this->payload->layouts);
		}
		$words = [];
		$scoreMap = [];
		foreach ($phrases as $phrase) {
			foreach ($this->payload->tables as $table) {
				[$variations, $variationScores] = $this->manticoreClient->fetchFuzzyVariations(
					$phrase,
					$table,
					$this->payload->forceBigrams,
					$this->payload->distance
				);
				Buddy::debug("Fuzzy: variations for '$phrase' in '$table': " . json_encode($variations));
				$this->mergeVariations($variations, $variationScores, $words, $scoreMap);
			}
		}

		// If no words found, we just add the original phrase as fallback
		if (!$words) {
			$words = [[$query]];
		}

		/** @var array<array<string>> */
		return $words;
	}

	/**
	 * Perform validation, method should be run inside coroutine
	 * @return void
	 * @throws QueryValidationError
	 */
	protected function validate(): void {
		$validator = new TableValidator($this->manticoreClient, $this->flagCache, 30);
		foreach ($this->payload->tables as $table) {
			if ($validator->hasMinInfixLen($table)) {
				continue;
			}

			QueryValidationError::throw('Fuzzy search requires min_infix_len to be set');
		}
	}

	/**
	 * @param array<int,array{keywords:array<string>,original:string}> $variations
	 * @param array<string,float> $variationScores
	 * @param array<int,array<string>> $words
	 * @param array<string,int> $scoreMap
	 */
	private function mergeVariations(array $variations, array $variationScores, array &$words, array &$scoreMap): void {
		foreach ($variations as $pos => $variation) {
			$keywords = $variation['keywords'];
			if (!$keywords) {
				if (!$this->payload->preserve) {
					continue;
				}
				$keywords = [$variation['original']];
			}

			$words[$pos] ??= [];
			$words[$pos] = array_values(array_unique(Arrays::blend($words[$pos], $keywords)));
			$scoreMap = Arrays::getMapSum($scoreMap, $variationScores);
		}
	}


}
