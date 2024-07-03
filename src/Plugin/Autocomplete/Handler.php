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
use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
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

			$suggestions = [];
			foreach ($phrases as $phrase) {
				$combinations = $this->processPhrase($phrase);
				$suggestions = array_merge($suggestions, $combinations);
			}
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
	 * Process the given phrase and return the list of suggestions
	 * @param string $phrase
	 * @return array<string>
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	public function processPhrase(string $phrase): array {
		$words = $this->manticoreClient->fetchFuzzyVariations(
			$phrase,
			$this->payload->table,
			$this->payload->distance
		);

		// Expand last word with wildcard
		$lastIndex = array_key_last($words);
		$lastWords = $words[$lastIndex];
		$words[$lastIndex] = [];
		foreach ($lastWords as $lastWord) {
			// 1. Try to end the latest word with a wildcard
			$q = "CALL KEYWORDS('{$this->payload->table}', '{$lastWord}*')";
			/** @var array{0:array{data?:array<array{normalized:string,tokenized:string}>}} $result */
			$result = $this->manticoreClient->sendRequest($q)->getResult();
			foreach ($result[0]['data'] ?? [] as $row) {
				$words[$lastIndex][] = $row['normalized'];
			}

			// 2. Use suggestions for word extension in case of typos
			$optionsString = "{$this->payload->maxEdits} as max_edits";
			$q = "CALL SUGGEST('{$lastWord}*', '{$this->payload->table}', {$optionsString})";
			/** @var array{0:array{data?:array<array{suggest:string}>}} $result */
			$result = $this->manticoreClient->sendRequest($q)->getResult();
			foreach ($result[0]['data'] ?? [] as $row) {
				$words[$lastIndex][] = $row['suggest'];
			}

			// 3. Make sure we have unique fill up
			$words[$lastIndex] = array_unique($words[$lastIndex]);
		}

		$combinations = Arrays::getPositionalCombinations($words);
		$combinations = array_map(fn($v) => implode(' ', $v), $combinations);

		return $combinations;
	}
}
