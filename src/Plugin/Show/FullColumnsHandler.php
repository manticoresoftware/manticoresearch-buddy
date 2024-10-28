<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Show;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is the parent class to handle erroneous Manticore queries
 */
class FullColumnsHandler extends BaseHandlerWithClient {
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
		//      Field: updated_at
		//       Type: int unsigned
		//  Collation: NULL
		//       Null: NO
		//        Key:
		//    Default: NULL
		//      Extra:
		// Privileges: select,insert,update,references
		//    Comment:

		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {
			$query = "DESC {$payload->table}";
			/** @var array{0:array{data:array<mixed>}} */
			$result = $manticoreClient->sendRequest($query)->getResult();
			$base = [
				'Field' => '',
				'Type' => '',
				'Collation' => '',
				'Null' => 'NO',
				'Key' => '',
				'Default' => null,
				'Extra' => '',
				'Privileges' => '',
				'Comment' => '',
			];
			$data = [];
			/** @var array{Field:string,Type:string} $row */
			foreach ($result[0]['data'] as $row) {
				$data[] = array_replace(
					$base, [
					'Field' => $row['Field'],
					'Type' => $row['Type'],
					]
				);
			}

			return TaskResult::withData($data)
				->column('Field', Column::String)
				->column('Type', Column::String)
				->column('Collation', Column::String)
				->column('Null', Column::String)
				->column('Key', Column::String)
				->column('Default', Column::String)
				->column('Extra', Column::String)
				->column('Privileges', Column::String)
				->column('Comment', Column::String);
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}
}
