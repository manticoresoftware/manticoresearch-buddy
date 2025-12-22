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
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

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
	public string $rawQuery = '';
	public string $requestId = '';

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
		$self = new static();
		$self->actingUser = $request->user;
		$self->rawQuery = $request->payload;
		$self->requestId = $request->id;

		[$self->type, $self->handler] = match (true) {
			self::hasCreateUser($request) => ['create', 'UserHandler'],
			self::hasDropUser($request) => ['drop', 'UserHandler'],
			self::hasGrant($request) => ['grant', 'GrantRevokeHandler'],
			self::hasRevoke($request) => ['revoke', 'GrantRevokeHandler'],
			self::hasShowMyPermissions($request) => ['show_my_permissions', 'ShowHandler'],
			self::hasSetPassword($request) => ['set_password', 'PasswordHandler'],
			default => throw AuthError::createFromRequest($request, 'Failed to handle your query', true)
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

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		return (
			self::hasCreateUser($request) ||
			self::hasDropUser($request) ||
			self::hasGrant($request) ||
			self::hasRevoke($request) ||
			self::hasShowMyPermissions($request) ||
			self::hasSetPassword($request)
		);
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasCreateUser(Request $request): bool {
		return (str_starts_with(
			$request->error,
			'P03: syntax error, unexpected tablename, '.
				"expecting CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER"
		)
			&& stripos($request->payload, 'CREATE USER') !== false);
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasDropUser(Request $request): bool {
		// More robust pattern matching for DROP USER detection
		$patterns = [
			'P03: syntax error, unexpected tablename, expecting FUNCTION or PLUGIN or TABLE near',
			'P03: syntax error, unexpected identifier near',
		];

		$matchesPattern = false;
		foreach ($patterns as $pattern) {
			if (str_starts_with($request->error, $pattern)) {
				$matchesPattern = true;
				break;
			}
		}

		return $matchesPattern && stripos($request->payload, 'DROP USER') !== false;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasGrant(Request $request): bool {
		return (str_starts_with(
			$request->error,
			"P02: syntax error, unexpected identifier near 'GRANT"
		)
			&& stripos($request->payload, 'GRANT') !== false);
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasRevoke(Request $request): bool {
		return (str_starts_with(
			$request->error,
			"P02: syntax error, unexpected identifier near 'REVOKE"
		)
			&& stripos($request->payload, 'REVOKE') !== false);
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasShowMyPermissions(Request $request): bool {
		return (str_starts_with(
			$request->error,
			'P01: syntax error, unexpected identifier, '.
				"expecting VARIABLES near 'MY PERMISSIONS'"
		)
			&& stripos($request->payload, 'SHOW MY PERMISSIONS') !== false);
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	private static function hasSetPassword(Request $request): bool {
		return (str_starts_with(
			$request->error,
			'P01: syntax error, unexpected string, '.
				"expecting '=' near"
		)
			&& stripos($request->payload, 'SET PASSWORD') !== false);
	}

	/**
	 * @throws GenericError
	 */
	private static function parseUserCommand(string $payload, self $self): void {
		$createPattern = '/^CREATE\s+USER\s+\'([^\']+)\'\s+IDENTIFIED\s+BY\s+\'([^\']+)\'$/i';
		$dropPattern = '/^DROP\s+USER\s+\'([^\']+)\'$/i';

		if (preg_match($createPattern, $payload, $matches)) {
			$self->username = $matches[1];
			$self->password = $matches[2];
		} elseif (preg_match($dropPattern, $payload, $matches)) {
			$self->username = $matches[1];
			$self->password = null;
		} else {
			$error = 'Invalid payload: Does not match CREATE USER or DROP USER command.';
			throw AuthError::createFromPayload($self, $error, true);
		}
	}

	/**
	 * @throws GenericError
	 */
	private static function parseGrantRevokeCommand(string $payload, self $self): void {
		$pattern = '/^(GRANT|REVOKE)\s+([\w]+)\s+ON\s+(\*|\'([^\']*)\')'.
			'\s+(TO|FROM)\s+\'([^\']+)\'(?:\s+WITH\s+BUDGET\s+([\'"]?\{.*?\}[\'"]?))?$/i';

		if (!preg_match($pattern, $payload, $matches)) {
			throw AuthError::createFromPayload($self, 'Invalid payload: Does not match GRANT or REVOKE command.', true);
		}

		$command = strtolower($matches[1]);
		$action = $matches[2];
		$target = $matches[3] === '*' ? '*' : $matches[4];
		$preposition = $matches[5];
		$username = $matches[6];
		$budget = isset($matches[7]) ? trim($matches[7], "'\"") : null;

		if ($command === 'grant' && strtoupper($preposition) !== 'TO') {
			throw AuthError::createFromPayload($self, 'Invalid preposition for GRANT: Must use TO.', true);
		}
		if ($command === 'revoke' && strtoupper($preposition) !== 'FROM') {
			throw AuthError::createFromPayload($self, 'Invalid preposition for REVOKE: Must use FROM.', true);
		}
		if ($command === 'revoke' && $budget !== null) {
			throw AuthError::createFromPayload($self, 'REVOKE does not support WITH BUDGET.', true);
		}

		$allowedActions = ['read', 'write', 'schema', 'admin', 'replication'];
		if (!in_array(strtolower($action), $allowedActions)) {
			throw AuthError::createFromPayload(
				$self,
				'Invalid action: Must be one of read, write, schema, admin, replication.',
				true
			);
		}

		if ($budget !== null && json_decode($budget, true) === null) {
			throw AuthError::createFromPayload($self, 'Invalid budget JSON.', true);
		}

		$self->action = $action;
		$self->target = $target;
		$self->username = $username;
		$self->budget = $budget;
	}

	/**
	 * @throws GenericError
	 */
	private static function parsePasswordCommand(string $payload, self $self): void {
		$pattern = '/^SET\s+PASSWORD\s+\'([^\']+)\'(?:\s+FOR\s+\'([^\']+)\')?$/i';

		if (!preg_match($pattern, $payload, $matches)) {
			throw AuthError::createFromPayload($self, 'Invalid payload: Does not match SET PASSWORD command.', true);
		}

		$self->username = $matches[2] ?? null;
		$self->password = $matches[1];
	}

	/**
	 * Get the handler class name
	 *
	 * @return string
	 */
	public function getHandlerClassName(): string {
		return __NAMESPACE__ . '\\' . $this->handler;
	}

	public function getRedactedQuery(): string {
		return preg_replace(
			[
				"/(IDENTIFIED\\s+BY\\s+)'[^']*'/iu",
				"/(SET\\s+PASSWORD\\s+)'[^']*'/iu",
			],
			[
				"$1'***'",
				"$1'***'",
			],
			$this->rawQuery
		) ?? $this->rawQuery;
	}
}
