<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Select;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\MySQLTool;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\TaskResult;

/**
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	// HANDLED_TABLES value defines if we actually process the request and return some data (1)
	// or just return an empty result (0)
	const HANDLED_TABLES = [
		'information_schema.files' => 0,
		'information_schema.tables' => 1,
		'information_schema.triggers' => 0,
		'information_schema.column_statistics' => 0,
		'information_schema.columns' => 1,
		'information_schema.events' => 0,
		'information_schema.schemata' => 0,
		'information_schema.key_column_usage' => 0,
		'information_schema.statistics' => 0,
		'information_schema.partitions' => 0,
		'information_schema.referential_constraints' => 0,
		'information_schema.routines' => 0,
		'mysql.user' => 0,
	];

	/** @var string */
	public string $originalQuery;

	/** @var string */
	public string $path;

	/** @var string */
	public string $table = '';

	/** @var array<string> */
	public array $fields = [];

	/** @var array<string> */
	public array $values = [];

	/** @var array<string,array{operator:string,value:int|string|bool}> */
	public array $where = [];

	/** @var ?MySQLTool */
	public ?MySQLTool $mySQLTool = null;

	public function __construct() {
	}

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Various SELECTs handlers needed for mysqldump'
			. ' and other software support, mostly aiming to work similarly to MySQL';
	}

	/**
	 * @param Request $request
	 * @return static
	 * @throws QueryParseError
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$self->path = $request->path;
		$self->originalQuery = str_replace("\n", ' ', $request->payload);
		$self->mySQLTool = $request->mySQLTool ?? null;
		// Match fields
		preg_match(
			'/^SELECT\s+(?:(.*?)\s+FROM\s+(`?[a-z][a-z\_\-0-9]*`?(\.`?[a-z][a-z\_\-0-9]*`?)?)'
			. '|(version\(\))|(\'[^\']*?\'))/is',
			$self->originalQuery,
			$matches
		);

		// At this point we have two cases: when we have table and when we direct select some function like
		// select version()
		// we put this function in fields and table will be empty
		// otherwise it's normal select with fields and table required
		if ($matches[2] ?? null) {
			$table = strtolower(ltrim((string)$matches[2], '.'));
			if ($table[0] === '`' || $table[-1] === '`') {
				$table = trim((string)preg_replace('/`?\.`?/', '.', $table), '`');
			}
			$self->table = $table;
			$pattern = '/(?:[^,(]+|(\((?>[^()]+|(?1))*\)))+/';
			preg_match_all($pattern, $matches[1], $matches);
			$self->fields = array_map('trim', $matches[0]);

			$self->where = self::addWhereStatements($request->payload);

			// Check that we hit tables that we support otherwise return standard error
			// To proxy original one
			if (!str_contains($request->error, "unsupported filter type 'string' on attribute")
				&& !isset(static::HANDLED_TABLES[$self->table])
				&& !str_starts_with($self->table, 'manticore')
			) {
				throw QueryParseError::create('Failed to handle your select query', true);
			}
		} else {
			$self->handleNoTableMatches($matches);
		}

		return $self;
	}

	/**
	 * Helper function to add where statements to the Payload object
	 *
	 * @param string $payload
	 * @return array<string,array{operator:string,value:int|string|bool}>
	 */
	protected static function addWhereStatements(string $payload): array {
		// Match WHERE statements
		$where = [];
		$matches = [];
		$pattern = '/([@a-zA-Z0-9_]+)\s*(=|<|>|!=|<>|'
			. 'NOT LIKE|LIKE|NOT IN|IN)'
			. "\s*(?:\('([^']+)'\)|'([^']+)'|([0-9]+))/";
		preg_match_all($pattern, $payload, $matches);
		foreach ($matches[1] as $i => $column) {
			$operator = $matches[2][$i];
			$value = $matches[3][$i] !== '' ? $matches[3][$i] : $matches[4][$i];
			$where[(string)$column] = [
				'operator' => (string)$operator,
				'value' => (string)$value,
			];
		}

		return $where;
	}

	/**
	 * Handle no table matches from select query
	 * @param array<string> $matches
	 * @return static
	 */
	protected function handleNoTableMatches(array $matches): static {
		if (isset($matches[5])) {
			$this->values = [trim($matches[5], "'")];
		} elseif (isset($matches[4])) {
			$this->fields[] = $matches[4];
		} elseif (stripos($this->originalQuery, 'database()') !== false
			&& stripos($this->originalQuery, 'schema()') !== false
			&& stripos($this->originalQuery, 'left(user()') !== false) {
			$this->fields = [
				'database',
				'schema',
				'user',
			];
		}
		return $this;
	}

	/**
	 * Return initial TaskResult that we can extend later
	 * based on the fields we have
	 *
	 * @return TaskResult
	 */
	public function getTaskResult(): TaskResult {
		$result = TaskResult::withTotal(0);
		foreach ($this->fields as $field) {
			if (stripos($field, ' AS ') !== false) {
				[, $field] = (array)preg_split('/ AS /i', $field);
			}
			$result->column(trim((string)$field, '`'), Column::String);
		}

		return $result;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$isSelect = stripos($request->payload, 'select') === 0;
		if ($isSelect) {
			foreach (array_keys(static::HANDLED_TABLES) as $table) {
				[$db, $dbTable] = explode('.', $table);
				if (preg_match("/`?$db`?\.`?$dbTable`?/i", $request->payload)) {
					return true;
				}
			}

			if (preg_match('/(`?Manticore`?|^select\s+version\(\))/ius', $request->payload)) {
				return true;
			}

			if (static::matchError($request)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function matchError(Request $request): bool {
		if (str_contains($request->error, "unsupported filter type 'string' on attribute")) {
			return true;
		}

		if (str_contains($request->error, "syntax error, unexpected identifier, expecting DISTINCT or '*' near")) {
			return true;
		}

		if (str_contains($request->error, "unsupported filter type 'stringlist' on attribute")) {
			return true;
		}

		if (str_contains($request->error, 'unexpected LIKE')) {
			return true;
		}

		if (str_contains($request->error, "unexpected '('")
			&& (stripos($request->payload, 'coalesce') !== false
				|| stripos($request->payload, 'contains') !== false
				|| str_contains($request->error, "expecting \$end near ')'")
			)
		) {
			return true;
		}

		if (str_contains($request->error, "unexpected identifier, expecting ',' or ')' near")
			&& (stripos($request->payload, 'date(') !== false
				|| stripos($request->payload, 'quarter') !== false)) {
			return true;
		}

		if (str_contains($request->error, 'unexpected $end')) {
			return true;
		}

		if (str_contains($request->error, 'unexpected $undefined near \'.*')) {
			return true;
		}
		return false;
	}
}
