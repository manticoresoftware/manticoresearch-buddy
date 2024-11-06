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
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithTableFormatter;
use Manticoresearch\Buddy\Core\Plugin\TableFormatter;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * This is a class to handle SHOW TABLES-like queries where MySQL syntax is used
 */
class ExpandedTablesHandler extends BaseHandlerWithTableFormatter {
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
		// We run in a thread anyway but in case if we need blocking
		// We just waiting for a thread to be done
		$taskFn = static function (
			Payload $payload,
			Client $manticoreClient,
			TableFormatter $tableFormatter
		): TaskResult {
			$time0 = hrtime(true);
			// First, get response from the manticore
			$query = 'SHOW TABLES';
			if ($payload->like) {
				$query .= " LIKE '{$payload->like}'";
			}
			$resp = $manticoreClient->sendRequest($query, $payload->path);
			/** @var array<int,array{error:string,data:array<int,array<string,string>>,total?:int,columns?:string}> $result */
			$result = $resp->getResult();
			$total = $result[0]['total'] ?? -1;
			// Adjust result row to be mysql like
			if ($result[0]['data']) {
				$resultData = $result[0];
				foreach ($resultData['data'] as &$row) {
					$row = match ($payload->tableType) {
						'full' => [
							"Tables_in_{$payload->database}" => $row['Table'],
							'Table_type' => 'BASE TABLE', // Set Mysql like table type
						],
						'open' => [
							'Database' => 'Manticore',
							'Table' => $row['Table'],
							'In_use' => 0,
							'Name_locked' => 0,
						],
						default => throw new \Exception("Unknown table type {$payload->tableType} passed"),
					};
				}
				$result->offsetSet(0, $resultData);
			}
			
			if ($payload->hasCliEndpoint) {
				return TaskResult::raw($tableFormatter->getTable($time0, $result[0]['data'], $total));
			}

			return match ($payload->tableType) {
				'full' => TaskResult::withData($result[0]['data'])
					->column("Tables_in_{$payload->database}", Column::String)
					->column('Table_type', Column::String),
				'open' => TaskResult::withData($result[0]['data'])
					->column('Database', Column::String)
					->column('Table', Column::String)
					->column('In_Use', Column::Long)
					->column('Name_locked', Column::Long),
				default => throw new \Exception("Unknown table type {$payload->tableType} passed"),
			};
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient, $this->tableFormatter]
		)->run();
	}
}
