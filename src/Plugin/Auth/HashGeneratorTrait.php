<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Auth;

use Manticoresearch\Buddy\Core\Error\GenericError;

/**
 * Trait for generating password hashes for authentication
 */
trait HashGeneratorTrait {
	/**
	 * Generate password hashes for storage
	 *
	 * @param string $password The password to hash
	 * @param string $salt The salt to use for hashing
	 * @return string JSON-encoded hashes
	 * @throws GenericError
	 */
	private function generateHashes(string $password, string $salt): string {
		$hashes = [
			'password_sha1_no_salt' => sha1($password),
			'password_sha256' => hash('sha256', $salt . $password),
			'bearer_sha256' => hash('sha256', $salt . hash('sha256', $password)),
		];
		$hashesJson = json_encode($hashes);

		if ($hashesJson === false) {
			throw GenericError::create('Failed to encode hashes as JSON.');
		}

		return addslashes($hashesJson);
	}
}
