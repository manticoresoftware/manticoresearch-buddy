<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\AlterColumn;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class Handler extends BaseHandlerWithClient
{

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
		$taskFn = static function (Payload $payload, Client $client): TaskResult {
			if (!isset($payload->type)) {
				throw GenericError::create(
					'No operation is given'
				);
			}
			if ($payload->type === 'drop') {
				$sql = "ALTER TABLE {$payload->destinationTableName} DROP COLUMN {$payload->columnName}";
			} elseif ($payload->type === 'add') {
				$columnDatatype = static::getManticoreDatatype($payload->columnDatatype);
				$sql = "ALTER TABLE {$payload->destinationTableName} "
					. "ADD COLUMN {$payload->columnName} $columnDatatype";
			} else {
				throw GenericError::create(
					"Only add/drop operations are supported, {$payload->type} operation is given"
				);
			}
			$result = $client->sendRequest($sql);
			if ($result->hasError()) {
				throw GenericError::create(
					"Can't {$payload->type} column in table {$payload->destinationTableName}. Reason: "
						. $result->getError()
				);
			}

			return TaskResult::none();
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * Map MySQL data type to a manticore one when possible
	 * @param string $mySqlDatatype
	 * @return string
	 * @throws RuntimeException
	 */
	protected static function getManticoreDatatype(string $mySqlDatatype): string {
		$mySqlDatatype = strtoupper($mySqlDatatype);
		$typeMap = [
			'BIGINT UNSIGNED' => 'bigint',
			'BINARY' => 'string',
			'BIT' => 'int',
			'BLOB' => 'text',
			'BOOL' => 'boolean',
			'CHAR' => 'string',
			'DATE' => 'timestamp',
			'DATETIME' => 'timestamp',
			'FLOAT' => 'float',
			'INT' => 'int',
			'INT UNSIGNED' => 'int',
			'LONG VARBINARY' => 'int',
			'LONG VARCHAR' => 'string',
			'LONGBLOB' => 'text',
			'LONGTEXT' => 'text',
			'MEDIUMBLOB' => 'text',
			'MEDIUMINT UNSIGNED' => 'text',
			'MEDIUMTEXT' => 'text',
			'SMALLINT UNSIGNED' => 'int',
			'TEXT' => 'text',
			'TIME' => 'timestamp',
			'TIMESTAMP' => 'timestamp',
			'TINYBLOB' => 'text',
			'TINYINT UNSIGNED' => 'int',
			'TINYTEXT' => 'text',
			'VARBINARY' => 'string',
			'VARCHAR' => 'string',
			'JSON' => 'json',
		];
		if (!isset($typeMap[$mySqlDatatype])) {
			throw GenericError::create(
				"Can't map $mySqlDatatype to any Manticore data type"
			);
		}

		return $typeMap[$mySqlDatatype];
	}
}
