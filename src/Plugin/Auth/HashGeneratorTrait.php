<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/
namespace Manticoresearch\Buddy\Base\Plugin\Auth;

use Manticoresearch\Buddy\Core\Error\GenericError;

/**
 * Trait for generating password hashes for authentication
 */
trait HashGeneratorTrait {
	// Hash key constants
	private const PASSWORD_SHA1_KEY = 'password_sha1_no_salt';
	private const PASSWORD_SHA256_KEY = 'password_sha256';
	private const BEARER_SHA256_KEY = 'bearer_sha256';

	/**
	 * Generate password hashes with token for bearer authentication
	 *
	 * @param string $password The password to hash
	 * @param string $token The token to hash for bearer auth
	 * @param string $salt The salt to use for hashing
	 * @return string JSON-encoded hashes
	 * @throws GenericError
	 */
	private function generateHashesWithToken(string $password, string $token, string $salt): string {
		$hashes = [
			self::PASSWORD_SHA1_KEY => sha1($password),
			self::PASSWORD_SHA256_KEY => hash('sha256', $salt . $password),
			self::BEARER_SHA256_KEY => $this->generateTokenHash($token, $salt),
		];
		$hashesJson = json_encode($hashes);

		if ($hashesJson === false) {
			throw GenericError::create('Failed to encode hashes as JSON.');
		}

		return addslashes($hashesJson);
	}

	/**
	 * Update password hashes while preserving existing bearer_sha256
	 *
	 * @param string $newPassword The new password to hash
	 * @param string $salt The salt to use for hashing
	 * @param array<string, mixed> $existingHashes The existing hashes array to preserve bearer_sha256
	 * @return string JSON-encoded updated hashes
	 * @throws GenericError
	 */
	private function updatePasswordHashes(string $newPassword, string $salt, array $existingHashes): string {
		if (empty($existingHashes[self::BEARER_SHA256_KEY])) {
			throw GenericError::create('Existing bearer_sha256 hash is required for password update.');
		}

		$updatedHashes = [
			self::PASSWORD_SHA1_KEY => sha1($newPassword),
			self::PASSWORD_SHA256_KEY => hash('sha256', $salt . $newPassword),
			self::BEARER_SHA256_KEY => $existingHashes[self::BEARER_SHA256_KEY], // Preserve existing token hash
		];
		$hashesJson = json_encode($updatedHashes);

		if ($hashesJson === false) {
			throw GenericError::create('Failed to encode updated hashes as JSON.');
		}

		return addslashes($hashesJson);
	}

	/**
	 * Validate hash structure
	 *
	 * @param array<string, mixed> $hashes The hashes array to validate
	 * @throws GenericError
	 */
	private function validateHashesStructure(array $hashes): void {
		$required = [self::PASSWORD_SHA1_KEY, self::PASSWORD_SHA256_KEY, self::BEARER_SHA256_KEY];
		foreach ($required as $key) {
			if (!isset($hashes[$key]) || !is_string($hashes[$key])) {
				throw GenericError::create("Invalid hash structure: missing or invalid '{$key}'.");
			}
		}
	}

	/**
	 * Generate token hash for bearer authentication
	 *
	 * @param string $token The token to hash
	 * @param string $salt The salt to use for hashing
	 * @return string The token hash
	 */
	private function generateTokenHash(string $token, string $salt): string {
		return hash('sha256', $salt . hash('sha256', $token));
	}
}
