<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyTest\Helper;

use Manticoresearch\Buddy\Core\ManticoreSearch\Response;

class AuthTestHelpers {

	/**
	 * Create a response for permission queries
	 *
	 * @param array<array<string,mixed>> $permissions Array of permission records
	 * @return Response
	 */
	public static function createPermissionResponse(array $permissions): Response {
		return Response::fromBody(
			(string)json_encode(
				[
				[
					'data' => $permissions,
					'columns' => [
						['Username' => 'Username'],
						['action' => 'action'],
						['Target' => 'Target'],
						['Allow' => 'Allow'],
						['Budget' => 'Budget'],
					],
					'total' => sizeof($permissions),
				],
				]
			)
		);
	}

	/**
	 * Create a response for user existence check
	 *
	 * @param bool $userExists Whether the user exists
	 * @return Response
	 */
	public static function createUserExistsResponse(bool $userExists): Response {
		return Response::fromBody(
			(string)json_encode(
				[
				[
					'data' => [['c' => $userExists ? 1 : 0]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
				],
				]
			)
		);
	}

	/**
	 * Create a response for permission existence check
	 *
	 * @param bool $permissionExists Whether the permission exists
	 * @return Response
	 */
	public static function createPermissionExistsResponse(bool $permissionExists): Response {
		return Response::fromBody(
			(string)json_encode(
				[
				[
					'data' => [['c' => $permissionExists ? 1 : 0]],
					'columns' => [['c' => 'count(*)']],
					'total' => 1,
				],
				]
			)
		);
	}

	/**
	 * Create an empty success response (for INSERT/UPDATE/DELETE operations)
	 *
	 * @return Response
	 */
	public static function createEmptySuccessResponse(): Response {
		return Response::fromBody((string)json_encode([]));
	}

	/**
	 * Create an error response
	 *
	 * @param string $errorMessage The error message
	 * @return Response
	 */
	public static function createErrorResponse(string $errorMessage): Response {
		return Response::fromBody(
			(string)json_encode(
				[
				'error' => $errorMessage,
				]
			)
		);
	}

	/**
	 * Create a response for user data queries (with hashes)
	 *
	 * @param string $salt The salt value
	 * @param string $hashesJson JSON string of password hashes
	 * @return Response
	 */
	public static function createUserDataResponse(string $salt, string $hashesJson): Response {
		return Response::fromBody(
			(string)json_encode(
				[
				[
					'data' => [['salt' => $salt, 'hashes' => $hashesJson]],
					'columns' => [
						['salt' => 'salt'],
						['hashes' => 'hashes'],
					],
					'total' => 1,
				],
				]
			)
		);
	}

	/**
	 * Create a response for empty user data (user not found)
	 *
	 * @return Response
	 */
	public static function createEmptyUserDataResponse(): Response {
		return Response::fromBody(
			(string)json_encode(
				[
				[
					'data' => [],
					'columns' => [
						['salt' => 'salt'],
						['hashes' => 'hashes'],
					],
					'total' => 0,
				],
				]
			)
		);
	}
}
