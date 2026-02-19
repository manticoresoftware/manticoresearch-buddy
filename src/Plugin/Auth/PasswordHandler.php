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
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

/**
 * Handles password updates for authentication plugin
 */
final class PasswordHandler extends BaseHandlerWithClient {
	use HashGeneratorTrait;

	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload The payload containing password data
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
	 * Validate password strength and constraints
	 *
	 * @param string $password The password to validate
	 * @throws GenericError
	 */
	private function validatePassword(string $password): void {
		if (empty($password)) {
			throw AuthError::createFromPayload($this->payload, 'Password cannot be empty.');
		}
		if (strlen($password) < 8) {
			throw AuthError::createFromPayload($this->payload, 'Password must be at least 8 characters long.');
		}
		if (strlen($password) > 128) {
			throw AuthError::createFromPayload($this->payload, 'Password is too long (max 128 characters).');
		}
	}

	/**
	 * Process the password update request
	 *
	 * @return TaskResult
	 * @throws GenericError
	 */
	private function processRequest(): TaskResult {
		$username = $this->payload->username ?? $this->payload->actingUser;
		$username = addslashes($username);
		$password = $this->payload->password;

		if ($password === null) {
			throw AuthError::createFromPayload($this->payload, 'Password is required for password update.');
		}

		$this->validatePassword($password);

		$userData = $this->getUserData($username);
		$existingHashes = json_decode($userData['hashes'], true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			$error = 'Failed to parse user hash data: ' . json_last_error_msg();
			throw AuthError::createFromPayload($this->payload, $error);
		}

		if (!is_array($existingHashes)) {
			throw AuthError::createFromPayload($this->payload, 'Invalid hash data format: expected array.');
		}

		// Validate hash structure before proceeding
		$this->validateHashesStructure($existingHashes);

		// Update only password hashes, preserve bearer_sha256
		$hashesJson = $this->updatePasswordHashes($password, $userData['salt'], $existingHashes);
		$this->replaceUserData($username, $userData['salt'], $hashesJson);

		return TaskResult::none();
	}

	/**
	 * Fetch current user data (salt and hashes)
	 *
	 * @param string $username The username to fetch data for
	 * @return array{salt: string, hashes: string}
	 * @throws GenericError
	 */
	private function getUserData(string $username): array {
		$tableUsers = Payload::AUTH_USERS_TABLE;
		$query = "SELECT salt, hashes FROM {$tableUsers} WHERE username = '{$username}'";
		/** @var Response $resp */
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw AuthError::createFromPayload($this->payload, (string)$resp->getError());
		}

		$result = $resp->getResult()->toArray();
		if (!is_array($result) || !isset($result[0]['data'][0])) {
			throw AuthError::createFromPayload($this->payload, "User '{$username}' does not exist.");
		}

		$userData = $result[0]['data'][0];
		if (!is_array($userData) || !isset($userData['salt']) || !isset($userData['hashes'])) {
			throw AuthError::createFromPayload($this->payload, 'Invalid user data format.');
		}

		return $userData;
	}

	/**
	 * Replace user data in the users table with new hashes
	 *
	 * @param string $username The username to update
	 * @param string $salt The existing salt
	 * @param string $hashesJson The new hashes JSON
	 * @throws GenericError
	 */
	private function replaceUserData(string $username, string $salt, string $hashesJson): void {
		$tableUsers = Payload::AUTH_USERS_TABLE;
		$query = "REPLACE INTO {$tableUsers} (username, salt, hashes) ".
			"VALUES ('{$username}', '{$salt}', '{$hashesJson}')";
		/** @var Response $resp */
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw AuthError::createFromPayload($this->payload, (string)$resp->getError());
		}
	}
}
