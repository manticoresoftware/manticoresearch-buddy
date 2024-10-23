<?php declare(strict_types=1);

/*
 Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
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
 * This is the parent class to handle queries not supported by Manticore
 * but, for example, sent by different MySQL tools and required some response
 */
class UnsupportedStmtHandler extends BaseHandlerWithClient {
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
			callable $queryHandler,
		): TaskResult {
			return $queryHandler($payload, $manticoreClient);
		};
		return Task::create(
			$taskFn,
			[
				$this->payload,
				$this->manticoreClient,
				[$this, 'handleQuery'],
			]
		)->run();
	}

	/**
	 * Helper function to return the empty result for an unsupported query
	 *
	 * @param Payload $payload
	 * @throws RuntimeException
	 */
	public static function checkForNoDataResponse(Payload $payload):TaskResult {
		if (preg_match('/^show (function|procedure)? status(\s.*|$)/is', $payload->query)) {
			return TaskResult::withData([[]])
				->column('Db', Column::String)
				->column('Name', Column::String)
				->column('Type', Column::String)
				->column('Definer', Column::String)
				->column('Modified', Column::String)
				->column('Created', Column::String)
				->column('Security_type', Column::String)
				->column('Invoker', Column::String)
				->column('Comment', Column::String)
				->column('character_set_client', Column::String)
				->column('collation_connection', Column::String)
				->column('Database Collation', Column::String);
		}
		if (stripos($payload->query, 'show triggers from') === 0) {
			return TaskResult::withData([[]])
				->column('Trigger', Column::String)
				->column('Event', Column::String)
				->column('Table', Column::String)
				->column('Statement', Column::String)
				->column('Timing', Column::String)
				->column('Created', Column::String)
				->column('sql_mode', Column::String)
				->column('Definer', Column::String)
				->column('character_set_client', Column::String)
				->column('collation_connection', Column::String)
				->column('Database Collation', Column::String);
		}
		if (stripos($payload->query, 'show events from') === 0) {
			return TaskResult::withData([[]])
				->column('Db', Column::String)
				->column('Name', Column::String)
				->column('Definer', Column::String)
				->column('Time zone', Column::String)
				->column('Type', Column::String)
				->column('Execute at', Column::String)
				->column('Interval value', Column::String)
				->column('Interval field', Column::String)
				->column('Starts', Column::String)
				->column('Ends', Column::String)
				->column('Status', Column::String)
				->column('Originator', Column::String)
				->column('character_set_client', Column::String)
				->column('collation_connection', Column::String)
				->column('Database Collation', Column::String);
		}
		if (stripos($payload->query, 'show session status') === 0) {
			return TaskResult::withData([[]])
				->column('Variable_name', Column::String)
				->column('Value', Column::String);
		}
		if (stripos($payload->query, 'show character set') === 0) {
			return TaskResult::withData([[]])
				->column('Charset', Column::String)
				->column('Description', Column::String)
				->column('Default collation', Column::String)
				->column('Maxlen', Column::Long);
		}
		if (stripos($payload->query, 'show full processlist') === 0) {
			return TaskResult::withData([[]])
				->column('Id', Column::Long)
				->column('User', Column::String)
				->column('Host', Column::String)
				->column('db', Column::String)
				->column('Command', Column::String)
				->column('Time', Column::Long)
				->column('State', Column::String)
				->column('Info', Column::String);
		}
		if (stripos($payload->query, 'show privileges') === 0) {
			return TaskResult::withData([[]])
				->column('Privilege', Column::String)
				->column('Context', Column::String)
				->column('Comment', Column::String);
		}
		if (stripos($payload->query, 'show global status') === 0) {
			return TaskResult::withData([[]])
				->column('Variable_name', Column::String)
				->column('Value', Column::String);
		}
		if ((stripos($payload->query, 'show index') === 0) || (stripos($payload->query, 'show keys') === 0)) {
			return TaskResult::withData([[]])
				->column('Table', Column::String)
				->column('Non_unique', Column::Long)
				->column('Key_name', Column::String)
				->column('Column_name', Column::String)
				->column('Collation', Column::String)
				->column('Null', Column::String);
		}
		throw new \RuntimeException('Cannot handle unsupported query');
	}

	/**
	 * Process the request query and return the result of task execution
	 *
	 * @param Payload $payload
	 * @param Client $manticoreClient
	 * @return TaskResult
	 */
	public static function handleQuery(
		Payload $payload,
		Client $manticoreClient
	): TaskResult {
		if (preg_match('/^show tables from (information_schema|`information_schema`)$/is', $payload->query)) {
			$data = [
				[
					'Tables_in_information_schema' => 'COLUMNS',
				],
				[
					'Tables_in_information_schema' => 'COLUMN_STATISTICS',
				],
				[
					'Tables_in_information_schema' => 'FILES',
				],
				[
					'Tables_in_information_schema' => 'TABLES',
				],
				[
					'Tables_in_information_schema' => 'TRIGGERS',
				],
			];
			return TaskResult::withData($data)
				->column('Tables_in_information_schema', Column::String);
		}
		if (preg_match('/^show( open)? tables from (Manticore|`Manticore`)(\s.*|$)/is', $payload->query)) {
			$query = 'SHOW TABLES';
			/** @var array{0:array{data:array<mixed>}} */
			$result = $manticoreClient->sendRequest($query)->getResult();
			$data = $result[0]['data'];
			return TaskResult::withData($data)
				->column('Table', Column::String)
				->column('Type', Column::String);
		}
		$matches = [];
		if (preg_match('/^show table status from( Manticore| `Manticore`)?(\s*.*|$)/is', $payload->query, $matches)) {
			$query = 'SHOW TABLES';
			/** @var array{0:array{data:array<int,array<mixed>>}} */
			$result = $manticoreClient->sendRequest($query)->getResult();
			$tableNames = array_map(
				fn($item) => $item['Table'] ?? '',
				$result[0]['data']
			);
			if (!$matches[1]) {
				// A specific table has been requested
				$reqTable = ltrim($matches[2]);
				$tableNames = in_array($reqTable, $tableNames) ? [$reqTable] : [];
			}
			$data = array_map(
				fn($t) => [
					'Name' => $t,
					'Engine' => 'MyISAM',
					'Version' => '10',
					'Row_format' => 'Dynamic',
					'Rows' => 0,
					'Avg_row_length' => 0,
					'Data_length' => 0,
					'Max_data_length' => 2533274790395903,
					'Index_length' => 0,
					'Data_free' => 0,
					'Auto_increment' => 1,
					'Create_time' => 'NULL',
					'Update_time' => 'NULL',
					'Check_time' => 'NULL',
					'Collation' => 'utf8mb4_0900_ai_ci',
					'Checksum' => 'NULL',
					'Create_options' => '',
					'Comment' => '',
				],
				$tableNames
			);

			return TaskResult::withData($data)
				->column('Name', Column::String)
				->column('Engine', Column::String)
				->column('Version', Column::Long)
				->column('Row_format', Column::String)
				->column('Rows', Column::Long)
				->column('Avg_row_length', Column::Long)
				->column('Data_length', Column::Long)
				->column('Max_data_length', Column::Long)
				->column('Index_length', Column::Long)
				->column('Data_free', Column::Long)
				->column('Auto_increment', Column::Long)
				->column('Create_time', Column::String)
				->column('Update_time', Column::String)
				->column('Check_time', Column::String)
				->column('Collation', Column::String)
				->column('Checksum', Column::Long)
				->column('Create_options', Column::String)
				->column('Comment', Column::String);
		}
		if (stripos($payload->query, 'show engines') === 0) {
			$data = [
				[
					'Engine' => 'MyISAM',
					'Support' => 'DEFAULT',
					'Comment' => 'MyISAM storage engine',
					'Transactions' => 'NO',
					'XA' => 'NO',
					'Savepoints' => 'NO',
				],
			];
			return TaskResult::withData($data)
				->column('Engine', Column::String)
				->column('Support', Column::String)
				->column('Comment', Column::String)
				->column('Transactions', Column::String)
				->column('XA', Column::String)
				->column('Savepoints', Column::String);
		}
		if (stripos($payload->query, 'show charset') === 0) {
			$data = [
				[
					'Charset' => 'utf8mb4',
					'Description' => 'UTF-8 Unicode',
					'Default collation' => 'utf8mb4_0900_ai_ci',
					'Maxlen' => 4,
				],
			];
			return TaskResult::withData($data)
				->column('Charset', Column::String)
				->column('Description', Column::String)
				->column('Default collation', Column::String)
				->column('Maxlen', Column::Long);
		}
		if (stripos($payload->query, 'show variables') === 0) {
			$query = 'SHOW VARIABLES';
			/** @var array{0:array{data:array<int,array<mixed>>}} */
			$result = $manticoreClient->sendRequest($query)->getResult();
			$condData = static::getVariablesWithCondition($result[0]['data'], $payload->query);
			return TaskResult::withData($condData)
				->column('Variable_name', Column::String)
				->column('Value', Column::String);
		}

		return self::checkForNoDataResponse($payload);
	}

	/**
	 * Return variables under the condition passed in the original query
	 *
	 * @param array<int,array<mixed>> $data
	 * @return array<mixed>
	 */
	public static function getVariablesWithCondition(array $data, string $query): array {
		$pattern = '/^show variables where '
			. '`?(?P<varColumn>(Variable_name|Value))`?\s*(?P<cond>(in|=))\s*'
			. '(\(|["\'])?(?P<condValue>(.*?))(["\']|\))?$/is';
		$match = [];
		if (!preg_match($pattern, $query, $match)) {
			throw new \RuntimeException('Cannot parse SHOW VARIABLES query');
		}
		$varColumn = $match['varColumn'];
		$cond = strtolower($match['cond']);
		if ($cond === 'in') {
			$condValue = array_map(
				function ($v) {
					return trim(trim($v), '"\'`');
				},
				explode(',', substr($match['condValue'], 1, -1))
			);
		} else {
			$condValue = $match['condValue'];
		}
		$res = array_filter(
			$data,
			function ($v) use ($cond, $condValue, $varColumn) {
				return ($cond === 'in' && in_array($v[$varColumn], (array)$condValue))
					|| ($cond === '=' && $v[$varColumn] === $condValue);
			}
		);
		return empty($res) ? [[]] : $res;
	}

}
