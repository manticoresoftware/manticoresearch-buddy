<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Backup;

use Manticoresearch\Buddy\Base\CommandRequestBase;
use Manticoresearch\Buddy\Exception\SQLQueryParsingError;
use Manticoresearch\Buddy\Network\Request as NetRequest;
use RuntimeException;

/**
 * Request for Backup command that has parsed parameters from SQL
 */
final class Request extends CommandRequestBase {
	const OPTIONS = [
		'async' => 'bool',
		'compress' => 'bool',
	];

	/** @var string */
	public string $configPath;

  /**
   * @param string $path
   * @param string[] $tables
   * @param array{async?:bool,compress?:bool} $options
   */
	public function __construct(
		public string $path,
		public array $tables,
		public array $options,
	) {
		$configFile = getenv('SEARCHD_CONFIG');
		if (!$configFile || !file_exists($configFile)) {
			throw new RuntimeException("Cannot find manticore config file: $configFile");
		}
		$this->configPath = $configFile;
	}


  /**
   * Create instance by parsing query into parameters
   *
   * @param NetRequest $request
   *  The query itself without command prefix already
   * @return Request
   * @throws SQLQueryParsingError
   */
	public static function fromNetworkRequest(NetRequest $request): Request {
		$query = $request->payload;
		$whatPattern = '(?:TABLES?\s*(?P<table>(,?\s*[\w]+\s*)+))';
		$toPattern = 'TO\s*(?P<path>([\._\-a-z/0-9]+|[A-Z]\:\\\[\.\\\_\-a-z0-9]+))';
		$optionsKeys = implode('|', array_keys(static::OPTIONS));
		$optionsPattern = 'OPTIONS?\s*(?P<options>'
			. '(,?\s*(?:' . $optionsKeys . ')\s*\=\s*(?:0|1|true|false|yes|no|on|off)'
			. '\s*)+)'
		;
		$pattern = "BACKUP\s*($whatPattern)?\s*($toPattern)\s*($optionsPattern)?";
		if (false === preg_match("#^$pattern$#ius", $query, $matches)) {
			static::throwSqlParseError();
		}

		$params = static::extractNamedMatches($matches);
		if (!isset($params['path'])) {
			static::throwSqlParseError();
		}
		$path = $params['path'];
		$tables = isset($params['table'])
			? array_map(trim(...), explode(',', $params['table']))
			: []
		;

		/** @var array{async?:bool,compress?:bool} $options */
		$options = isset($params['options'])
			? array_reduce(
				array_map(trim(...), explode(',', $params['options'])),
				function (array $map, string $v): array {
					[$key, $value] = array_map(fn ($v) => trim(strtolower($v)), explode('=', $v));
					$map[$key] = static::castOption($key, $value);
					return $map;
				},
				[]
			) : []
		;

		$self = new Request(
			path: $path,
			tables: $tables,
			options: $options
		);

		$queryTokens = static::extractTokens($query);
		$buildTokens = $self->tokenize();
		if (array_diff($queryTokens, $buildTokens)) {
			static::throwSqlParseError();
		}

		return $self;
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
			'bool' => $value === 'true' || $value === '1' || $value === 'yes' || $value === 'on',
			default => throw new RuntimeException("Unsupported type to cast the option '$key' to '$type"),
		};
	}

	/**
	 * Extract tokens from query to validate
	 *
	 * @param string $query
	 * @return array<string>
	 */
	protected static function extractTokens(string $query): array {
		$query = preg_replace(
			[
				'/\(\s*([^\)]+?)\s*\)/',
				'/(' . implode('|', array_keys(static::OPTIONS)) . ')\s*=\s*/ius',
				'/=\s*(on|1|yes|true)/ius',
				'/=\s*(off|0|no|false)/ius',
				'/options/ius',
				'/tables/ius',
			], [
				'($1)',
				'$1=',
				'=1',
				'=0',
				'option',
				'table',
				'',
			], $query
		);
		return static::prepareTokens((array)explode(' ', $query ?: ''));
	}

	/**
	 * Do the same as extractTokens but with current request and opposite operation
	 * We tokenize to single form of word and boolean to 0 or 1 by using replace query
	 *
	 * @return array<string>
	 */
	protected function tokenize(): array {
		$tokens = [
			'backup',
			'to',
			$this->path,
		];

		if ($this->tables) {
			$tokens[] = 'table';
			array_push($tokens, ...$this->tables);
		}

		if ($this->options) {
			$tokens[] = 'option';
			foreach ($this->options as $key => $value) {
				$tokens[] = $key . '=' . ($value ? '1' : '0');
			}
		}

		return static::prepareTokens($tokens);
	}

	/**
	 * Helper to convert token list to single output
	 *
	 * @param array<string> $tokens
	 * @return array<string>
	 */
	protected static function prepareTokens(array $tokens): array {
		return array_unique(
			array_filter(
				array_map(
					fn ($v) => strtolower(trim($v, ' ,')),
					$tokens
				)
			)
		);
	}

	/**
	 * Throws parse error
	 * @return void
	 * @throws SQLQueryParsingError
	 */
	protected static function throwSqlParseError(): void {
		throw SQLQueryParsingError::create('You have an error in your query. Please, double check it.');
	}
}
