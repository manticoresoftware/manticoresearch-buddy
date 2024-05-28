<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

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
			// First call keywords and tokenize input
			$keywordsQuery = "CALL KEYWORDS('{$payload->query}', '{$payload->table}')";
			/** @var array<array{data:array<array{tokenized:string}>}> $result */
			$result = $manticoreClient->sendRequest($keywordsQuery)->getResult();
			$tokenized = array_column($result[0]['data'] ?? [], 'tokenized');
			$words = [];
			foreach ($tokenized as $word) {
				/** @var array<array{data:array<array{suggest:string,distance:int,docs:int}>}> $result */
				$result = $manticoreClient
					->sendRequest("CALL SUGGEST('$word', '{$payload->table}')")
					->getResult();
				$suggestions = $result[0]['data'] ?? [];
				/** @var array{suggest:string,distance:int,docs:int} $suggestion */
				foreach ($suggestions as $suggestion) {
					// If the distance is out of allowed, we use original word form
					if ($suggestion['distance'] > $payload->distance) {
						$words[] = $word;
					} else {
						$words[] = $suggestion['suggest'];
					}
				}
			}

			$query = sprintf($payload->template, implode(' ', $words));
			$result = $manticoreClient->sendRequest($query)->getResult();
			return TaskResult::raw($result);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}
}
