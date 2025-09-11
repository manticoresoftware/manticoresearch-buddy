<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Auth;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\ManticoreSearch\Response;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;

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

        throw GenericError::create('Invalid operation type for GrantRevokeHandler.');
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
            throw GenericError::create($resp->getError());
        }

        $result = $resp->getResult();
        if (!isset($result[0]['data'][0]['c'])) {
            throw GenericError::create('Unexpected response format when checking user existence.');
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
        $query = "SELECT count(*) as c FROM {$tablePerms} WHERE username = '{$username}' AND action = '{$action}' AND target = '{$target}'";
        /** @var Response $resp */
        $resp = $this->manticoreClient->sendRequest($query);

        if ($resp->hasError()) {
            throw GenericError::create($resp->getError());
        }

        $result = $resp->getResult();
        if (!isset($result[0]['data'][0]['c'])) {
            throw GenericError::create('Unexpected response format when checking permission existence.');
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
            throw GenericError::create("User '{$username}' does not exist.");
        }

        // Check if permission already exists
        if ($this->permissionExists($username, $action, $target)) {
            throw GenericError::create("User '{$username}' already has '{$action}' permission on '{$target}'.");
        }

        $tablePerms = Payload::AUTH_PERMISSIONS_TABLE;
        $query = "INSERT INTO {$tablePerms} (username, action, target, allow, budget) " .
            "VALUES ('{$username}', '{$action}', '{$target}', 1, '{$budget}')";
        /** @var Response $resp */
        $resp = $this->manticoreClient->sendRequest($query);

        if ($resp->hasError()) {
            throw GenericError::create($resp->getError());
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
            throw GenericError::create("User '{$username}' does not exist.");
        }

        // Check if permission exists before revoking
        if (!$this->permissionExists($username, $action, $target)) {
            throw GenericError::create("User '{$username}' does not have '{$action}' permission on '{$target}'.");
        }

        $tablePerms = Payload::AUTH_PERMISSIONS_TABLE;
        $query = "DELETE FROM {$tablePerms} WHERE username = '{$username}' AND action = '{$action}' AND target = '{$target}'";
        /** @var Response $resp */
        $resp = $this->manticoreClient->sendRequest($query);

        if ($resp->hasError()) {
            throw GenericError::create($resp->getError());
        }

        return TaskResult::none();
    }
}
