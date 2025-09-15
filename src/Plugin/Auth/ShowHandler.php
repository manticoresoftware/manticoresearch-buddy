<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Auth;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class ShowHandler extends BaseHandlerWithClient {
	/**
	* Initialize the executor
	*
	* @param Payload $payload
	*
	* @return void
	*/
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request
	 *
	 * @return Task
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, Client $client): TaskResult {
			$request = $client->sendRequest('SHOW PERMISSIONS');
			if ($request->hasError()) {
				throw GenericError::create((string)$request->getError());
			}

			$document = $request->getResult()->toArray();

			if (!is_array($document) || !isset($document[0]['data'])) {
				throw GenericError::create('Searchd failed with an empty response.');
			}

			$myPermissions = [];
			$allPermissions = $document[0]['data'];

			if (!is_array($allPermissions)) {
				throw GenericError::create('Invalid permissions data format.');
			}

			foreach ($allPermissions as $row) {
				if (!is_array($row) || !isset($row['Username'])) {
					continue;
				}

				if ($row['Username'] !== $payload->actingUser) {
					continue;
				}

				$myPermissions[] = $row;
			}

			return TaskResult::withData($myPermissions)
				->column('Username', Column::String)
				->column('action', Column::String)
				->column('Target', Column::String)
				->column('Allow', Column::String)
				->column('Budget', Column::String);
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}
}
