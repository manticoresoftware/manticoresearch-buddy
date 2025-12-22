<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Auth;

use Manticoresearch\Buddy\Base\Plugin\Auth\Exception\AuthError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

/**
 * Handles GRANT and REVOKE commands for authentication plugin
 */
final class GrantRevokeHandler extends BaseHandlerWithClient {
	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload The payload containing permission data
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request asynchronously
	 *
	 * @return Task
	 */
	public function run(): Task {
		return Task::create(
			fn () => $this->processRequest(),
			[$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * Process the GRANT or REVOKE request
	 *
	 * @return TaskResult
	 * @throws GenericError
	 */
	private function processRequest(): TaskResult {
		assert($this->payload->username !== null);
		assert($this->payload->action !== null);
		assert($this->payload->target !== null);
		$username = addslashes($this->payload->username);
		$action = addslashes($this->payload->action);
		$target = addslashes($this->payload->target);
		$budget = $this->payload->budget !== null ? addslashes($this->payload->budget) : '{}';

		if ($this->payload->type === 'grant') {
			return $this->handleGrant($username, $action, $target, $budget);
		}

		if ($this->payload->type === 'revoke') {
			return $this->handleRevoke($username, $action, $target);
		}

		throw AuthError::createFromPayload($this->payload, 'Invalid operation type for GrantRevokeHandler.');
	}

	/**
	 * Check if the user exists in the users table
	 *
	 * @param string $username The username to check
	 * @return bool
	 * @throws GenericError
	 */
	private function userExists(string $username): bool {
		$tableUsers = Payload::AUTH_USERS_TABLE;
		$query = "SELECT count(*) as c FROM {$tableUsers} WHERE username = '{$username}'";
		/** @var Response $resp */
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw AuthError::createFromPayload($this->payload, (string)$resp->getError());
		}

		$result = $resp->getResult()->toArray();
		if (!is_array($result) || !isset($result[0]['data'][0]['c'])) {
			$error = 'Unexpected response format when checking user existence.';
			throw AuthError::createFromPayload($this->payload, $error);
		}

		$count = (int)$result[0]['data'][0]['c'];
		return $count > 0;
	}

	/**
	 * Check if a permission already exists for the user
	 *
	 * @param string $username The username to check
	 * @param string $action The action to check
	 * @param string $target The target to check
	 * @return bool
	 * @throws GenericError
	 */
	private function permissionExists(string $username, string $action, string $target): bool {
		$tablePerms = Payload::AUTH_PERMISSIONS_TABLE;
		$query = "SELECT count(*) as c FROM {$tablePerms} WHERE ".
			"username = '{$username}' AND action = '{$action}' AND target = '{$target}'";
		/** @var Response $resp */
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw AuthError::createFromPayload($this->payload, (string)$resp->getError());
		}

		$result = $resp->getResult()->toArray();
		if (!is_array($result) || !isset($result[0]['data'][0]['c'])) {
			$error = 'Unexpected response format when checking permission existence.';
			throw AuthError::createFromPayload($this->payload, $error);
		}

		$count = (int)$result[0]['data'][0]['c'];
		return $count > 0;
	}

	/**
	 * Handle GRANT command by adding a permission
	 *
	 * @param string $username The username to grant permissions to
	 * @param string $action The action to grant (e.g., read, write)
	 * @param string $target The target (e.g., *, table/mytable)
	 * @param string $budget JSON-encoded budget or '{}'
	 * @return TaskResult
	 * @throws GenericError
	 */
	private function handleGrant(string $username, string $action, string $target, string $budget): TaskResult {
		if (!$this->userExists($username)) {
			throw AuthError::createFromPayload($this->payload, "User '{$username}' does not exist.");
		}

		// Check if permission already exists
		if ($this->permissionExists($username, $action, $target)) {
			throw AuthError::createFromPayload(
				$this->payload,
				"User '{$username}' already has '{$action}' permission on '{$target}'."
			);
		}

		$tablePerms = Payload::AUTH_PERMISSIONS_TABLE;
		$query = "INSERT INTO {$tablePerms} (username, action, target, allow, budget) " .
			"VALUES ('{$username}', '{$action}', '{$target}', 1, '{$budget}')";
		/** @var Response $resp */
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw AuthError::createFromPayload($this->payload, (string)$resp->getError());
		}

		return TaskResult::none();
	}

	/**
	 * Handle REVOKE command by removing a permission
	 *
	 * @param string $username The username to revoke permissions from
	 * @param string $action The action to revoke (e.g., read, write)
	 * @param string $target The target (e.g., *, table/mytable)
	 * @return TaskResult
	 * @throws GenericError
	 */
	private function handleRevoke(string $username, string $action, string $target): TaskResult {
		if (!$this->userExists($username)) {
			throw AuthError::createFromPayload($this->payload, "User '{$username}' does not exist.");
		}

		// Check if permission exists before revoking
		if (!$this->permissionExists($username, $action, $target)) {
			throw AuthError::createFromPayload(
				$this->payload,
				"User '{$username}' does not have '{$action}' permission on '{$target}'."
			);
		}

		$tablePerms = Payload::AUTH_PERMISSIONS_TABLE;
		$query = "DELETE FROM {$tablePerms} WHERE username = '{$username}' ".
			"AND action = '{$action}' AND target = '{$target}'";
		/** @var Response $resp */
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw AuthError::createFromPayload($this->payload, (string)$resp->getError());
		}

		return TaskResult::none();
	}
}
