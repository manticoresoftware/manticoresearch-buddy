<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify

  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Auth\Exception;

use Manticoresearch\Buddy\Base\Plugin\Auth\Payload;
use Manticoresearch\Buddy\Core\Error\DaemonLogError;
use Manticoresearch\Buddy\Core\Network\Request;

final class AuthError extends DaemonLogError {
	private const CHANNEL = 'auth';
	private const MAX_MESSAGE_LENGTH = 4096;

	/**
	 * @param string $responseError
	 * @param array<string,mixed> $context
	 * @param bool $proxyOriginalError
	 * @return self
	 */
	public static function createForAuth(
		string $responseError,
		array $context = [],
		bool $proxyOriginalError = false
	): self {
		$logMessage = self::buildLogMessage($responseError, $context);
		return self::createWithLog(
			$responseError,
			$logMessage,
			self::CHANNEL,
			'ERROR',
			$proxyOriginalError
		);
	}

	/**
	 * @param Request $request
	 * @param string $responseError
	 * @param bool $proxyOriginalError
	 * @param array<string,mixed> $context
	 * @return self
	 */
	public static function createFromRequest(
		Request $request,
		string $responseError,
		bool $proxyOriginalError = false,
		array $context = []
	): self {
		$context += [
			'request_id' => $request->id,
			'acting_user' => $request->user,
			'command' => $request->command,
			'query' => self::redactQuery($request->payload),
		];

		return self::createForAuth($responseError, $context, $proxyOriginalError);
	}

	/**
	 * @param Payload $payload
	 * @param string $responseError
	 * @param bool $proxyOriginalError
	 * @param array<string,mixed> $context
	 * @return self
	 */
	public static function createFromPayload(
		Payload $payload,
		string $responseError,
		bool $proxyOriginalError = false,
		array $context = []
	): self {
		$context += [
			'request_id' => $payload->requestId,
			'operation' => isset($payload->type) ? $payload->type : null,
			'acting_user' => isset($payload->actingUser) ? $payload->actingUser : null,
			'username' => $payload->username,
			'action' => isset($payload->action) ? $payload->action : null,
			'target' => isset($payload->target) ? $payload->target : null,
			'has_budget' => $payload->budget !== null,
			'query' => $payload->getRedactedQuery(),
		];

		return self::createForAuth($responseError, $context, $proxyOriginalError);
	}

	private static function redactQuery(string $query): string {
		$query = preg_replace("/(IDENTIFIED\\s+BY\\s+)'[^']*'/iu", "$1'***'", $query) ?? $query;
		$query = preg_replace("/(SET\\s+PASSWORD\\s+)'[^']*'/iu", "$1'***'", $query) ?? $query;
		return $query;
	}

	/**
	 * @param string $message
	 * @param array<string,mixed> $context
	 * @return string
	 */
	private static function buildLogMessage(string $message, array $context): string {
		$parts = [$message];

		$operation = $context['operation'] ?? null;
		if (is_string($operation) && $operation !== '') {
			$parts[] = "op=$operation";
		}

		$actingUser = $context['acting_user'] ?? null;
		if (is_string($actingUser) && $actingUser !== '') {
			$parts[] = "user=$actingUser";
		}

		$username = $context['username'] ?? null;
		if (is_string($username) && $username !== '') {
			$parts[] = "target_user=$username";
		}

		$query = $context['query'] ?? null;
		if (is_string($query) && $query !== '') {
			$parts[] = 'query="' . $query . '"';
		}

		$requestId = $context['request_id'] ?? null;
		if (is_string($requestId) && $requestId !== '') {
			$parts[] = "rid=$requestId";
		}

		$result = implode(' ', $parts);
		if (strlen($result) > self::MAX_MESSAGE_LENGTH) {
			return substr($result, 0, self::MAX_MESSAGE_LENGTH);
		}
		return $result;
	}
}
