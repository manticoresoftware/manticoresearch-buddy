<?php declare(strict_types=1);

/*
  Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue;

use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Permissions;

final class QueuePermissionChecker {
	private const string PERMISSION_DENIED_NEEDLE = 'permission denied';

	/**
	 * @throws ManticoreSearchClientError
	 */
	public static function requireSchema(Client $client, string $table): void {
		if (Permissions::hasSchemaAccess($client, $table)) {
			return;
		}

		throw self::permissionDenied('schema', $table);
	}

	/**
	 * @throws ManticoreSearchClientError
	 */
	public static function requireViewWrite(Client $client, string $table): void {
		$response = $client->sendRequest("UPDATE $table SET suspended=0 WHERE id=-1");
		if (!$response->hasError()) {
			return;
		}

		if (stripos((string)$response->getError(), self::PERMISSION_DENIED_NEEDLE) === false) {
			return;
		}

		throw self::permissionDenied('write', $table);
	}

	private static function permissionDenied(string $action, string $table): ManticoreSearchClientError {
		return ManticoreSearchClientError::create(
			"Permission denied: requires $action permission on table '$table'"
		);
	}
}
