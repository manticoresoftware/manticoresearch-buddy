<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Autocomplete;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Arrays;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 * @phpstan-type keyword array{normalized:string,tokenized:string,docs:int}
 * @phpstan-type suggestion array{suggest:string,docs:int}
 */
final class Handler extends BaseHandlerWithClient {
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
		$taskFn = function (): TaskResult {
			$phrases = [$this->payload->query];
			// If we have layout correction we generate for all languages given phrases
			if ($this->payload->layouts) {
				$phrases = KeyboardLayout::combineMany($this->payload->query, $this->payload->layouts);
			}

			$suggestions = $this->getSuggestions($phrases);
			// Preparing the final result with suggestions
			$data = [];
			foreach ($suggestions as $suggestion) {
				$data[] = ['query' => $suggestion];
			}
			return TaskResult::withData($data)->column('query', Column::String);
		};

		$task = Task::create($taskFn, []);
		return $task->run();
	}

	/**
	 * @param array<string> $phrases
	 * @return array<string>
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	public function getSuggestions(array $phrases): array {
		$combinationSets = [];
		$maxCount = 0;
		foreach ($phrases as $phrase) {
			$suggestions = $this->processPhrase($phrase);
			$maxCount = max($maxCount, sizeof($suggestions));
			$combinationSets[] = $suggestions;
		}

		// Combine it in relevant order
		/** @var array<string> $suggestions */
		$suggestions = Arrays::blend(...$combinationSets);
		return $suggestions;
	}

	/**
	 * Process the given phrase and return the list of suggestions
	 * @param string $phrase
	 * @return array<string>
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	public function processPhrase(string $phrase): array {
		[$words, $scoreMap] = $this->manticoreClient->fetchFuzzyVariations(
			$phrase,
			$this->payload->table,
			$this->payload->distance
		);

		if (!$words) {
			$words = [[$phrase]];
		}
		Buddy::debug("Autocomplete: variations for '$phrase': " . json_encode($words));

		// Expand last word with wildcard
		$lastIndex = array_key_last($words);
		$lastWords = $words[$lastIndex];
		$words[$lastIndex] = [];
		foreach ($lastWords as $lastWord) {
			// 1. Try to end the latest word with a wildcard
			$words[$lastIndex] = array_merge($words[$lastIndex], $this->expandKeywords($lastWord));
			// 2. Use suggestions for word extension in case of typos $row['su
			$lastWordLen = strlen($lastWord);
			$maxEdits = $this->payload->prefixLengthToEditsMap[$lastWordLen] ?? 0;
			if ($maxEdits > 0) {
				$optionsString = "{$maxEdits} as max_edits, 100 as limit, 1 as result_stats";
				$q = "CALL SUGGEST('{$lastWord}*', '{$this->payload->table}', {$optionsString})";
				/** @var array{0:array{data?:array<suggestion>}} $result */
				$result = $this->manticoreClient->sendRequest($q)->getResult();
				$data = $result[0]['data'] ?? [];
				$rawSuggestionsCount = sizeof($data);
				/** @var array<string> */
				$suggestions = array_column(static::applyThreshold($data, 0.5, 20), 'suggest');
				$thresholdSuggestionsCount = sizeof($suggestions);
				$suggestions = array_filter(
					$suggestions, function ($suggestion) use ($lastWord, $lastWordLen) {
					// Check the prefix on Levenshtein distance and filter out unsuited autocompletes
						$prefix = substr($suggestion, 0, $lastWordLen);
						if (levenshtein($lastWord, $prefix) > $this->payload->prefixDistance) {
							return false;
						}
						return true;
					}
				);
				$finalSuggestionsCount = sizeof($suggestions);
				Buddy::debug(
					'Autocomplete: filtering counters: ' .
						"raw: $rawSuggestionsCount, " .
						"threshold: $thresholdSuggestionsCount, " .
						"final: $finalSuggestionsCount"
				);
				Buddy::debug(
					"Autocomplete: filtered suggestions for '$lastWord*' [" .
						implode(', ', $suggestions) .
						']'
				);
				$words[$lastIndex] = array_merge($words[$lastIndex], $suggestions);
			}

			// 3. Make sure we have unique fill up
			$words[$lastIndex] = array_unique($words[$lastIndex]);
		}
		$combinations = Arrays::getPositionalCombinations($words, $scoreMap);
		/** @var array<string> $combinations */
		$combinations = array_map(fn($v) => implode(' ', $v), $combinations);
		// If the original phrase in the list, we add it to the beginning to boost weight
		$combinations = Arrays::boostListValues($combinations, [$phrase]);
		/** @var array<string> $combinations */
		return $combinations;
	}

	/**
	 * Expand the keyword with wildcards
	 * @param string $word
	 * @return array<string>
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	private function expandKeywords(string $word): array {
		$keywords = [];
		$q = "CALL KEYWORDS('{$word}*', '{$this->payload->table}', 1 as stats, 'docs' as sort_mode)";
		/** @var array{0:array{data?:array<keyword>}} $result */
		$result = $this->manticoreClient->sendRequest($q)->getResult();
		$data = $result[0]['data'] ?? [];
		// Case when nothing found in that way we have word* form
		if (sizeof($data) === 1 && $data[0]['tokenized'] === $data[0]['normalized']) {
			return [];
		}
		/** @var array<keyword> */
		$data = static::applyThreshold($data);
		/** @var array<string> */
		$keywords = array_map(
			fn($row) => $row['normalized'][0] === '='
			? substr($row['normalized'], 1)
			: $row['normalized'],
			$data
		);

		Buddy::debug("Autocomplete: expanded keywords for '$word*' [" . implode(', ', $keywords) . ']');
		return $keywords;
	}

	/**
	 * Filter and prioritize documents based on the number of associated docs
	 * @param array<keyword>|array<suggestion> $documents
	 * @param float $threshold
	 * @param int $topN
	 * @return array<keyword>|array<suggestion>
	 */
	private static function applyThreshold(array $documents, float $threshold = 0.5, int $topN = 10): array {
		if (!$documents) {
			return [];
		}

		// Sort documents by number of associated docs in descending order
		usort(
			$documents, static function ($a, $b) {
				return $b['docs'] - $a['docs'];
			}
		);

		// Calculate the average number of docs
		$totalDocs = array_sum(array_column($documents, 'docs'));
		$avgDocs = $totalDocs / sizeof($documents);
		Buddy::debug(
			'Autocomplete: apply threshold ' .
				"average docs: $avgDocs, " .
				"total docs: $totalDocs, " .
				'size: ' . sizeof($documents)
		);

		// Filter documents
		$filteredDocs = array_filter(
			$documents, static function ($doc) use ($avgDocs, $threshold) {
			// Keep documents with docs count above average * threshold
				return $doc['docs'] >= ($avgDocs * $threshold);
			}
		);

		// Return top N documents
		return array_slice($filteredDocs, 0, $topN);
	}
}
