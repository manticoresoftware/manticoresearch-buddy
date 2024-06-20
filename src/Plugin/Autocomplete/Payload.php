<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Autocomplete;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

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
		/** @var array{query?:string|mixed,table?:string|mixed} $payload */
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
		return $self;
	}

	/**
	 * Parse the request from the given SQL
	 * @param Request $request
	 * @return static
	 */
	protected static function fromSqlRequest(Request $request): static {
		preg_match('/autocomplete\s*\(([\'"])([^\1]+?)\1\s*,\s*([\'"])([^\3]+?)\3\)/ius', $request->payload, $matches);
		if (!$matches) {
			throw new QueryParseError('Failed to parse query');
		}

		$self = new static();
		$self->query = $matches[2];
		$self->table = $matches[4];
		return $self;
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
}
