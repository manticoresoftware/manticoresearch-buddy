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
use Manticoresearch\Buddy\Core\Error\QueryValidationError;
use Manticoresearch\Buddy\Core\ManticoreSearch\TableValidator;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithFlagCache;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Arrays;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 *
 * @phpstan-import-type Variation from \Manticoresearch\Buddy\Core\ManticoreSearch\Client
 * @phpstan-type keyword array{normalized:string,tokenized:string,docs:int}
 * @phpstan-type suggestion array{suggest:string,docs:int}
 */
final class Handler extends BaseHandlerWithFlagCache {
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
			$this->validate();
			$layoutPhrases = [];
			// If we have layout correction we generate for all languages given phrases
			if ($this->payload->layouts) {
				$layoutPhrases = KeyboardLayout::combineMany($this->payload->query, $this->payload->layouts);
			}
			$phrases = array_unique([$this->payload->query, ...$layoutPhrases]);
			$queryLen = strlen($this->payload->query);
			$maxCount = match (true) {
				$queryLen < 2 => 50,
				$queryLen < 5 => 25,
				default => 10,
			};
			$suggestions = $this->getSuggestions($phrases, $maxCount);
			$this->sortSuggestions($suggestions, $phrases);

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
	 * Perform validation, method should be run inside coroutine
	 * @return void
	 * @throws QueryValidationError
	 */
	protected function validate(): void {
		$validator = new TableValidator($this->manticoreClient, $this->flagCache, 30);
		if ($validator->hasMinInfixLen($this->payload->table)) {
			return;
		}

		QueryValidationError::throw('Autocomplete requires min_infix_len to be set');
	}

	/**
	 * @param array<string> $phrases
	 * @param int $maxCount
	 * @return array<string>
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	public function getSuggestions(array $phrases, int $maxCount = 10): array {
		$combinationSets = [];
		$count = 0;
		foreach ($phrases as $phrase) {
			$suggestions = $this->processPhrase($phrase, $maxCount);
			$count += sizeof($suggestions);
			$combinationSets[] = $suggestions;
			// Do early return when enough suggestions found
			if ($count >= $maxCount) {
				break;
			}
		}

		// Combine it in relevant order
		// We need to use unique to avoid duplicates in case
		// we're dealing with multiple languages and it results in a single character change
		// so in the end, we wind up with multiple suggestions for the same phrase
		/** @var array<string> $suggestions */
		$suggestions = array_unique(Arrays::blend(...$combinationSets));
		return $suggestions;
	}

	/**
	 * Sort the suggestions by the prefix and suffix depending on request parameters
	 * @param array<string> &$suggestions
	 * @param array<string> $phrases
	 * @return void
	 */
	public function sortSuggestions(array &$suggestions, array $phrases): void {
		uasort(
			$suggestions, function (string $a, string $b) use ($phrases) {
				return $this->compareSuggestions($a, $b, $phrases);
			}
		);
	}

	/**
	 * @param string $a
	 * @param string $b
	 * @param array<string> $phrases
	 * @return int
	 */
	private function compareSuggestions(string $a, string $b, array $phrases): int {
		$query = $this->payload->query;
		$aPrefix = $this->isPrefix($a, $query);
		$bPrefix = $this->isPrefix($b, $query);
		$aSuffix = $this->isSuffix($a, $query);
		$bSuffix = $this->isSuffix($b, $query);
		$aPhrasePrefix = $this->startsWithAnyPhrase($a, $phrases);
		$bPhrasePrefix = $this->startsWithAnyPhrase($b, $phrases);

		// Check for phrase prefixing first
		if ($aPhrasePrefix !== $bPhrasePrefix) {
			return $aPhrasePrefix ? -1 : 1;
		}

		if ($aPrefix !== $bPrefix) {
			return $aPrefix ? -1 : 1;
		}

		if ($aSuffix !== $bSuffix) {
			return $aSuffix ? -1 : 1;
		}

		return 0;
	}

	/**
	 * @param string $str
	 * @param string $query
	 * @return bool
	 */
	private function isPrefix(string $str, string $query): bool {
		return $this->payload->append && str_starts_with($str, $query);
	}

	/**
	 * @param string $str
	 * @param string $query
	 * @return bool
	 */
	private function isSuffix(string $str, string $query): bool {
		return $this->payload->prepend && str_ends_with($str, $query);
	}

	/**
	 * @param string $str
	 * @param array<string> $phrases
	 * @return bool
	 */
	private function startsWithAnyPhrase(string $str, array $phrases): bool {
		foreach ($phrases as $phrase) {
			if (str_starts_with(strtolower($str), strtolower($phrase))) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Process the given phrase and return the list of suggestions
	 * @param string $phrase
	 * @param int $maxCount
	 * @return array<string>
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	public function processPhrase(string $phrase, int $maxCount = 10): array {
		$words = $scoreMap = [];
		// Do query only in case we have fuzzy activated
		$distance = $this->getLevenshteinDistance($phrase);
		if ($distance > 0) {
			[$words, $scoreMap] = $this->manticoreClient->fetchFuzzyVariations(
				$phrase,
				$this->payload->table,
				$this->payload->forceBigrams,
				$distance
			);
		}

		// If no words found, we just add the original phrase
		if (!$words) {
			$words = [['original' => $phrase, 'keywords' => $this->payload->preserve ? [$phrase] : []]];
		}

		Buddy::debug("Autocomplete: variations for '$phrase': " . json_encode($words));

		// Expand last word with wildcard
		$lastIndex = array_key_last($words);
		$lastWords = $words[$lastIndex]['keywords'];
		if (!$lastWords) {
			$lastWords = [$words[$lastIndex]['original']];
		}

		foreach ($lastWords as $lastWord) {
			// 1. Try to end the latest word with a wildcard
			$keywords = $this->expandKeywords($lastWord);

			// 2. Use suggestions for word extension in case of typos $row['su
			$suggestions = $this->expandSuggestions($lastWord);

			// 3. Merge it all
			$words[$lastIndex]['keywords'] = array_merge($words[$lastIndex]['keywords'], $keywords, $suggestions);

			// 4. Make sure we have unique fill up
			$words[$lastIndex]['keywords'] = array_unique($words[$lastIndex]['keywords']);
		}

		// If the original phrase in the list, we add it to the beginning to boost weight
		$combinations = static::buildRelevantCombinations($words, $scoreMap, $maxCount);
		$combinations = Arrays::boostListValues($combinations, [$phrase]);
		/** @var array<string> $combinations */
		return $combinations;
	}

	/**
	 * Most effecitve way to find MOST scored relevant combinations by score map and return it with maxCount
	 * @param array<Variation> $words
	 * @param array<string,float> $scoreMap
	 * @param int $maxCount
	 * @return array<string>
	 */
	private static function buildRelevantCombinations(array $words, array $scoreMap, int $maxCount): array {
		if (empty($words)) {
			return [];
		}

		$combinations = ['' => 0.0];
		$positions = array_keys($words);

		foreach ($positions as $position) {
			/** @var array<string> $keywords */
			$keywords = $words[$position]['keywords'];
			$combinations = static::processCombinations($combinations, $keywords, $scoreMap, $maxCount);
		}

		arsort($combinations);
		return array_slice(array_filter(array_keys($combinations)), 0, $maxCount);
	}

	/**
	 * @param array<string,float> $combinations
	 * @param array<string> $positionWords
	 * @param array<string,float> $scoreMap
	 * @param int $maxCount
	 * @return array<string,float>
	 */
	private static function processCombinations(
		array $combinations,
		array $positionWords,
		array $scoreMap,
		int $maxCount
	): array {
		$newCombinations = [];
		foreach ($combinations as $combination => $score) {
			foreach ($positionWords as $word) {
				$newCombination = trim($combination . ' ' . $word);
				$newScore = $score + ($scoreMap[$word] ?? 0);
				if (sizeof($newCombinations) >= $maxCount && $newScore <= min($newCombinations)) {
					continue;
				}

				$newCombinations[$newCombination] = $newScore;
				if (sizeof($newCombinations) <= $maxCount) {
					continue;
				}

				arsort($newCombinations);
				array_pop($newCombinations);
			}
		}
		return $newCombinations;
	}


	/**
	 * Get levenshtein distance for the given word on auto or not algorithm
	 * @param string $word
	 * @return int
	 */
	private function getLevenshteinDistance(string $word): int {
		if (!$this->payload->fuzziness) {
			return 0;
		}
		$wordLen = strlen($word);
		// If we set fuzziness, we choose minimal distance from out algo and parameter set
		// When force_bigrams is true, use distance=2 for wordLen > 3 instead of > 6
		$distanceThreshold = $this->payload->forceBigrams ? 3 : 6;
		return min(
			$this->payload->fuzziness, match (true) {
				$wordLen > $distanceThreshold => 2,
				$wordLen > 2 => 1,
				default => 0,
			}
		);
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
		$match = $this->getExpansionWordMatch($word);
		$optionString = "1 as stats, 'docs' as sort_mode, 100 as expansion_limit";
		$q = "CALL KEYWORDS('{$match}', '{$this->payload->table}', {$optionString})";
		/** @var array{0:array{data?:array<keyword>}} $result */
		$result = $this->manticoreClient->sendRequest($q)->getResult();
		$data = $result[0]['data'] ?? [];
		$totalFound = sizeof($data);
		Buddy::debug('Autocomplete: expanded keywords found: ' . $totalFound);
		/** @var array<keyword> */
		$data = static::applyThreshold($data, 0.5);
		/** @var array<string> */
		$keywords = array_map(fn($row) => ltrim($row['normalized'], '='), $data);
		// Filter out keywords that are too long to given config
		$maxLen = strlen($word) + $this->payload->expansionLen;
		$keywords = array_filter($keywords, fn ($keyword) => strlen($keyword) <= $maxLen);
		Buddy::debug("Autocomplete: expanded keywords for '$word*' [" . implode(', ', $keywords) . ']');
		return $keywords;
	}

	/**
	 * Expand the suggestions for the given word and filter it by out levenshtein distance
	 * @param string $lastWord
	 * @return array<string>
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	private function expandSuggestions(string $lastWord): array {
		$distance = $this->getLevenshteinDistance($lastWord);
		if (!$distance) {
			return [];
		}

		$lastWordLen = strlen($lastWord);
		$maxEdits = $this->payload->expansionLen;
		$optionsString = "{$maxEdits} as max_edits, 100 as limit, 1 as result_stats, 1 as non_char";
		$match = $this->getExpansionWordMatch($lastWord);
		$q = "CALL SUGGEST('{$match}', '{$this->payload->table}', {$optionsString})";
		/** @var array{0:array{data?:array<suggestion>}} $result */
		$result = $this->manticoreClient->sendRequest($q)->getResult();
		$data = $result[0]['data'] ?? [];
		$rawSuggestionsCount = sizeof($data);
		/** @var array<string> */
		$suggestions = array_column(static::applyThreshold($data, 0.5, 20), 'suggest');
		$thresholdSuggestionsCount = sizeof($suggestions);
		$filterFn = function (string $suggestion) use ($lastWord, $lastWordLen, $distance) {
			$suggestionLen = strlen($suggestion);
			$lastWordPos = strpos($suggestion, $lastWord);

			// First check case when we have exact match of not filled up word and it's in beginning or ending
			// with maximum allowed distance
			if ($lastWordPos !== false) {
				if ($this->payload->prepend && $lastWordPos <= $distance) {
					return true;
				}
				if ($this->payload->append && ($suggestionLen - $lastWordPos - $lastWordLen) <= $distance) {
					return true;
				}
			}

			// Validate levenstein now for prefix or suffix
			if ($this->payload->prepend) {
				$prefix = substr($suggestion, 0, $lastWordLen);
				if (levenshtein($lastWord, $prefix) <= $distance) {
					return true;
				}
			}

			if ($this->payload->append) {
				$suffix = substr($suggestion, -$lastWordLen);
				if (levenshtein($lastWord, $suffix) <= $distance) {
					return true;
				}
			}

			return false;
		};

		$suggestions = array_filter($suggestions, $filterFn);
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

		return $suggestions;
	}

	/**
	 * Execute prepend and append logic and expand the word match
	 * @param string $word
	 * @return string
	 */
	private function getExpansionWordMatch(string $word): string {
		$match = $word;
		if ($this->payload->append) {
			$match = "$match*";
		}
		if ($this->payload->prepend) {
			$match = "*$match";
		}
		return $match;
	}

	/**
	 * Filter and prioritize documents based on the number of associated docs
	 * @param array<keyword>|array<suggestion> $documents
	 * @param float $threshold
	 * @param int $topN
	 * @return array<keyword>|array<suggestion>
	 */
	private static function applyThreshold(array $documents, float $threshold = 0.5, int $topN = 10): array {
		// Do not apply when we have less than topN documents
		$totalRows = sizeof($documents);
		if ($totalRows < $topN) {
			Buddy::debug("Autocomplete: skipping threshold [$topN], total rows: $totalRows");
			// Filter out cases when tokenized form is the same as normalized (when * in beginning or end)
			return array_filter($documents, fn($row) => $row['docs'] > 0);
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
			"Autocomplete: apply threshold [$topN] " .
				"rows found: $totalRows, " .
				"average docs: $avgDocs, " .
				"total docs: $totalDocs, " .
				'size: ' . sizeof($documents)
		);

		// Filter documents
		$filteredDocs = array_filter(
			$documents, static function ($doc) use ($avgDocs, $threshold) {
				// Keep documents with docs count above average * threshold
				$minDocs = (int)round($avgDocs * $threshold);
				return $doc['docs'] > $minDocs;
			}
		);

		// Return top N documents
		return array_slice($filteredDocs, 0, $topN);
	}
}
