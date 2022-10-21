<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Exception\SQLQueryParsingError;
use Manticoresearch\Buddy\Interface\CommandRequestInterface;
use RuntimeException;

/**
 * Request for Backup command that has parsed parameters from SQL
 */
class BackupRequest implements CommandRequestInterface {
	const OPTIONS = [
		'async' => 'bool',
		'compress' => 'bool',
	];

  /**
   * @param string $path
   * @param string[] $tables
   * @param array{async?:bool,compress?:bool} $options
   */
	public function __construct(
		public string $path,
		public array $tables,
		public array $options
	) {
	}


  /**
   * Create instance by parsing query into parameters
   *
   * @param string $query
   *  The query itself without command prefix already
   * @return BackupRequest
   * @throws SQLQueryParsingError
   */
	public static function fromQuery(string $query): BackupRequest {
		$whatPattern = '(?P<all>ALL)|(?:TABLES?\s*(?P<table>(,?\s*[\w]+\s*)+))';
		$toPattern = 'TO\s*local\(\s*(?P<path>[\\_\-a-z/0-9]+)\s*\)';
		$optionsKeys = implode('|', array_keys(static::OPTIONS));
		$optionsPattern = 'OPTIONS?\s*(?P<options>'
			. '(,?\s*(?:' . $optionsKeys . ')\s*\=\s*(?:0|1|true|false|yes|no|on|off)'
			. '\s*)+)'
		;
		$pattern = "($whatPattern)?\s*($toPattern)?\s*($optionsPattern)?";
		if (false === preg_match("#^$pattern$#ius", $query, $matches)) {
			throw new SQLQueryParsingError();
		}

		$params = static::extractNamedMatches($matches);
		// TODO: fix the default path
		$path = $params['path'] ?? '/var/lib/manticore';
		$tables = !isset($params['all']) && isset($params['table'])
			? array_map(trim(...), explode(',', $params['table']))
			: []
		;
		/* @var array{async?:bool,compress?:bool} $options */
		$options = isset($params['options'])
		? array_reduce(
			array_map(trim(...), explode(',', $params['options'])),
			function (array $map, string $v): array {
				[$key, $value] = array_map(trim(...), explode('=', $v));
				$map[$key] = static::castOption($key, $value);
				return $map;
			},
			[]
		) : []
		;

		return new BackupRequest(
			path: $path,
			tables: $tables,
			options: $options
		);
	}

  /**
   * TODO: extract to common utils
   * This is helper to extract named matches from results of executing regexp pattern
   *
   * @param string[] $matches
   *  result of matching from regexp
   * @return array<string,string>
   *  Filtere result with only named matches in it
   */
	protected static function extractNamedMatches(array $matches): array {
		return array_filter(
			$matches,
			fn($v, $k) => is_string($k) && !empty($v),
			ARRAY_FILTER_USE_BOTH
		);
	}

  /**
   * TODO: extract to common utils that utilize some mappings in component
   * Cast the option to the its type declared in OPTIONS constant
   *
   * @param string $key
   * @param string $value
   * @return bool
   * @throws RuntimeException
   */
	protected static function castOption(string $key, string $value): bool {
		$type = static::OPTIONS[$key] ?? 'undefined';
		return match ($type) {
			'bool', 'boolean' => $value === 'true' || $value === '1' || $value === 'yes' || $value === 'on',
			default => throw new RuntimeException("Unsupported type to cast the option '$key' to '$type"),
		};
	}
}
