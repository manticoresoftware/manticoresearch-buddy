<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class ResourceTable {
	public const string RESOURCE_SOURCE = 'source';
	public const string RESOURCE_MATERIALIZED_VIEW = 'materialized_view';
	public const string RESOURCE_CHAT_MODEL = 'chat_model';
	public const string RESOURCE_CHAT_HISTORY = 'chat_history';
	public const string TABLE_PREFIX_SOURCE = 'system.source_';
	public const string TABLE_PREFIX_MATERIALIZED_VIEW = 'system.materialized_view_';
	public const string TABLE_PREFIX_CHAT_MODEL = 'system.chat_model_';
	public const string TABLE_PREFIX_CHAT_HISTORY = 'system.chat_history_';

	private const string IDENTIFIER_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

	/**
	 * @throws QueryParseError
	 */
	public static function name(string $resource, string $name): string {
		self::validateName($name);

		return match ($resource) {
			self::RESOURCE_SOURCE => self::TABLE_PREFIX_SOURCE . $name,
			self::RESOURCE_MATERIALIZED_VIEW => self::TABLE_PREFIX_MATERIALIZED_VIEW . $name,
			self::RESOURCE_CHAT_MODEL => self::TABLE_PREFIX_CHAT_MODEL . $name,
			self::RESOURCE_CHAT_HISTORY => self::TABLE_PREFIX_CHAT_HISTORY . $name,
			default => throw QueryParseError::create("Unsupported plugin resource '$resource'"),
		};
	}

	/**
	 * @throws QueryParseError
	 */
	private static function validateName(string $name): void {
		if ($name === '*') {
			return;
		}

		if (preg_match(self::IDENTIFIER_PATTERN, $name) !== 1) {
			throw QueryParseError::create("Invalid plugin resource name '$name'");
		}
	}

	public static function isName(string $name): bool {
		return preg_match(self::IDENTIFIER_PATTERN, $name) === 1;
	}

	/**
	 * @return list<string>
	 * @throws ManticoreSearchClientError
	 */
	public static function list(Client $client, string $prefix): array {
		$response = $client->sendRequest('SHOW TABLES FROM system');
		if ($response->hasError()) {
			throw ManticoreSearchClientError::create((string)$response->getError());
		}

		/** @var array<int, array{data: array<int, array{Table: string, Type: string}>}> $result */
		$result = $response->getResult();
		$tables = [];
		foreach ($result[0]['data'] as $row) {
			if (!str_starts_with($row['Table'], $prefix)) {
				continue;
			}

			$tables[] = $row['Table'];
		}

		return $tables;
	}
}
