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
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
final class Handler extends BaseHandlerWithClient {
	// accept template values as follows: table: string,match: string, limit: integer
	const HIGHLIGHT_QUERY_TEMPLATE = 'SELECT HIGHLIGHT({'
		. "chunk_separator='', before_match='', after_match='',"
		. 'limit_passages=3, around=0, weight_order=1, passage_boundary=sentence,'
		. "html_strip_mode=strip}) AS autocomplete FROM %s WHERE MATCH('%s') LIMIT %d;";
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
			$sentences = static::fuzzyVariations($this->payload->query, $this->payload->table, 3);
			$sentence = '"' . implode('"|"', $sentences) . '" *';
			$q  = sprintf(self::HIGHLIGHT_QUERY_TEMPLATE, $this->payload->table, $sentence, 3);
			$result = $this->manticoreClient->sendRequest($q)->getResult();
			return TaskResult::raw($result);
		};

		$task = Task::create($taskFn, []);
		return $task->run();
	}

	/**
	 * Helper to build combinations of words with typo and fuzzy correction to next combine in searches
	 * @param string $query The query to be tokenized
	 * @param string $table The table to be used in the suggest function
	 * @param int $limit The maximum number of suggestions for each tokenized word
	 * @return array<string> The list of combinations of words with typo and fuzzy correction
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 */
	public function fuzzyVariations(string $query, string $table, int $limit = 3): array {
		// 1. Tokenize the query first with the keywords function
		$q = "CALL KEYWORDS('{$query}', '{$table}')";
		/** @var array<array{data:array<array{tokenized:string}>}> $keywordsResult */
		$keywordsResult = $this->manticoreClient->sendRequest($q)->getResult();
		$tokenized = array_column($keywordsResult[0]['data'] ?? [], 'tokenized');
		$words = [];
		// 2. For each tokenized word, we get the suggestions from the suggest function
		foreach ($tokenized as $word) {
			/** @var array<array{data:array<array{suggest:string,distance:int,docs:int}>}> $suggestResult */
			$suggestResult = $this->manticoreClient
				->sendRequest(
					"CALL SUGGEST('{$word}', '{$table}', {$limit} as limit)"
				)
				->getResult();
			/** @var array{suggest:string,distance:int,docs:int} $suggestion */
			$suggestions = $suggestResult[0]['data'] ?? [];
			$choices = [];
			foreach ($suggestions as $suggestion) {
				// If the distance is out of allowed, we use original word form
				if ($suggestion['distance'] > $this->payload->distance) {
					$choices[] = $word;
					break;
				}

				$choices[] = $suggestion['suggest'];
			}
			$words[] = $choices;
		}
		// 3. We combine all the words with the same distance to get the final combinations
		$combinations = [[]]; // Initialize with an empty array to start the recursion
		foreach ($words as $choices) {
			$temp = [];
			foreach ($combinations as $combination) {
				foreach ($choices as $choice) {
					$temp[] = array_merge($combination, [$choice]);
				}
			}
			$combinations = $temp;
		}

		return array_map(fn($v) => implode(' ', $v), $combinations);
	}
}
