<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\SourceHandlers;

use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

abstract class SourceHandler extends BaseHandlerWithClient {
	const SOURCE_TABLE_NAME = '_sources';
	const SOURCE_TYPE_KAFKA = 'kafka';

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
	 * @return Task
	 */
	public function run(): Task {

		/**
		 * @param Payload $payload
		 * @param Client $manticoreClient
		 * @return TaskResult
		 * @throws ManticoreSearchClientError
		 */
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {

			self::checkAndCreateSource($manticoreClient);
			return static::handle($payload, $manticoreClient);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	protected static function checkAndCreateSource(Client $manticoreClient): void {
		if ($manticoreClient->hasTable(self::SOURCE_TABLE_NAME)) {
			return;
		}

		$sql = /** @lang ManticoreSearch */
			'CREATE TABLE ' . self::SOURCE_TABLE_NAME .
			' (id bigint, type text, name text, buffer_table text, offset bigint, attrs json)';

		$request = $manticoreClient->sendRequest($sql);
		if ($request->hasError()) {
			throw ManticoreSearchClientError::create($request->getError());
		}
	}

	abstract public static function handle(Payload $payload, Client $manticoreClient): TaskResult;
}
