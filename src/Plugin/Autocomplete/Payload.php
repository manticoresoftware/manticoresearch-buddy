<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Autocomplete;

use Exception;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;
use Manticoresearch\Buddy\Core\Tool\KeyboardLayout;
use Manticoresearch\Buddy\Core\Tool\Strings;

/**
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	/** @var string */
	public string $table;

	/** @var string */
	public string $query;

	/** @var int */
	public int $distance = 2;

	/** @var int */
	public int $maxEdits = 5;

	/** @var array<string> */
	public array $layouts = [];

	public function __construct() {
	}

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Autocomplete plugin that offers suggestions based on the starting query';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		return match ($request->endpointBundle) {
			Endpoint::Autocomplete => static::fromJsonRequest($request),
			default => static::fromSqlRequest($request),
		};
	}

	/**
	 * Parse the request from the given JSON
	 * @param Request $request
	 * @return static
	 */
	protected static function fromJsonRequest(Request $request): static {
		/** @var array{query?:string|mixed,table?:string|mixed,options?:array{distance?:int,max_edits?:int,layouts?:string}} $payload */
		$payload = json_decode($request->payload, true);
		if (!isset($payload['query']) || !is_string($payload['query'])) {
			throw new QueryParseError('Failed to parse query: make sure you have query and it is a string');
		}

		if (!isset($payload['table']) || !is_string($payload['table'])) {
			throw new QueryParseError('Failed to parse query: make sure you have table and it is a string');
		}

		$self = new static();
		$self->query = $payload['query'];
		$self->table = $payload['table'];
		$self->distance = (int)($payload['options']['distance'] ?? 2);
		$self->maxEdits = (int)($payload['options']['max_edits'] ?? 5);
		$self->layouts = static::parseLayouts($payload['options']['layouts'] ?? null);
		return $self;
	}

	/**
	 * Parse the request from the given SQL
	 * @param Request $request
	 * @return static
	 */
	protected static function fromSqlRequest(Request $request): static {
		$pattern = '/autocomplete\('
			. '\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'\s*'
			. '((?:,\s*(?:(\d+)\s+as\s+(\w+)|\'([^\']+)\'\s+as\s+(\w+))\s*)*)\)/ius';
		preg_match($pattern, $request->payload, $matches);
		if (!$matches) {
			throw new QueryParseError('Failed to parse query');
		}

		$self = new static();
		$self->query = $matches[1];
		$self->table = $matches[2];
		$self->parseOptions($matches);
		return $self;
	}

	/**
	 * @param array<int,string> $matches
	 * @return void
	 * @throws Exception
	 */
	protected function parseOptions(array $matches): void {
		$matchesLen = sizeof($matches);
		if ($matchesLen <= 4) {
			return;
		}

		for ($i = 4; $i < $matchesLen; $i += 2) {
			$value = $matches[$i];
			$key = $matches[$i + 1];
			$key = Strings::underscoreToCamelcase($key);
			if (!property_exists($this, $key) || $key === 'query' || $key === 'table') {
				QueryParseError::throw("Unknown option '$key'");
			}
			if ($key === 'layouts') {
				$value = static::parseLayouts($value);
			}
			if ($key === 'distance' || $key === 'max_edits') {
				$value = (int)$value;
			}
			$this->{$key} = $value;
		}
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$hasMatch = $request->error === 'no such built-in procedure AUTOCOMPLETE'
			&& stripos($request->payload, 'CALL AUTOCOMPLETE(') === 0
		;

		if (!$hasMatch) {
			$hasMatch = $request->endpointBundle === Endpoint::Autocomplete
				&& str_contains($request->error, 'unsupported endpoint');
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
