<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Fuzzy;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;
use Manticoresearch\Buddy\Core\Tool\Arrays;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;
use RuntimeException;

/**
 * Request for Backup command that has parsed parameters from SQL
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	const MAX_BOOST = 50;
	const DECREASE_FACTOR = 1.44;

	/** @var string */
	public string $path;

	/** @var string */
	public string $table;

	/** @var bool */
	public bool $fuzzy;

	/** @var int */
	public int $distance;

	/** @var array<string> */
	public array $layouts;

	/** @var bool */
	public bool $preserve;

	/** @var string|array{index:string,query:array{match:array{'*'?:string}},options?:array<string,mixed>} */
	public array|string $payload;

	public function __construct() {
	}

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Fuzzy search plugin. It helps to find the best match for a given query.';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		return match ($request->endpointBundle) {
			Endpoint::Search => static::fromJsonRequest($request),
			default => static::fromSqlRequest($request),
		};
	}

	/**
	 * Parse the request as JSON
	 * @param Request $request
	 * @return static
	 */
	protected static function fromJsonRequest(Request $request): static {
		/** @var array{index:string,query:array{match:array{'*'?:string}},options:array{fuzzy?:bool,distance?:int,layouts?:string,preserve?:bool}} $payload */
		$payload = json_decode($request->payload, true);
		$self = new static();
		$self->path = $request->path;
		$self->table = $payload['index'];
		$self->fuzzy = (bool)($payload['options']['fuzzy'] ?? 0);
		$self->distance = (int)($payload['options']['distance'] ?? 2);
		$self->layouts = static::parseLayouts($payload['options']['layouts'] ?? null);
		$self->preserve = (bool)($payload['options']['preserve'] ?? true);

		$payload = static::cleanUpPayloadOptions($payload);
		$self->payload = $payload;
		return $self;
	}

	/**
	 * Parse the request as SQL
	 * @param Request $request
	 * @return static
	 */
	protected static function fromSqlRequest(Request $request): static {
		$query = $request->payload;
		preg_match('/FROM\s+(\w+)\s+WHERE/ius', $query, $matches);
		$tableName = $matches[1] ?? '';

		$pattern = '/OPTION\s+' .
			'([a-zA-Z0-9_]+\s*=\s*[\'"]?[a-zA-Z0-9_]+[\'"]?\s*,\s*)*' .
			'[a-zA-Z0-9_]+\s*=\s*[\'"]?[a-zA-Z0-9_]+[\'"]?' .
			'(\s*,\s*[a-zA-Z0-9_]+\s*=\s*[\'"]?[a-zA-Z0-9_]+[\'"]?)*$/ius';

		if (!preg_match($pattern, $query)) {
			throw QueryParseError::create('Invalid options in query string, make sure they are separated by commas');
		}

		// Parse fuzzy and use default 0 if missing
		if (!preg_match('/fuzzy\s*=\s*(\d+)/ius', $query, $matches)) {
			throw QueryParseError::create('Invalid value for option \'fuzzy\'');
		}
		$fuzzy = (bool)$matches[1];

		// Parse distance and use default 2 if missing
		preg_match('/distance\s*=\s*(\d+)/ius', $query, $matches);
		$distanceValue = (int)($matches[1] ?? 2);

		// Parse preserve
		preg_match('/preserve\s*=\s*(\d+)/ius', $query, $matches);
		$preserve = (bool)($matches[1] ?? 1);

		// Parse layouts and use default all languages if missed
		preg_match('/layouts\s*=\s*\'([a-zA-Z, ]*)\'/ius', $query, $matches);
		$layouts = static::parseLayouts($matches[1] ?? null);

		$self = new static();
		$self->path = $request->path;
		$self->table = $tableName;
		$self->fuzzy = $fuzzy;
		$self->distance = $distanceValue;
		$self->layouts = $layouts;
		$self->preserve = $preserve;
		$self->payload = $query;
		return $self;
	}

	/**
	 * @param callable $fn A callable function that returns an array of options to be used in the query
	 * @return string returns payload to use in the query send to Manticore
	 */
	public function getQueriesRequest(callable $fn): string {
		// In case we have SQL request, we just return it
		if (is_string($this->payload)) {
			return $this->getQueriesSQLRequest($fn);
		}

		// Now let check the case when we parse HTTP JSON request
		return $this->getQueriesHTTPRequest($fn);
	}

	/**
	 * Get request with prepared queries with callable function for SQL request
	 * @param callable $fn
	 * @return string
	 */
	public function getQueriesSQLRequest(callable $fn): string {
		/** @var string */
		$payload = $this->payload;
		preg_match('/MATCH\s*\(\'(.*?)\'\)/ius', $payload, $matches);
		$searchValue = $matches[1] ?? '';
		$template = (string)preg_replace(
			[
				'/MATCH\s*\(\'(.*?)\'\)/ius',
				'/(fuzzy|distance)\s*=\s*\d+[,\s]*/ius',
				'/(layouts)\s*=\s*\'([a-zA-Z, ]*)\'[,\s]*/ius',
				'/option,/ius',
				'/ option/ius', // TODO: hack
				],
			[
				'MATCH(\'%s\')',
				'',
				'',
				'option ',
				' option idf=\'plain,tfidf_normalized\',', // TODO: hack
			],
			$payload
		);
		$template = trim($template, ' ,');
		if (str_ends_with($template, 'option')) {
			$template = substr($template, 0, -6);
		}
		// TODO: hack
		if (false === strpos($template, 'option idf=\'plain,tfidf_normalized\'')) {
			$template .= ' option idf=\'plain,tfidf_normalized\'';
		}

		$match = $this->getQueryStringMatch($fn, $searchValue);
		Buddy::debug("Fuzzy: match: $match");
		return sprintf($template, $match);
	}

	/**
	 * @param callable $fn
	 * @param string $searchValue
	 * @return string
	 */
	public function getQueryStringMatch(callable $fn, string $searchValue): string {
		if (!$searchValue) {
			return '';
		}

		$isPhrase = false;
		if ($searchValue[0] === '"') {
			$isPhrase = true;
			$searchValue = trim($searchValue, '"');
		}
		/** @var array<string> $variations */
		$variations = $fn($searchValue);
		if ($isPhrase) {
			$match = '"' . implode(
				'"|"', array_map(
					function ($word, $i) {
						return $word . '^' . static::getBoostValue($i);
					}, $variations, array_keys($variations)
				)
			) . '"';
		} else {
			$match = '(' . implode(
				')|(', array_map(
					function ($word, $i) {
						return $word . '^' . static::getBoostValue($i);
					}, $variations, array_keys($variations)
				)
			) . ')';
		}
		// Edge case when nothing to match, use original phrase as fallback
		if (!$variations) {
			$match = $searchValue;
		}

		return $match;
	}

	/**
	 * Get request with prepared queries with callable function for HTTP request
	 * @param callable $fn
	 * @return string
	 */
	public function getQueriesHTTPRequest(callable $fn): string {
		/** @var array{index:string,query:array{match:array{'*'?:string}},options:array{fuzzy?:string,distance?:int,layouts?:string,other?:string}} $request */
		$request = $this->payload;
		$queries = static::parseQueryMatches($request['query']);
		Buddy::debug('Fuzzy: parsed queries: ' . implode(', ', $queries));
		foreach ($queries as $keyPath => $query) {
			$parts = explode('.', $keyPath);
			$lastIndex = sizeof($parts) - 1;
			$isQueryString = $parts[$lastIndex] === 'query_string' && !str_ends_with($keyPath, 'match.query_string');
			if ($isQueryString) {
				$match = $this->getQueryStringMatch($fn, $query);
				Arrays::setValueByDotNotation($request['query'], $keyPath, $match);
			} else {
				$options = $fn($query);
				$field = $parts[$lastIndex];
				$newQuery = static::buildShouldHttpQuery($field, $options);
				$replaceKey = implode('.', array_slice($parts, 0, -2));
				// Here we do some trick and set the value of the pass in do notation to the payload by using refs
				if ($replaceKey) {
					Arrays::setValueByDotNotation($request['query'], $replaceKey, $newQuery);
				} else {
					$request['query'] = $newQuery;
				}
				$encodedNewQuery = json_encode($newQuery);
				Buddy::debug("Fuzzy: transform: $query [$keyPath] -> $encodedNewQuery");
			}
		}
		$encoded = json_encode($request);
		if ($encoded === false) {
			throw new RuntimeException('Cannot encode JSON');
		}
		return $encoded;
	}

	/**
	 * @param string $field
	 * @param array<string> $options
	 * @return array{bool:array{should:array<array{match:array<string,string>}>}}
	 */
	private static function buildShouldHttpQuery(string $field, array $options): array {
		$should = [];
		foreach ($options as $v) {
			$should[] = [
				'match' => [
					$field => $v,
				],
			];
		}
		return ['bool' => ['should' => $should]];
	}

	/**
	 * Calculate the boost value depending on the position
	 * @param int $i
	 * @return float
	 */
	private static function getBoostValue(int $i): float {
		return max(static::MAX_BOOST / pow($i + 1, static::DECREASE_FACTOR), 1);
	}

	/**
	 * Recursively parse the query matches and extract it from nested arrays
	 * @param array<mixed> $values
	 * @param string|int $parent
	 * @return array<string,string>
	 */
	public static function parseQueryMatches(array $values, string|int $parent = ''): array {
		$queries = [];
		$parent = (string)$parent;
		foreach ($values as $key => $value) {
			$currentKey = $parent === '' ? $key : $parent . '.' . $key;
			$isArray = is_array($value);
			if ($isArray && isset($value['query'])) {
				$queries[$currentKey] = $value['query'];
			} elseif ($isArray) {
				$queries = array_merge($queries, static::parseQueryMatches($value, $currentKey));
			} elseif (is_string($value) && str_ends_with($parent, 'match')) {
				$queries[$currentKey] = $value;
			} elseif (is_string($value) && $key === 'query_string') {
				$queries[$currentKey] = $value;
			}
		}

		return $queries;
	}

	/**
	 * Clean up payload options that we do not need in the query
	 *
	 * @param array{
	 * index: string,
	 *     query: array{
	 *         match: array{
	 *             '*'?: string
	 *         }
	 *     },
	 *     options: array{
	 *         fuzzy?: bool,
	 *         distance?: int,
	 *         layouts?: string,
	 *         preserve?: bool
	 *     }
	 * } $payload
	 * @return array{index:string,query:array{match:array{'*'?:string}},options?:array<string,mixed>}
	 */
	public static function cleanUpPayloadOptions(array $payload): array {
		$excludedOptions = ['distance', 'fuzzy', 'layouts', 'preserve'];
		$payload['options'] = array_diff_key($payload['options'], array_flip($excludedOptions));
		// TODO: hack
		if (!isset($payload['options']['idf'])) { // @phpstan-ignore-line
			$payload['options']['idf'] = 'plain,tfidf_normalized';
		}
		/* if (empty($payload['options'])) { */
		/* 	unset($payload['options']); */
		/* } */

		return $payload;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$hasMatch = stripos($request->payload, 'select') === 0
			&& stripos($request->payload, 'match') !== false
			&& stripos($request->payload, 'option') !== false
			&& stripos($request->payload, 'fuzzy') !== false
			&& stripos($request->error, 'unknown option') !== false
		;


		if (!$hasMatch) {
			$hasMatch = $request->endpointBundle === Endpoint::Search
				&& stripos($request->error, 'unknown option') !== false
			;
		}

		return $hasMatch;
	}

	/**
	 * Helper to parse the lang string into array
	 * @param null|string|array<string> $layouts
	 * @return array<string>
	 */
	protected static function parseLayouts(null|string|array $layouts): array {
		// If we have array already, just return it
		if (is_array($layouts)) {
			return $layouts;
		}
		if (isset($layouts)) {
			$layouts = array_map('trim', explode(',', $layouts));
		} else {
			$layouts = KeyboardLayout::getSupportedLanguages();
		}
		return $layouts;
	}
}
