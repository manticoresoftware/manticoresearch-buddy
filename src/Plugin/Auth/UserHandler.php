<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Auth;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Column;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

/**
 * Handles user creation and deletion for authentication plugin
 */
final class UserHandler extends BaseHandlerWithClient {
	use HashGeneratorTrait;

	private const TOKEN_BYTES = 32; // 256 bits

	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload The payload containing user data
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
	 * Process the request based on operation type
	 *
	 * @return TaskResult
	 * @throws GenericError
	 */
	private function processRequest(): TaskResult {
		$username = addslashes($this->payload->username);
		$count = $this->getUserCount($username);

		if ($this->payload->type === 'create') {
			return $this->handleCreateUser($username, $count);
		}

		if ($this->payload->type === 'drop') {
			return $this->handleDropUser($username, $count);
		}

		throw GenericError::create('Invalid operation type for UserHandler.');
	}

	/**
	 * Get the count of users with the given username
	 *
	 * @param string $username The username to check
	 * @return int
	 * @throws GenericError
	 */
	private function getUserCount(string $username): int {
		$tableUsers = Payload::AUTH_USERS_TABLE;
		$query = "SELECT count(*) as c FROM {$tableUsers} WHERE username = '{$username}'";
		/** @var Response $resp */
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw GenericError::create($resp->getError());
		}

		$result = $resp->getResult();
		if (!isset($result[0]['data'][0]['c'])) {
			throw GenericError::create('Unexpected response format when checking user existence.');
		}

		return (int)$result[0]['data'][0]['c'];
	}

	/**
	 * Validate username format and constraints
	 *
	 * @param string $username The username to validate
	 * @throws GenericError
	 */
	private function validateUsername(string $username): void {
		if (empty($username)) {
			throw GenericError::create('Username cannot be empty.');
		}
		if (strlen($username) > 64) {
			throw GenericError::create('Username is too long (max 64 characters).');
		}
		if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
			throw GenericError::create('Username contains invalid characters.');
		}
	}

	/**
	 * Generate a cryptographically secure random token
	 *
	 * @return string
	 * @throws GenericError
	 */
	private function generateToken(): string {
		try {
			return bin2hex(random_bytes(self::TOKEN_BYTES));
		} catch (Exception $e) {
			throw GenericError::create('Failed to generate secure token: ' . $e->getMessage());
		}
	}

	/**
	 * Handle user creation
	 *
	 * @param string $username The username to create
	 * @param int $count Existing user count
	 * @return TaskResult
	 * @throws GenericError
	 */
	private function handleCreateUser(string $username, int $count): TaskResult {
		$this->validateUsername($username);

		if ($count > 0) {
			throw GenericError::create("User '{$username}' already exists.");
		}

		if (!isset($this->payload->password)) {
			throw GenericError::create('Password is required for CREATE USER.');
		}

		$salt = bin2hex(random_bytes(20));
		$token = $this->generateToken();

		// Generate hashes including the token hash for bearer_sha256
		$hashesJson = $this->generateHashesWithToken($this->payload->password, $token, $salt);

		$tableUsers = Payload::AUTH_USERS_TABLE;
		$query = "INSERT INTO {$tableUsers} (username, salt, hashes) ".
			"VALUES ('{$username}', '{$salt}', '{$hashesJson}')";
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw GenericError::create($resp->getError());
		}

		// Return the generated token to the user
		return TaskResult::withRow(
			[
			'token' => $token,
			'username' => $username,
			'generated_at' => date('Y-m-d H:i:s'),
			]
		)->column('token', Column::String)
		 ->column('username', Column::String)
		 ->column('generated_at', Column::String);
	}

	/**
	 * Handle user deletion
	 *
	 * @param string $username The username to delete
	 * @param int $count Existing user count
	 * @return TaskResult
	 * @throws GenericError
	 */
	private function handleDropUser(string $username, int $count): TaskResult {
		if ($count === 0) {
			throw GenericError::create("User '{$username}' does not exist.");
		}

		$tablePerms = Payload::AUTH_PERMISSIONS_TABLE;
		$query = "DELETE FROM {$tablePerms} WHERE username = '{$username}'";
		/** @var Response $resp */
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw GenericError::create($resp->getError());
		}

		$tableUsers = Payload::AUTH_USERS_TABLE;
		$query = "DELETE FROM {$tableUsers} WHERE username = '{$username}'";
		/** @var Response $resp */
		$resp = $this->manticoreClient->sendRequest($query);

		if ($resp->hasError()) {
			throw GenericError::create($resp->getError());
		}

		return TaskResult::none();
	}
}
