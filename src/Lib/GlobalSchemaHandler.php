<?php declare(strict_types=1);

/*
 Copyright (c) 2026, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Lib;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Tool\ConfigManager;
use Throwable;

/**
 * Global operations implemented in Buddy cannot rely on authorization of a
 * parsed statement in the daemon, or on a grant for an individual table.
 */
abstract class GlobalSchemaHandler extends BaseHandlerWithClient {
	private string $requestUser = '';

	final public function setRequestUser(string $user): void {
		$this->requestUser = $user;
	}

	final public function run(): Task {
		try {
			$allowed = $this->hasUnrestrictedSchemaAccess();
		} catch (Throwable) {
			// A failed authorization request must not execute the handler.
			$allowed = false;
		}
		if (!$allowed) {
			throw GenericError::create("Permission denied for user '{$this->requestUser}'")
				->setResponseErrorCode(403);
		}
		return $this->runAuthorized();
	}

	abstract protected function runAuthorized(): Task;

	private function hasUnrestrictedSchemaAccess(): bool {
		if (ConfigManager::get('BUDDY_AUTH_TOKEN') === '') {
			// A missing token alone does not establish that auth is disabled.
			// Require a successful request without credentials to the daemon.
			$client = clone $this->manticoreClient;
			$client->setAuthToken(null);
			$client->clearDelegatedUser();
			$response = $client->sendRequest('SELECT 1 AS buddy_auth_probe');
			return self::isSuccessfulResult($response) && in_array(
				$response->getData(),
				[[['buddy_auth_probe' => 1]], [['buddy_auth_probe' => '1']]],
				true
			);
		}
		if ($this->requestUser === '') {
			return false;
		}

		// Use the delegated client, never the system client. SHOW PERMISSIONS
		// returns all users' rules for ADMIN, so match the original user.
		$response = $this->manticoreClient->sendRequest('SHOW PERMISSIONS');
		if (!self::isSuccessfulResult($response)) {
			return false;
		}

		return $this->hasGlobalSchemaGrant($response->getData());
	}

	private static function isSuccessfulResult(Response $response): bool {
		return !$response->hasError() && !$response->isRaw()
			&& !$response->hasMultipleRows() && $response->hasData();
	}

	/** @param array<int|string,mixed> $rows */
	private function hasGlobalSchemaGrant(array $rows): bool {
		$allowed = false;
		foreach ($rows as $row) {
			if (!is_array($row)
				|| !is_string($row['username'] ?? null)
				|| !is_string($row['action'] ?? null)
				|| !is_string($row['target'] ?? null)
				|| !in_array($row['allow'] ?? null, [0, 1, '0', '1'], true)
			) {
				return false;
			}
			if ($row['username'] !== $this->requestUser || strtolower($row['action']) !== 'schema') {
				continue;
			}
			// Match the daemon's unrestricted permission semantics: a global
			// allow is necessary and any scoped deny rules out global access.
			if (in_array($row['allow'], [0, '0'], true)) {
				return false;
			}
			$allowed = $allowed || $row['target'] === '*';
		}
		return $allowed;
	}
}
