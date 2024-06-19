<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Autocomplete;

use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
final class Handler extends BaseHandlerWithClient {
	// accept template values as follows: table: string,match: string, limit: integer
	/* const HIGHLIGHT_QUERY_TEMPLATE = 'SELECT HIGHLIGHT({' */
	/* 	. "chunk_separator='', before_match='', after_match=''," */
	/* 	. 'limit_passages=3, around=0, weight_order=1, passage_boundary=sentence,' */
	/* 	. "html_strip_mode=strip}) AS autocomplete FROM %s WHERE MATCH('%s') LIMIT %d;"; */
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
			$words = $this->manticoreClient->fetchFuzzyVariations(
				$this->payload->query,
				$this->payload->table,
				$this->payload->distance
			);
			$taskResult = TaskResult::withData([])->column('query', Column::String);
			foreach ($words as $choices) {
				$lastIndex = array_key_last($choices);
				$lastWord = $choices[$lastIndex];
				$q = "CALL SUGGEST('{$lastWord}*', '{$this->payload->table}', {$this->payload->maxEdits} as max_edits)";
				/** @var array{0:array{data?:array<array{suggest:string}>}} $result */
				$result = $this->manticoreClient->sendRequest($q)->getResult();
				foreach ($result[0]['data'] ?? [] as $row) {
					$choices[$lastIndex] = $row['suggest'];
					$sentence = implode(' ', $choices);
					$taskResult->row(['query' => $sentence]);
				}
			}
			return $taskResult;
			/* $sentence = '"' . implode('"|"', $sentences) . '" *'; */
			/* $q  = sprintf(self::HIGHLIGHT_QUERY_TEMPLATE, $this->payload->table, $sentence, 3); */
			/* $result = $this->manticoreClient->sendRequest($q)->getResult(); */
			/* return TaskResult::raw($result); */
		};

		$task = Task::create($taskFn, []);
		return $task->run();
	}

}
