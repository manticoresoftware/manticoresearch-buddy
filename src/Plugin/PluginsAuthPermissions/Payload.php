<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	private const string RESOURCE_PATTERN =
		'/\bON\s+(?<resource>source|mva|materialized\s+view|chat_model|chat\s+model)\/'
		. '(?<name>\*|[A-Za-z_][A-Za-z0-9_]*)(?=\s|;|\z)/i';

	public string $query;
	public string $resource;
	public string $resourceName;
	public string $morphedQuery;

	public static function getInfo(): string {
		return 'GRANT/REVOKE plugin resource permissions';
	}

	public static function fromRequest(Request $request): static {
		$payload = new static();
		$payload->query = $request->payload;
		$payload->parseQuery();

		return $payload;
	}

	public static function hasMatch(Request $request): bool {
		if ($request->error === '') {
			return false;
		}

		if (!preg_match('/^\s*(GRANT|REVOKE)\b/i', $request->payload)) {
			return false;
		}

		return preg_match(self::RESOURCE_PATTERN, $request->payload) === 1;
	}

	/**
	 * @throws QueryParseError
	 */
	private function parseQuery(): void {
		$matched = preg_match(self::RESOURCE_PATTERN, $this->query, $matches);
		if ($matched !== 1) {
			throw QueryParseError::create('Invalid plugin resource permission query');
		}

		$this->resource = self::normalizeResource($matches['resource']);
		$this->resourceName = $matches['name'];
		$tableName = "'" . ResourceTable::name($this->resource, $this->resourceName) . "'";

		$morphedQuery = preg_replace(
			self::RESOURCE_PATTERN,
			'ON ' . $tableName,
			$this->query,
			1
		);
		if (!is_string($morphedQuery)) {
			throw QueryParseError::create('Invalid plugin resource permission query');
		}

		$this->morphedQuery = $morphedQuery;
	}

	private static function normalizeResource(string $resource): string {
		return match (true) {
			strtolower($resource) === 'mva',
			preg_match('/\Amaterialized\s+view\z/i', $resource) === 1 => ResourceTable::RESOURCE_MATERIALIZED_VIEW,
			preg_match('/\Achat\s+model\z/i', $resource) === 1 => ResourceTable::RESOURCE_CHAT_MODEL,
			default => strtolower($resource),
		};
	}
}
