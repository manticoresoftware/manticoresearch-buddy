<?php declare(strict_types=1);

/*
  Copyright (c) 2024-2025, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Auth;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload {

	const AUTH_USERS_TABLE = 'system.auth_users';
	const AUTH_PERMISSIONS_TABLE = 'system.auth_permissions';

	public string $type;
	private string $handler;

	public ?string $username = null;
	public ?string $password = null;
	public string $target;

	public string $actingUser;
	public string $action;
	public ?string $budget = null;

	/**
	 * Get a description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		/**
		 * Handle commands:
		 *
		 * CREATE USER '<username>' IDENTIFIED BY '<password>'
		 *
		 * DROP USER '<username>'
		 *
		 * GRANT <action> ON <target> TO '<username>' [WITH BUDGET <json_budget>];
		 * GRANT READ ON * TO 'readonly' WITH BUDGET '{"queries_per_day": 10000}';
		 * GRANT WRITE ON 'mytable' TO 'custom_user';
		 *
		 * REVOKE <action> ON <target> FROM '<username>';
		 * REVOKE READ ON '*' FROM 'readonly';
		 *
		 * SHOW USERS
		 * SHOW MY PERMISSIONS;
		 * SHOW PERMISSIONS;
		 *
		 * SET PASSWORD 'abcdef';
		 * SET PASSWORD 'abcdef' FOR 'justin';
		 *
		 */
		return 'Handles commands related to Authentication';
	}

	/**
	 * @param Request $request
	 * @return static
	 * @throws GenericError
	 */
	public static function fromRequest(Request $request): static {
		Buddy::debug("Parsing request: {$request->payload}, error: {$request->error}");
		$self = new static();
		$self->actingUser = $request->user;

		[$self->type, $self->handler] = match (true) {
			self::hasCreateUser($request) => ['create', 'UserHandler'],
			self::hasDropUser($request) => ['drop', 'UserHandler'],
			self::hasGrant($request) => ['grant', 'GrantRevokeHandler'],
			self::hasRevoke($request) => ['revoke', 'GrantRevokeHandler'],
			self::hasShowMyPermissions($request) => ['show_my_permissions', 'ShowHandler'],
			self::hasSetPassword($request) => ['set_password', 'PasswordHandler'],
			default => throw GenericError::create('Failed to handle your query', true)
		};

		switch ($self->handler) {
			case 'UserHandler':
				self::parseUserCommand($request->payload, $self);
				break;
			case 'GrantRevokeHandler':
				self::parseGrantRevokeCommand($request->payload, $self);
				break;
			case 'ShowHandler':
				break;
			case 'PasswordHandler':
				self::parsePasswordCommand($request->payload, $self);
				break;
		}

		Buddy::debug("Parsed request type: {$self->type}, handler: {$self->handler}");
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$result = (
			self::hasCreateUser($request) ||
			self::hasDropUser($request) ||
			self::hasGrant($request) ||
			self::hasRevoke($request) ||
			self::hasShowMyPermissions($request) ||
			self::hasSetPassword($request)
		);
		Buddy::debug("hasMatch result for query '{$request->payload}': " . ($result ? 'yes' : 'no'));
		return $result;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasCreateUser(Request $request): bool {
		$result = (str_starts_with(
				$request->error,
				'P03: syntax error, unexpected tablename, '.
				"expecting CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER"
			)
			&& stripos($request->payload, 'CREATE USER') !== false);
		Buddy::debug("hasCreateUser for query '{$request->payload}': " . ($result ? 'yes' : 'no'));
		return $result;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasDropUser(Request $request): bool {
		$result = (str_starts_with(
				$request->error,
				'P03: syntax error, unexpected tablename, '.
				"expecting FUNCTION or PLUGIN or TABLE near 'user"
			)
			&& stripos($request->payload, 'DROP USER') !== false);
		Buddy::debug("hasDropUser for query '{$request->payload}': " . ($result ? 'yes' : 'no'));
		return $result;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasGrant(Request $request): bool {
		$result = (str_starts_with(
				$request->error,
				"P02: syntax error, unexpected identifier near 'GRANT"
			)
			&& stripos($request->payload, 'GRANT') !== false);
		Buddy::debug("hasGrant for query '{$request->payload}': " . ($result ? 'yes' : 'no'));
		return $result;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasRevoke(Request $request): bool {
		$result = (str_starts_with(
				$request->error,
				"P02: syntax error, unexpected identifier near 'REVOKE"
			)
			&& stripos($request->payload, 'REVOKE') !== false);
		Buddy::debug("hasRevoke for query '{$request->payload}': " . ($result ? 'yes' : 'no'));
		return $result;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasShowMyPermissions(Request $request): bool {
		$result = (str_starts_with(
				$request->error,
				'P01: syntax error, unexpected identifier, '.
				"expecting VARIABLES near 'MY PERMISSIONS'"
			)
			&& stripos($request->payload, 'SHOW MY PERMISSIONS') !== false);
		Buddy::debug("hasShowMyPermissions for query '{$request->payload}': " . ($result ? 'yes' : 'no'));
		return $result;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasSetPassword(Request $request): bool {
		$result = (str_starts_with(
				$request->error,
				'P01: syntax error, unexpected string, '.
				"expecting '=' near"
			)
			&& stripos($request->payload, 'SET PASSWORD') !== false);
		Buddy::debug("hasSetPassword for query '{$request->payload}': " . ($result ? 'yes' : 'no'));
		return $result;
	}

	/**
	 * @throws GenericError
	 */
	private static function parseUserCommand(string $payload, self $self): void {
		$createPattern = '/^CREATE\s+USER\s+\'([^\']+)\'\s+IDENTIFIED\s+BY\s+\'([^\']+)\'$/i';
		$dropPattern = '/^DROP\s+USER\s+\'([^\']+)\'$/i';

		Buddy::debug("Parsing user command: {$payload}");
		if (preg_match($createPattern, $payload, $matches)) {
			$self->username = $matches[1];
			$self->password = $matches[2];
			Buddy::debug("Parsed CREATE USER: username={$self->username}, password length=" . strlen($self->password));
		} elseif (preg_match($dropPattern, $payload, $matches)) {
			$self->username = $matches[1];
			$self->password = null;
			Buddy::debug("Parsed DROP USER: username={$self->username}");
		} else {
			Buddy::debug("Failed to parse user command: {$payload}");
			throw GenericError::create('Invalid payload: Does not match CREATE USER or DROP USER command.', true);
		}
	}

	/**
	 * @throws GenericError
	 */
	private static function parseGrantRevokeCommand(string $payload, self $self): void {
		$pattern = '/^(GRANT|REVOKE)\s+([\w]+)\s+ON\s+(\*|\'([^\']*)\')\s+(TO|FROM)\s+\'([^\']+)\'(?:\s+WITH\s+BUDGET\s+([\'"]?\{.*?\}[\'"]?))?$/i';

		Buddy::debug("Parsing grant/revoke command: {$payload}");
		if (!preg_match($pattern, $payload, $matches)) {
			Buddy::debug("Failed to parse grant/revoke command: {$payload}");
			throw GenericError::create('Invalid payload: Does not match GRANT or REVOKE command.', true);
		}

		$command = strtolower($matches[1]);
		$action = $matches[2];
		$target = $matches[3] === '*' ? '*' : ($matches[4] ?? '');
		$preposition = $matches[5];
		$username = $matches[6];
		$budget = isset($matches[7]) ? trim($matches[7], "'\"") : null;

		if ($command === 'grant' && strtoupper($preposition) !== 'TO') {
			Buddy::debug("Invalid preposition for GRANT: {$preposition}");
			throw GenericError::create('Invalid preposition for GRANT: Must use TO.', true);
		}
		if ($command === 'revoke' && strtoupper($preposition) !== 'FROM') {
			Buddy::debug("Invalid preposition for REVOKE: {$preposition}");
			throw GenericError::create('Invalid preposition for REVOKE: Must use FROM.', true);
		}
		if ($command === 'revoke' && $budget !== null) {
			Buddy::debug("REVOKE with budget: {$budget}");
			throw GenericError::create('REVOKE does not support WITH BUDGET.', true);
		}

		$allowedActions = ['read', 'write', 'schema', 'admin', 'replication'];
		if (!in_array(strtolower($action), $allowedActions)) {
			Buddy::debug("Invalid action: {$action}");
			throw GenericError::create('Invalid action: Must be one of read, write, schema, admin, replication.', true);
		}

		if ($budget !== null && json_decode($budget, true) === null) {
			Buddy::debug("Invalid budget JSON: {$budget}");
			throw GenericError::create('Invalid budget JSON.', true);
		}

		$self->action = $action;
		$self->target = $target;
		$self->username = $username;
		$self->budget = $budget;
		Buddy::debug("Parsed GRANT/REVOKE: action={$action}, target={$target}, username={$username}, budget=" . ($budget ?? 'null'));
	}

	/**
	 * @throws GenericError
	 */
	private static function parsePasswordCommand(string $payload, self $self): void {
		$pattern = '/^SET\s+PASSWORD\s+\'([^\']+)\'(?:\s+FOR\s+\'([^\']+)\')?$/i';

		Buddy::debug("Parsing password command: {$payload}");
		if (!preg_match($pattern, $payload, $matches)) {
			Buddy::debug("Failed to parse password command: {$payload}");
			throw GenericError::create('Invalid payload: Does not match SET PASSWORD command.', true);
		}

		$self->username = $matches[2] ?? null;
		$self->password = $matches[1];
		Buddy::debug("Parsed SET PASSWORD: username=" . ($self->username ?? 'null') . ", password length=" . strlen($self->password));
	}

	/**
	 * Get the handler class name
	 *
	 * @return string
	 */
	public function getHandlerClassName(): string {
		return __NAMESPACE__ . '\\' . $this->handler;
	}
}
