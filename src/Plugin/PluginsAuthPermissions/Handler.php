<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class Handler extends BaseHandlerWithClient {
	public function __construct(public Payload $payload) {
	}

	public function run(): Task {
		$taskFn = static function (Payload $payload, Client $client): TaskResult {
			foreach (self::permissionQueries($payload) as $query) {
				self::sendQuery($client, $query);
			}

			return TaskResult::none();
		};

		return Task::create($taskFn, [$this->payload, $this->manticoreClient])->run();
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	private static function sendQuery(Client $client, string $query): void {
		$response = $client->sendRequest($query);
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create((string)$response->getError());
		}
	}

	/**
	 * @return array<int, string>
	 */
	private static function permissionQueries(Payload $payload): array {
		return match ($payload->resource) {
			ResourceTable::RESOURCE_SOURCE,
			ResourceTable::RESOURCE_MATERIALIZED_VIEW => [$payload->morphedQuery],
			ResourceTable::RESOURCE_CHAT_MODEL => [$payload->morphedQuery, self::morphChatHistoryQuery($payload)],
			default => throw ManticoreSearchClientError::create(
				"Unsupported plugin resource '$payload->resource'"
			),
		};
	}

	private static function morphChatHistoryQuery(Payload $payload): string {
		$resourceTable = "'" . ResourceTable::name($payload->resource, $payload->resourceName) . "'";
		$historyTable = "'" . ResourceTable::name(ResourceTable::RESOURCE_CHAT_HISTORY, $payload->resourceName) . "'";

		$historyQuery = preg_replace(
			'/\bON\s+' . preg_quote($resourceTable, '/') . '(?=\s|;|\z)/i',
			'ON ' . $historyTable,
			$payload->morphedQuery,
			1
		);
		if (!is_string($historyQuery)) {
			throw ManticoreSearchClientError::create('Failed to morph chat history permission query');
		}

		return $historyQuery;
	}
}
