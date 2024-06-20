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
use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;

/**
 * Request for Backup command that has parsed parameters from SQL
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	/** @var string */
	public string $table;

	/** @var int */
	public int $distance;

	/** @var array<string> */
	public array $layouts;

	/** @var string */
	public string $query;

	/** @var string */
	public string $template;

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
		/** @var array{index:string,query:array{match:array{'*'?:string}},options:array{fuzzy?:string,distance?:int,layouts?:string,other?:string}} $payload */
		$payload = json_decode($request->payload, true);
		$query = $payload['query']['match']['*'] ?? '';
		if (!$query) {
			throw new QueryParseError('You are missing * match in query, please check it');
		}
		$self = new static();
		$self->table = $payload['index'];
		$self->query = $query;
		$self->distance = (int)($payload['options']['distance'] ?? 2);
		$self->layouts = static::parseLayouts($payload['options']['layouts'] ?? null);
		// Now build template that we will use to fetch fields with SQL but response will remain original query
		$self->template = "SELECT * FROM `{$self->table}` WHERE MATCH('%s')";
		$isFirstOption = true;
		foreach ($payload['options'] as $k => $v) {
			if ($k === 'distance' || $k === 'fuzzy' || $k === 'layouts') {
				continue;
			}
			if ($isFirstOption) {
				$self->template .= " OPTIONS {$k} = {$v}";
				$isFirstOption = false;
			} else {
				$self->template .= ", {$k} = {$v}";
			}
		}
		return $self;
	}

	/**
	 * Parse the request as SQL
	 * @param Request $request
	 * @return static
	 */
	protected static function fromSqlRequest(Request $request): static {
		$query = $request->payload;
		preg_match('/FROM\s+(\w+)\s+WHERE\s+MATCH\s*\(\'(.*?)\'\)/ius', $query, $matches);
		$tableName = $matches[1] ?? '';
		$searchValue = $matches[2] ?? '';

		// Parse distance and use default 2 if missing
		preg_match('/distance\s*=\s*(\d+)/ius', $query, $matches);
		$distanceValue = (int)($matches[1] ?? 2);

		// Parse layouts and use default all languages if missed
		preg_match('/layouts\s*=\s*\'([a-zA-Z, ]*)\'/ius', $query, $matches);
		$layouts = static::parseLayouts($matches[1]);

		$self = new static();
		$self->query = $searchValue;
		$self->table = $tableName;
		$self->distance = $distanceValue;
		$self->layouts = $layouts;
		$self->template = trim(
			(string)preg_replace(
				[
				'/MATCH\(\'(.*)\'\)/ius',
				'/(fuzzy|distance)\s*=\s*\d+[,\s]*/ius',
				'/(layouts)\s*=\s*\'([a-zA-Z, ]*)\'[,\s]*/ius',
				'/option,/ius',
				],
				[
				'MATCH(\'%s\')',
				'',
				'',
				'option ',
				],
				$query
			)
		);
		if (str_ends_with($self->template, 'option')) {
			$self->template = substr($self->template, 0, -6);
		}
		return $self;
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
