<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Auth;

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
     * Process the password update request
     *
     * @return TaskResult
     * @throws GenericError
     */
    private function processRequest(): TaskResult {
        $username = $this->payload->username ?? $this->payload->actingUser;
        $username = addslashes($username);
        $password = $this->payload->password;

        if (!$password) {
            throw GenericError::create('Password is required for SET PASSWORD.');
        }

        $userData = $this->getUserData($username);
        $hashesJson = $this->generateHashes($password, $userData['salt']);
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
            throw GenericError::create($resp->getError());
        }

        $result = $resp->getResult();
        if (!isset($result[0]['data'][0])) {
            throw GenericError::create("User '{$username}' does not exist.");
        }

        return $result[0]['data'][0];
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
        $query = "REPLACE INTO {$tableUsers} (username, salt, hashes) VALUES ('{$username}', '{$salt}', '{$hashesJson}')";
        /** @var Response $resp */
        $resp = $this->manticoreClient->sendRequest($query);

        if ($resp->hasError()) {
            throw GenericError::create($resp->getError());
        }
    }
}
