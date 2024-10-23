<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic;

use Exception;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class InvalidSourceKibanaHandler extends BaseHandlerWithClient {

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
		$taskFn = static function (Payload $payload, HTTPClient $manticoreClient): TaskResult {
			$request = json_decode($payload->body, true);
			if (!is_array($request)) {
				throw new Exception("Invalid request passed: {$payload->body}");
			}
			$request['_source'] = [];
			$request['table'] = $payload->table;
			if (isset($request['script_fields'])) {
				unset($request['script_fields']);
			}
			$isGetSingleDocQuery = isset($request['query']) && is_array($request['query'])
				&& isset($request['query']['ids']) && is_array($request['query']['ids'])
				&& isset($request['query']['ids']['values']) && is_array($request['query']['ids']['values']);
			if ($isGetSingleDocQuery) {
				$ids = array_map(fn($id) => (int)$id, $request['query']['ids']['values']);
				$request['query']['in'] = [
					'id' => $ids,
				];
			}
			$query = json_encode($request);
			/** @var array{error?:string} $queryResult */
			$queryResult = $manticoreClient->sendRequest((string)$query, 'search')->getResult();

			return TaskResult::raw($queryResult);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}
}
