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
use Manticoresearch\Buddy\Core\ManticoreSearch\MySQLTool;
use Manticoresearch\Buddy\Core\Plugin\BaseHandler;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/** @package Manticoresearch\Buddy\Base\Plugin\Select */
final class Handler extends BaseHandler {
	const TABLES_FIELD_MAP = [
		'engine' => ['field', 'engine'],
		'table_type' => ['static', 'BASE TABLE'],
		'table_name' => ['table', ''],
	];

	const COLUMNS_FIELD_MAP = [
		'EXTRA' => ['static', ''],
		'GENERATION_EXPRESSION' => ['static', ''],
		'COLUMN_NAME' => ['field', 'Field'],
		'COLUMN_TYPE' => ['field', 'Type'],
		'DATA_TYPE' => ['field', 'Type'],
		'SCHEMA_NAME' => ['field', 'Databases'],
	];

	const DEFAULT_FIELD_VALUES = [
		'ENGINE' => 'MyISAM',
		'TABLE_COLLATION' => 'utf8mb4_general_ci',
		'DEFAULT_CHARACTER_SET_NAME' => 'utf8mb4',
		'DEFAULT_COLLATION_NAME' => 'utf8mb4_general_ci',
		'IS_NULLABLE' => 'yes',
		'CREATE_TIME' => '2024-01-01 00:00:00',
		'UPDATE_TIME' => '2024-01-01 00:00:00',
		'ROW_FORMAT' => 'Dynamic',
		'VERSION' => 10,
		'TABLE_ROWS' => 0,
		'AVG_ROW_LENGTH' => 4096,
		'DATA_LENGTH' => 16384,
		'MAX_DATA_LENGTH' => 0,
		'INDEX_LENGTH' => 0,
		'DATA_FREE' => 0,
		'TABLE_COMMENT' => '',
		'CREATE_OPTIONS' => '',
		'AUTO_INCREMENT' => '',
	];

	/** @var array<string,string> */
	protected static array $unsupportedMySQLVars;

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

			// 1. Select that has no table
			if (!$payload->table) {
				return static::handleNoTableSelect($manticoreClient, $payload);
			}

			// 2. Other cases with normal select * from [table]
			$tableName = strtolower($payload->table);
			if (isset($payload::HANDLED_TABLES[$tableName]) && $payload::HANDLED_TABLES[$tableName] === 0) {
				return $payload->getTaskResult();
			}

			return match ($payload->table) {
				'information_schema.columns' => static::handleSelectFromColumns($manticoreClient, $payload),
				'information_schema.schemata' => static::handleSelectFromSchemata($manticoreClient, $payload),
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
	 * @return Client
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
					self::unifyFieldNames($payload->fields);
					foreach ($payload->fields as $field) {
						[$type, $value] = static::TABLES_FIELD_MAP[$field]
							?? static::TABLES_FIELD_MAP[strtolower($field)] ?? ['field', strtolower($field)];
						$data[$i][$field] = match ($type) {
							'field' => $row[$value] ?? (static::DEFAULT_FIELD_VALUES[$field] ?? null),
							'table' => $table,
							'static' => $value,
							// default => $row[$field] ?? null,
						};
					}
					++$i;
				}
			}
		} else {
			$data = static::processSelectOtherFromTables($manticoreClient, $payload);
		}

		$result = $payload->getTaskResult();
		return $result->data($data);
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return array<array<string,mixed>>
	 */
	protected static function processSelectOtherFromTables(Client $manticoreClient, Payload $payload): array {
		$data = [];
		// grafana: SELECT DISTINCT TABLE_SCHEMA from information_schema.TABLES
		// where TABLE_TYPE != 'SYSTEM VIEW' ORDER BY TABLE_SCHEMA
		if (sizeof($payload->fields) === 1
			&& stripos($payload->fields[0], 'table_schema') !== false
		) {
			$data[] = [
				'TABLE_SCHEMA' => 'Manticore',
			];
		} elseif (stripos($payload->fields[0], 'table_name') !== false) {
			self::unifyFieldNames($payload->fields);
			$query = 'SHOW TABLES';
			/** @var array<array{data:array<array<string,string>>}> */
			$tablesResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();
			$row = $tablesResult[0]['data'][0];
			$dataRow = [];
			foreach ($payload->fields as $f) {
				$dataRow[$f] = self::getFieldValue($f, $row);
			}
			$data[] = $dataRow;
		}

		return $data;
	}

	/**
	 * @param array<string> $fieldNames
	 * @return void
	 */
	protected static function unifyFieldNames(array &$fieldNames):void {
		foreach ($fieldNames as $i => $f) {
			// Removing table alias part from field names and converting them to uppercase
			$fieldNames[$i] = strtoupper((string)preg_replace('/^.+\./', '', $f));
		}
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
		array &$dataRow,
		?MySQLTool $mySQLTool,
	): void {
		$dataTypeMap = [
			'string' => 'VARCHAR',
			'uint' => 'INT UNSIGNED',
			'boolean' => 'BOOL',
		];
		foreach (static::COLUMNS_FIELD_MAP as $mapKey => $mapInfo) {
			if ($mapInfo[1] !== $field) {
				continue;
			}
			if (!in_array($mapKey, $fields)) {
				array_push($fields, $mapKey);
			}
			//  We need to map Manticore data types to respective MySQL types to avoid problems with MYSQL tools
			//  Also, the HeidiSQL tool doesn't recognize lowercase data types so we add this extra transformation
			if ($mySQLTool && ($mapKey === 'DATA_TYPE' || $mapKey === 'COLUMN_TYPE')) {
				$value = strtoupper($dataTypeMap[$value] ?? $value);
			}
			$dataRow[$mapKey] = $value;
		}

		if ($mySQLTool === null || $mySQLTool !== MySQLTool::HEIDI) {
			return;
		}
		// Adding the character set columns with fake data since they're mandatory for HeidiSQL
		$extraColumnMap = [
			'CHARACTER_SET_NAME' => 'utf8_general_ci',
			'COLLATION_NAME' => 'utf8mb4',
			'IS_NULLABLE' => 'YES',
			'COLUMN_DEFAULT' => 'NULL',
		];
		if (!in_array('CHARACTER_SET_NAME', $fields)) {
			array_push($fields, ...array_keys($extraColumnMap));
		}
		$dataRow += $extraColumnMap;
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectFromSchemata(Client $manticoreClient, Payload $payload): TaskResult {
		$query = 'SHOW DATABASES';
		/** @var array<array{data:array<array<string,string>>}> */
		$queryResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();
		$data = [];
		$i = 0;
		self::unifyFieldNames($payload->fields);
		foreach ($queryResult[0]['data'] as $row) {
			$data[$i] = [];
			foreach ($payload->fields as $field) {
				[$type, $value] = static::COLUMNS_FIELD_MAP[$field] ?? ['field', strtolower($field)];
				$data[$i][$field] = match ($type) {
					'field' => $row[$value] ?? self::getFieldValue($field, $row),
					default => null,
				};
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
	protected static function handleSelectFromColumns(Client $manticoreClient, Payload $payload): TaskResult {
		$table = match (true) {
			isset($payload->where['table_name']['value']) => $payload->where['table_name']['value'],
			isset($payload->where['TABLE_NAME']['value']) => $payload->where['TABLE_NAME']['value'],
			isset($payload->where['table_schema']['value']) => $payload->where['table_schema']['value'],
			default => null,
		};

		if ($table === null) {
			$matches = [];
			// Table name still can be passed in one of conditional queries so we try to get it from there too
			preg_match("/table_name\s+IN\s+\('(.*)'\)/is", $payload->originalQuery, $matches);
			if (!$matches || !isset($matches[1])) {
				return $payload->getTaskResult();
			}
			$table = (string)$matches[1];
		}

		if ($table === 'Manticore') {
			// Some MySQL tools require info on columns from all database tables available
			$query = 'SHOW TABLES';
			/** @var array<array{data:array<array{Table:string}>}> */
			$showResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();
			$tables = array_map(
				fn ($row) => $row['Table'],
				$showResult[0]['data']
			);
			$data = [];
			foreach ($tables as $table) {
				$data = [...$data, ...self::getTableColumns($table, $manticoreClient, $payload)];
			}
		} else {
			$data = self::getTableColumns((string)$table, $manticoreClient, $payload);
		}

		$result = $payload->getTaskResult();
		return $result->data($data);
	}

	/**
	 * @param string $table
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return array<array<string,mixed>>
	 */
	protected static function getTableColumns(string $table, Client $manticoreClient, Payload $payload): array {
		$data = [];
		$query = "DESC {$table}";
		/** @var array<array{data:array<array<string,string>>}> */
		$descResult = $manticoreClient->sendRequest($query, $payload->path)->getResult();

		$areAllColumnsSelected = sizeof($payload->fields) === 1 && $payload->fields[0] === '*';
		if ($areAllColumnsSelected) {
			$payload->fields = [];
		}
		foreach ($descResult[0]['data'] as $row) {
			$rowData = [];
			if ($areAllColumnsSelected) {
				foreach ($row as $field => $value) {
					self::addSelectRowData($field, $value, $payload->fields, $rowData, $payload->mySQLTool);
				}
			} else {
				self::unifyFieldNames($payload->fields);
				foreach ($payload->fields as $field) {
					[$type, $value] = static::COLUMNS_FIELD_MAP[$field] ?? ['field', strtolower($field)];
					$rowData[$field] = match ($type) {
						'field' => $row[$value] ?? self::getFieldValue($field, $row, $table),
						'static' => $value,
						// default => $row[$field] ?? null,
					};
				}
			}
			$data[] = $rowData;
		}

		return $data;
	}

	/**
	 * @param string $field
	 * @param array<string,string> $row
	 * @param string $table
	 * @return mixed
	 */
	protected static function getFieldValue(string $field, array $row, ?string $table = null): mixed {
		if ($field === 'TABLE_NAME') {
			return $table ?? ($row['Table'] ?? null);
		}
		return static::DEFAULT_FIELD_VALUES[$field] ?? null;
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
				if (!isset($matches[6])) {
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

		$response = $manticoreClient->sendRequest($selectQuery);
		if ($isLikeOp) {
			$filterFn = static::getFilterRegexFieldFn();
			$response->apply($filterFn);
		}
		return TaskResult::fromResponse($response);
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
		$selectResponse = $manticoreClient->sendRequest($query, $payload->path);
		return TaskResult::fromResponse($selectResponse);
	}

	/**
	 * Remove table alias syntax from query if exists
	 *
	 * @param Payload $payload
	 * @return void
	 */
	protected static function removeAliasSyntaxIfExists(Payload $payload): void {
		$alias = false;
		if ($payload->fields) {
			$i = 0;
			do {
				$field = $payload->fields[$i];
				if (str_ends_with($field, '.*')) {
					$alias = str_replace('.*', '', $field);
					$payload->fields[$i] = '*';
					$payload->originalQuery = str_replace("$field", '*', $payload->originalQuery);
				}
				$i++;
			} while ($alias === false && $i < sizeof($payload->fields));
		}
		if ($alias === false) {
			return;
		}
		$payload->originalQuery = (string)preg_replace(
			"/{$payload->originalTable}\s+$alias\s+/is",
			$payload->originalTable . ' ',
			$payload->originalQuery
		);
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectDatabasePrefixed(Client $manticoreClient, Payload $payload): TaskResult {
		self::removeAliasSyntaxIfExists($payload);
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

		$queryResponse = $manticoreClient->sendRequest($query, $payload->path);
		/** @var array{error?:string} $queryResult */
		$queryResult = $queryResponse->getResult();

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

		return TaskResult::fromResponse($queryResponse);
	}

	/**
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectValues(Payload $payload): TaskResult {
		$field = $payload->fields[0] ?? 'Value';
		return TaskResult::withRow([$field => $payload->values[0]])
			->column($field, Column::String);
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
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleNoTableSelect(Client $manticoreClient, Payload $payload): TaskResult {
		// 0. Handle unsupported mysql vars query
		if (str_starts_with($payload->fields[0] ?? '', '@@')) {
			return static::handleSelectSysVars($manticoreClient, $payload);
		}

		// 1. Handle datagrip query
		if ($payload->fields === ['database', 'schema', 'user']) {
			return static::handleSelectDatagrip($payload);
		}

		// 2. Handle empty table case
		return static::handleMethods($manticoreClient, $payload);
	}

	/**
	 * @param Client $manticoreClient
	 * @param Payload $payload
	 * @return TaskResult
	 */
	protected static function handleSelectSysVars(Client $manticoreClient, Payload $payload): TaskResult {
		// Checking for field aliases first
		$fieldNames = $aliasFields = [];
		[$fieldNames, $aliasFields] = self::getFieldNamesAndAliases($payload->fields);
		// Get unsupported var names from the error message
		$errorFields = preg_split('/\s*;\s*/', str_replace('unknown sysvar ', '', $payload->error)) ?: [];
		$requeryFields = array_diff($fieldNames, $errorFields);
		$unsupportedMySQLVars = self::getUnsupportedMySQLVars();
		$allVars = [];
		foreach ($fieldNames as $fieldName) {
			if (in_array($fieldName, $errorFields)) {
				$varName = str_replace('@@', '', $fieldName);
				if (!isset($unsupportedMySQLVars[$varName])) {
					throw new Exception('unknown sysvar ' . $fieldName);
				}
				$allVars[$fieldName] = $unsupportedMySQLVars[$varName];
			} else {
				$allVars[$fieldName] = null;
			}
		}
		// If an original query contained supported vars as well, we need to get their values
		if ($requeryFields) {
			$requery = 'SELECT ' . implode(',', $requeryFields);
			/** @var array<array{data:array<array<string,string>>}> $requeryResult */
			$requeryResult = $manticoreClient->sendRequest($requery, $payload->path, false, true)->getResult();
			foreach ($requeryResult[0]['data'][0] as $field => $value) {
				$allVars[$field] = $value;
			}
		}

		foreach ($fieldNames as $i => $fieldName) {
			$fieldNames[$i] = $aliasFields[$fieldName] ?? $fieldName;
		}
		$result = TaskResult::withData(
			[
			array_combine($fieldNames, array_values($allVars)),
			]
		);
		foreach ($fieldNames as $column) {
			$result->column($column, Column::String);
		}
		return $result;
	}

	/**
	 * @param array<string> $fields
	 * @return array{0:array<string>,1:array<string,string>}
	 */
	protected static function getFieldNamesAndAliases(array $fields): array {
		$fieldNames = $aliasFields = [];
		foreach ($fields as $fieldName) {
			if (str_contains(strtolower($fieldName), ' as ')) {
				$fieldInfo = preg_split('/\s+as\s+/', strtolower($fieldName));
				if ($fieldInfo) {
					[$fieldName, $alias] = $fieldInfo;
					$aliasFields[$fieldName] = $alias;
				}
			}
			$fieldNames[] = $fieldName;
		}
		return [$fieldNames, $aliasFields];
	}

	/**
	 * Get unsupported system variable names
	 * @return array<string,string>
	 */
	protected static function getUnsupportedMySQLVars(): array {
		if (isset(static::$unsupportedMySQLVars)) {
			return static::$unsupportedMySQLVars;
		}
		$mySqlVarsFilePath = __DIR__ . '/../../Config/mysql_vars.json';
		$mySqlVarsContent = file_get_contents($mySqlVarsFilePath);
		if ($mySqlVarsContent === false) {
			throw new Exception("Unable to read MySQLVars config file at '$mySqlVarsFilePath'");
		}
		/** @var array<string,string> $mySqlVars */
		$mySqlVars = json_decode($mySqlVarsContent, true);
		if (!is_array($mySqlVars)) {
			throw new Exception("Invalid MySQLVars config file at '$mySqlVarsFilePath'");
		}
		static::$unsupportedMySQLVars = $mySqlVars;
		return static::$unsupportedMySQLVars;
	}

	/**
	 * Get filter regex fields method to exclude regex fields from result
	 * @return callable
	 */
	protected static function getFilterRegexFieldFn(): callable {
		/**
		 * @param array{0:array{columns:array<array<string,mixed>>,data:array<array<string,string>>}} $data
		 * @return array{0:array{columns:array<array<string,mixed>>,data:array<array<string,string>>}}
		 */
		return function (array $data): array {
			$data[0]['columns'] = array_filter(
				$data[0]['columns'],
				fn($v) => !str_ends_with(array_key_first($v) ?? '', '__regex')
			);

			$data[0]['data'] = array_map(
				function ($row) {
					foreach (array_keys($row) as $key) {
						/** @var string $key */
						if (!str_ends_with($key, '__regex')) {
							continue;
						}

						unset($row[$key]);
					}

					return $row;
				},
				$data[0]['data']
			);

			return $data;
		};
	}
}
