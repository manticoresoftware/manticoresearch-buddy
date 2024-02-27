<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Select;

use Exception;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandler;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

final class Handler extends BaseHandler {
	const TABLES_FIELD_MAP = [
		'engine' => ['field', 'engine'],
		'table_type' => ['static', 'BASE TABLE'],
		'table_name' => ['table', ''],
	];

	const COLUMNS_FIELD_MAP = [
		'extra' => ['static', ''],
		'generation_expression' => ['static', ''],
		'column_name' => ['field', 'Field'],
		'data_type' => ['field', 'Type'],
	];

  /** @var Client $manticoreClient */
	protected Client $manticoreClient;

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
			if ($payload->values) {
				return static::handleSelectValues($payload);
			}

			if (preg_match('/COUNT\([^*\)]+\)/ius', $payload->originalQuery)) {
				return static::handleSelectCountOnField($manticoreClient, $payload);
			}

			// 0. Select that has database
			if (stripos($payload->originalQuery, '`Manticore`.') > 0
				|| stripos($payload->originalQuery, 'Manticore.') > 0
			) {
				return static::handleSelectDatabasePrefixed($manticoreClient, $payload);
			}

			// 0. Handle datagrip query
			if (!$payload->table && $payload->fields === ['database', 'schema', 'user']) {
				return static::handleSelectDatagrip($payload);
			}

			// 1. Handle empty table case first
			if (!$payload->table) {
				return static::handleMethods($manticoreClient, $payload);
			}

			// 2. Other cases with normal select * from [table]
			$tableName = strtolower($payload->table);
			if (isset($payload::HANDLED_TABLES[$tableName]) && $payload::HANDLED_TABLES[$tableName] === 0) {
				return $payload->getTaskResult();
			}

			return match ($payload->table) {
				'information_schema.columns' => static::handleSelectFromColumns($manticoreClient, $payload),
				'information_schema.tables' => static::handleSelectFromTables($manticoreClient, $payload),
				default => static::handleSelectFromExistingTable($manticoreClient, $payload),
			};
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * @return array<string>
	 */
	public function getProps(): array {
		return ['manticoreClient'];
	}

	/**
	 * Instantiating the http client to execute requests to Manticore server
	 *
	 * @param Client $client
	 * $return Client
	 */
	public function setManticoreClient(Client $client): Client {
		$this->manticoreClient = $client;
		return $this->manticoreClient;
	}

	/**
	 * Parse show create table response
	 * @param string $schema
	 * @return array<string,string>
	 */
	protected static function parseTableSchema(string $schema): array {
		preg_match("/\) engine='(.+?)'/", $schema, $matches);
		$row = [];
		if ($matches) {
			$row['engine'] = strtoupper($matches[1]);
		} else {
			$row['engine'] = 'ROWWISE';
		}

		return $row;
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleMethods(Client $manticoreClient, Payload $payload): TaskResult {
		[$method] = $payload->fields;
		[$query, $field] = match (strtolower($method)) {
			'version()' => ["show status like 'mysql_version'", 'Value'],
			default => throw new Exception("Unsupported method called: $method"),
		};

		/** @var array{0:array{data:array{0:array{Databases:string,Value:string}}}} */
		$queryResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();
		return $payload->getTaskResult()->row(['Value' => $queryResult[0]['data'][0][$field]]);
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleFieldCount(Client $manticoreClient, Payload $payload): TaskResult {
		$table = $payload->where['table_name']['value'] ?? $payload->where['TABLE_NAME']['value'];
		$query = "DESC {$table}";
		/** @var array{0:array{data:array<mixed>}} */
		$descResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();
		$count = sizeof($descResult[0]['data']);
		return TaskResult::withRow(['COUNT(*)' => $count])
			->column('COUNT(*)', Column::String);
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectFromTables(Client $manticoreClient, Payload $payload): TaskResult {
		if (sizeof($payload->fields) === 1 && stripos($payload->fields[0], 'count(*)') === 0) {
			return static::handleFieldCount($manticoreClient, $payload);
		}

		$table = $payload->where['table_name']['value'] ?? $payload->where['TABLE_NAME']['value'] ?? null;
		$data = [];
		if ($table) {
			$query = "SHOW CREATE TABLE {$table}";
			/** @var array<array{data:array<array<string,string>>}> */
			$schemaResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();
			$createTable = $schemaResult[0]['data'][0]['Create Table'] ?? '';

			if ($createTable) {
				$createTables = [$createTable];
				$i = 0;
				foreach ($createTables as $createTable) {
					$row = static::parseTableSchema($createTable);
					$data[$i] = [];
					foreach ($payload->fields as $field) {
						[$type, $value] = static::TABLES_FIELD_MAP[$field]
							?? static::TABLES_FIELD_MAP[strtolower($field)] ?? ['field', $field];
						$data[$i][$field] = match ($type) {
							'field' => $row[$value],
							'table' => $table,
							'static' => $value,
							// default => $row[$field] ?? null,
						};
					}
					++$i;
				}
			}
		} else {
			$data = static::processSelectOtherFromFromTables($manticoreClient, $payload);
		}

		$result = $payload->getTaskResult();
		return $result->data($data);
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return array<array<string,string>>
	 */
	protected static function processSelectOtherFromFromTables(Client $manticoreClient, Payload $payload): array {
		$data = [];
		// grafana: SELECT DISTINCT TABLE_SCHEMA from information_schema.TABLES
		// where TABLE_TYPE != 'SYSTEM VIEW' ORDER BY TABLE_SCHEMA
		if (sizeof($payload->fields) === 1
			&& stripos($payload->fields[0], 'table_schema') !== false
		) {
			$data[] = [
				'TABLE_SCHEMA' => 'Manticore',
			];
		} elseif (sizeof($payload->fields) === 1 && stripos($payload->fields[0], 'table_name') !== false) {
			$query = 'SHOW TABLES';
			/** @var array<array{data:array<array<string,string>>}> */
			$tablesResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();
			foreach ($tablesResult[0]['data'] as $row) {
				$data[] = [
					'TABLE_NAME' => $row['Index'],
				];
			}
		}

		return $data;
	}

	/**
	 * Helper function to populate response data for `*` select queries
	 *
	 * @param string $field
	 * @param string $value
	 * @param array<mixed> $fields
	 * @param array<mixed> $dataRow
	 * @return void
	 */
	protected static function addSelectRowData(
		string $field,
		string $value,
		array &$fields,
		array &$dataRow
	): void {
		foreach (static::COLUMNS_FIELD_MAP as $mapKey => $mapInfo) {
			$mapKey = strtoupper($mapKey);
			if ($mapInfo[1] !== $field) {
				continue;
			}
			if (!in_array($mapKey, $fields)) {
				array_push($fields, $mapKey);
			}
			$dataRow[$mapKey] = $value;
		}
		// Adding the character set columns with fake data since they're mandatory for HeidiSQL
		$extraColumns = [
			'CHARACTER_SET_NAME',
			'COLLATION_NAME',
			'IS_NULLABLE',
			'COLUMN_DEFAULT',
		];
		if (!in_array('CHARACTER_SET_NAME', $fields)) {
			array_push($fields, ...$extraColumns);
		}
		$dataRow['CHARACTER_SET_NAME'] = 'utf8_general_ci';
		$dataRow['COLLATION_NAME'] = 'utf8mb4';
		$dataRow['IS_NULLABLE'] = 'YES';
		$dataRow['COLUMN_DEFAULT'] = 'NULL';
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectFromColumns(Client $manticoreClient, Payload $payload): TaskResult {
		$table = $payload->where['table_name']['value'] ?? $payload->where['TABLE_NAME']['value'] ?? null;
		// As for now, if an original query does not contain a table name we definitely can stop further processing
		if ($table === null) {
			return $payload->getTaskResult();
		}

		$query = "DESC {$table}";
		/** @var array<array{data:array<array<string,string>>}> */
		$descResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();

		$data = [];
		$i = 0;
		$areAllColumnsSelected = sizeof($payload->fields) === 1 && $payload->fields[0] === '*';
		if ($areAllColumnsSelected) {
			$payload->fields = [];
		}
		foreach ($descResult[0]['data'] as $row) {
			$data[$i] = [];
			if ($areAllColumnsSelected) {
				foreach ($row as $field => $value) {
					self::addSelectRowData($field, $value, $payload->fields, $data[$i]);
				}
			} else {
				foreach ($payload->fields as $field) {
					[$type, $value] = static::COLUMNS_FIELD_MAP[$field] ?? ['field', $field];
					$data[$i][$field] = match ($type) {
						'field' => $row[$value],
						'static' => $value,
						// default => $row[$field] ?? null,
					};
				}
			}
			++$i;
		}
		$result = $payload->getTaskResult();
		return $result->data($data);
	}


	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectFromExistingTable(Client $manticoreClient, Payload $payload): TaskResult {
		$table = str_ireplace(
			['`Manticore`.', 'Manticore.'],
			'',
			$payload->table
		);
		$selectQuery = str_ireplace(
			['`Manticore`.', 'Manticore.'],
			'',
			$payload->originalQuery
		);
		$selectQuery = preg_replace_callback(
			'/COALESCE\(([a-z@][a-z0-9_@]*),\s*\'\'\)\s*(<>|=[^>])\s*\'\'|'
				. 'CONTAINS\(([a-z@][a-z0-9_@]*), \'NEAR\(\((\w+), (\w+)\), (\d+)\)\'\)/ius',
			function ($matches) {
				if (isset($matches[1])) {
					return $matches[1] . ' ' . $matches[2] . ' \'\'';
				}

				return 'MATCH(\'' . $matches[4]
					. ' NEAR/' . $matches[6]
					. ' ' . $matches[5] . '\')';
			},
			$selectQuery
		);
		if (!$selectQuery) {
			throw new Exception('Failed to parse coalesce or contains from the query');
		}

		$query = "DESC {$table}";
		/** @var array<array{data:array<array<string,string>>}> */
		$descResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();

		$isLikeOp = false;
		foreach ($descResult[0]['data'] as $row) {
			// Skip missing where statements
			if (!isset($payload->where[$row['Field']])) {
				continue;
			}

			$field = $row['Field'];
			$operator = $payload->where[$field]['operator'];
			$value = $payload->where[$field]['value'];
			$isInOp = str_contains($operator, 'IN');
			$isLikeOp = str_contains($operator, 'LIKE');

			$regexFn = static function ($field, $value) {
				return "REGEX($field, '^" . str_replace(
					'%',
					'.*',
					$value
				) . "$')";
			};

			$isNot = str_starts_with($operator, 'NOT');
			$selectQuery = match ($row['Type']) {
				'bigint', 'int', 'uint' => str_replace(
					match (true) {
						$isInOp => "{$field} {$operator} ('{$value}')",
						default => "{$field} {$operator} '{$value}'",
					},
					match (true) {
						$isInOp => "{$field} {$operator} '{$value}'",
					default => "{$field} {$operator} {$value}",
					},
					$selectQuery
				),
				'json', 'string' => str_replace(
					"{$field} {$operator} '{$value}'",
					match (true) {
						$isLikeOp => "{$field}__regex = " . ($isNot ? '0' : '1'),
					default => "{$field} {$operator} '{$value}'",
					},

					$isLikeOp ? str_ireplace(
						'select ',
						'select ' . $regexFn($field, $value) . ' AS ' . $field . '__regex,',
						$selectQuery
					) : $selectQuery
				),
				default => $selectQuery,
			};
		}

		/** @var array{0:array{columns:array<array<string,mixed>>,data:array<array<string,string>>}} */
		$result = $manticoreClient->sendRequest($selectQuery)->getResult();
		if ($isLikeOp) {
			$result = static::filterRegexFieldsFromResult($result);
		}
		return TaskResult::raw($result);
	}


	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectCountOnField(Client $manticoreClient, Payload $payload): TaskResult {
		$selectQuery = str_ireplace(
			['`Manticore`.', 'Manticore.'],
			'',
			$payload->originalQuery
		);

		$pattern = '/COUNT\((?! *\* *\))(\w+)\)/ius';
		$replacement = 'COUNT(*)';
		$query = preg_replace($pattern, $replacement, $selectQuery);
		if (!$query) {
			throw new Exception('Failed to fix query');
		}
		/** @var array<array{data:array<array<string,string>>}> */
		$selectResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();
		return TaskResult::raw($selectResult);
	}

	/**
	 * Remove table alias syntax from query if exists
	 *
	 * @param Payload $payload
	 * @param string $query
	 * @return void
	 */
	protected static function checkQueryForAliasSyntax(Payload &$payload, string &$query): void {
		$alias = false;
		if ($payload->fields) {
			$i = 0;
			do {
				$field = $payload->fields[$i];
				if (str_ends_with($field, '.*')) {
					$alias = str_replace('.*', '', $field);
					$payload->fields[$i] = '*';
					$query = str_replace("$field", '*', $query);
				}
				$i++;
			} while ($alias === false && $i < sizeof($payload->fields));
		}
		if ($alias === false) {
			return;
		}
		$query = preg_replace("/{$payload->table}\s+$alias\s+/i", $payload->table . ' ', $query);
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectDatabasePrefixed(Client $manticoreClient, Payload $payload): TaskResult {
		$query = str_ireplace(
			['`Manticore`.', 'Manticore.'],
			'',
			$payload->originalQuery
		);

		// Replace all as `Sum(...)` or kind of to something that is supported by Manticore
		$pattern = '/(?<=\s`)[^`]+(?=`)/i';
		$query = preg_replace_callback(
			$pattern, function ($matches) {
				return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $matches[0]) ?? '');
			}, $query
		) ?? $query;

		// Replace date grains if presented
		if (stripos($query, 'date(') !== false || stripos($query, 'quarter') !== false) {
			$fieldPattern = '[@a-zA-Z0-9_]+';
			$patterns = [
				/* second */
				"/DATE_ADD\(DATE\(($fieldPattern)\),\s+INTERVAL\s+\(HOUR\(($fieldPattern)\)\s*\*\s*60\s*\*\s*60\s+\+"
				. "MINUTE\(($fieldPattern)\)\s*\*\s*60\s*\+\s*"
				. "SECOND\(($fieldPattern)\)\)\s+SECOND\)\s+AS\s+$fieldPattern/",

				"/DATE_ADD\(DATE\(($fieldPattern)\),\s+INTERVAL\s+\(HOUR\(($fieldPattern)\)\s*\*\s*60\s*\*\s*60\s+\+"
				. "MINUTE\(($fieldPattern)\)\s*\*\s*60\s*\+\s*SECOND\(($fieldPattern)\)\)\s+SECOND\)/",

				/* minute */
				"/DATE_ADD\(DATE\(($fieldPattern)\),\s+INTERVAL\s+\(HOUR\(($fieldPattern)\)\s*\*\s*60\s+\+"
				. "MINUTE\(($fieldPattern)\)\)\s+MINUTE\)\s+AS\s+$fieldPattern/",

				"/DATE_ADD\(DATE\(($fieldPattern)\),\s+INTERVAL\s+\(HOUR\(($fieldPattern)\)\s*\*\s*60\s*\*\s*60\s+\+"
				. "MINUTE\(($fieldPattern)\)\s*\*\s*60\s*\+\s*SECOND\(($fieldPattern)\)\)\s+SECOND\)/",

				/* hour */
				"/DATE_ADD\(DATE\(($fieldPattern)\),\s+INTERVAL\s+HOUR\(($fieldPattern)\)\s+HOUR\)"
				. "\s+AS\s+$fieldPattern/",
				"/DATE_ADD\(DATE\(($fieldPattern)\),\s+INTERVAL\s+HOUR\(($fieldPattern)\)\s+HOUR\)/",

				/* day */
				"/DATE\(($fieldPattern)\)\s+AS\s+$fieldPattern/",
				"/DATE\(($fieldPattern)\)/",

				// /* week */
				// "/DATE\(DATE_SUB\(($fieldPattern),\s+INTERVAL\s+DAYOFWEEK\(($fieldPattern)\)"
				// . "\s*-\s*1\s+DAY\)\)\s+AS\s+" .
				// "$fieldPattern/",

				// "/DATE\(DATE_SUB\(($fieldPattern),\s+INTERVAL\s+DAYOFWEEK\(($fieldPattern)\)\s*-\s*1\s+DAY\)\)/",

				// /* week on monday */
				// "/DATE\(DATE_SUB\(($fieldPattern),\s+INTERVAL\s+DAYOFWEEK\(DATE_SUB\((" .
				// "$fieldPattern),\s+INTERVAL\s+1\s+DAY\)\)\s*-\s*1\s+DAY\)\)\s+AS\s+$fieldPattern/",

				// "/DATE\(DATE_SUB\(($fieldPattern),\s+INTERVAL\s+DAYOFWEEK\(DATE_SUB\((" .
				// "$fieldPattern),\s+INTERVAL\s+1\s+DAY\)\)\s*-\s*1\s+DAY\)\)/",

				/* month */
				"/DATE\(DATE_SUB\(($fieldPattern),\s+INTERVAL\s+DAYOFMONTH\(("
				. "$fieldPattern)\)\s*-\s*1\s+DAY\)\)\s+AS\s+$fieldPattern/",

				"/DATE\(DATE_SUB\(($fieldPattern),\s+INTERVAL\s+DAYOFMONTH\(("
				. "$fieldPattern)\)\s*-\s*1\s+DAY\)\)/",

				// /* quarter */
				// "/QUARTER\(($fieldPattern)\)\s+QUARTER\s*-\s*INTERVAL\s+1\s+QUARTER\s+AS\s+$fieldPattern/",
				// "/QUARTER\(($fieldPattern)\)\s+QUARTER\s*-\s*INTERVAL\s+1\s+QUARTER/",

				/* year */
				"/DATE\(DATE_SUB\(($fieldPattern),\s+INTERVAL\s+DAYOFYEAR\(("
				. "$fieldPattern)\)\s*-\s*1\s+DAY\)\)\s+AS\s+$fieldPattern/",

				"/DATE\(DATE_SUB\(($fieldPattern),\s+INTERVAL\s+DAYOFYEAR\(("
				. "$fieldPattern)\)\s*-\s*1\s+DAY\)\)/",
			];

			$replacements = [
				/* second */
				"DATE_FORMAT($1, '%Y-%m-%d %T') AS $1",
				'$1',
				/* minute */
				"DATE_FORMAT($1, '%Y-%m-%d %H:%M') AS $1",
				'$1',
				/* hour */
				"DATE_FORMAT($1, '%Y-%m-%d %H') AS $1",
				'$1',
				/* day */
				"DATE_FORMAT($1, '%Y-%m-%d') AS $1",
				'$1',
				// /* week */
				// "DATE_FORMAT($1, '%Y-%U') AS $1",
				// "$1",
				// /* week on monday */
				// "DATE_FORMAT($1, '%Y-%W') AS $1",
				// "$1",
				/* month */
				"DATE_FORMAT($1, '%Y-%m') AS $1",
				'$1',
				// /* quarter */
				// "DATE_FORMAT($1, '%Y-%m') AS $1",
				// "$1",
				/* year */
				"DATE_FORMAT($1, '%Y') AS $1",
				'$1',
			];

			$query = preg_replace($patterns, $replacements, $query) ?? $query;
		}

		/** @var array{error?:string} $queryResult */
		$queryResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();
		if (isset($queryResult['error'])) {
			$payload->originalQuery = $query;
			$errors = [
				"unsupported filter type 'string' on attribute",
				"unsupported filter type 'stringlist' on attribute",
				'unexpected LIKE',
				"unexpected '('",
			];

			foreach ($errors as $error) {
				if (str_contains($queryResult['error'], $error)) {
					return static::handleSelectFromExistingTable($manticoreClient, $payload);
				}
			}
		}

		return TaskResult::raw($queryResult);
	}

	/**
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectValues(Payload $payload): TaskResult {
		return TaskResult::withRow(['Value' => $payload->values[0]])
			->column('Value', Column::String);
	}

	/**
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectDatagrip(Payload $payload): TaskResult {
		$result = TaskResult::withData(
			[
			array_fill_keys($payload->fields, 'manticore'),
			]
		);
		foreach ($payload->fields as $column) {
			$result->column($column, Column::String);
		}
		return $result;
	}


	/**
	 * @param array{0:array{columns:array<array<string,mixed>>,data:array<array<string,string>>}} $result
	 * @return array{0:array{columns:array<array<string,mixed>>,data:array<array<string,string>>}}
	 */
	protected static function filterRegexFieldsFromResult(array $result): array {
		$result[0]['columns'] = array_filter(
			$result[0]['columns'],
			fn($v) => !str_ends_with(array_key_first($v) ?? '', '__regex')
		);
		$result[0]['data'] = array_map(
			function ($row) {
				foreach (array_keys($row) as $key) {
					if (!str_ends_with($key, '__regex')) {
						continue;
					}

					unset($row[$key]);
				}

				return $row;
			}, $result[0]['data']
		);

		return $result;
	}
}
