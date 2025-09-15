<?php declare(strict_types=1);

/*
  Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\BuddyTest\Plugin\Auth;

use Manticoresearch\Buddy\Base\Plugin\Auth\HashGeneratorTrait;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\BuddyTest\Trait\TestProtectedTrait;
use PHPUnit\Framework\TestCase;

class HashGeneratorTraitTest extends TestCase {
	use TestProtectedTrait;

	private function getTraitInstance(): object {
		/**
		 * @method string callGenerateHashesWithToken(string $password, string $token, string $salt)
		 * @method string callUpdatePasswordHashes(string $newPassword, string $salt, array $existingHashes)
		 * @method void callValidateHashesStructure(array $hashes)
		 * @method string callGenerateTokenHash(string $token, string $salt)
		 */
		return new class {
			use HashGeneratorTrait;

			// Hash key constants
			private const PASSWORD_SHA1_KEY = 'password_sha1_no_salt';
			private const PASSWORD_SHA256_KEY = 'password_sha256';
			private const BEARER_SHA256_KEY = 'bearer_sha256';

			public function callGenerateHashesWithToken(string $password, string $token, string $salt): string {
				return $this->generateHashesWithToken($password, $token, $salt);
			}

			/**
			 * @param array<string, mixed> $existingHashes
			 */
			public function callUpdatePasswordHashes(string $newPassword, string $salt, array $existingHashes): string {
				return $this->updatePasswordHashes($newPassword, $salt, $existingHashes);
			}

			/**
			 * @param array<string, mixed> $hashes
			 */
			public function callValidateHashesStructure(array $hashes): void {
				$this->validateHashesStructure($hashes);
			}

			public function callGenerateTokenHash(string $token, string $salt): string {
				return $this->generateTokenHash($token, $salt);
			}
		};
	}

	public function testGenerateHashesWithToken(): void {
		$instance = $this->getTraitInstance();
		$password = 'testpassword';
		$token = 'abcdef123456';
		$salt = 'testsalt';

		/** @phpstan-ignore-next-line */
		$result = $instance->callGenerateHashesWithToken($password, $token, $salt);
		$this->assertIsString($result);

		// Should return escaped JSON string
		$unescapedJson = stripslashes($result);
		$hashes = json_decode($unescapedJson, true);

		$this->assertIsArray($hashes);
		$this->assertArrayHasKey('password_sha1_no_salt', $hashes);
		$this->assertArrayHasKey('password_sha256', $hashes);
		$this->assertArrayHasKey('bearer_sha256', $hashes);

		// Verify hash values
		$this->assertEquals(sha1($password), $hashes['password_sha1_no_salt']);
		$this->assertEquals(hash('sha256', $salt . $password), $hashes['password_sha256']);
		$this->assertEquals(hash('sha256', $salt . hash('sha256', $token)), $hashes['bearer_sha256']);
	}

	public function testUpdatePasswordHashes(): void {
		$instance = $this->getTraitInstance();
		$newPassword = 'newpassword';
		$salt = 'testsalt';
		$existingBearerHash = 'existing_bearer_hash';

		$existingHashes = [
			'password_sha1_no_salt' => 'old_sha1',
			'password_sha256' => 'old_sha256',
			'bearer_sha256' => $existingBearerHash,
		];

		/** @phpstan-ignore-next-line */
		$result = $instance->callUpdatePasswordHashes($newPassword, $salt, $existingHashes);
		$this->assertIsString($result);

		// Should return escaped JSON string
		$unescapedJson = stripslashes($result);
		$updatedHashes = json_decode($unescapedJson, true);

		$this->assertIsArray($updatedHashes);

		// Password hashes should be updated
		$this->assertEquals(sha1($newPassword), $updatedHashes['password_sha1_no_salt']);
		$this->assertEquals(hash('sha256', $salt . $newPassword), $updatedHashes['password_sha256']);

		// Bearer hash should be preserved
		$this->assertEquals($existingBearerHash, $updatedHashes['bearer_sha256']);
	}

	public function testUpdatePasswordHashesMissingBearerHash(): void {
		$instance = $this->getTraitInstance();
		$existingHashes = [
			'password_sha1_no_salt' => 'old_sha1',
			'password_sha256' => 'old_sha256',
			// Missing bearer_sha256
		];

		try {
			/** @phpstan-ignore-next-line */
			$instance->callUpdatePasswordHashes('newpass', 'salt', $existingHashes);
			$this->fail('Expected GenericError to be thrown');
		} catch (GenericError $e) {
			$this->assertEquals('Existing bearer_sha256 hash is required for password update.', $e->getResponseError());
		}
	}

	public function testValidateHashesStructure(): void {
		$instance = $this->getTraitInstance();

		// Valid structure
		$validHashes = [
			'password_sha1_no_salt' => 'sha1_hash',
			'password_sha256' => 'sha256_hash',
			'bearer_sha256' => 'bearer_hash',
		];

		// Should not throw exception
		/** @phpstan-ignore-next-line */
		$instance->callValidateHashesStructure($validHashes);
		$this->assertTrue(true); // If we get here, validation passed
	}

	public function testValidateHashesStructureMissingKeys(): void {
		$instance = $this->getTraitInstance();

		$invalidStructures = [
			// Missing password_sha1_no_salt
			[
				'password_sha256' => 'sha256_hash',
				'bearer_sha256' => 'bearer_hash',
			],
			// Missing password_sha256
			[
				'password_sha1_no_salt' => 'sha1_hash',
				'bearer_sha256' => 'bearer_hash',
			],
			// Missing bearer_sha256
			[
				'password_sha1_no_salt' => 'sha1_hash',
				'password_sha256' => 'sha256_hash',
			],
		];

		foreach ($invalidStructures as $index => $invalidHashes) {
			try {
				/** @phpstan-ignore-next-line */
				$instance->callValidateHashesStructure($invalidHashes);
				$this->fail("Expected exception for invalid structure #$index");
			} catch (GenericError $e) {
				$this->assertStringContainsString('Invalid hash structure: missing or invalid', $e->getResponseError());
			}
		}
	}

	public function testValidateHashesStructureInvalidTypes(): void {
		$instance = $this->getTraitInstance();

		$invalidTypes = [
			[
				'password_sha1_no_salt' => 123, // Should be string
				'password_sha256' => 'sha256_hash',
				'bearer_sha256' => 'bearer_hash',
			],
			[
				'password_sha1_no_salt' => 'sha1_hash',
				'password_sha256' => null, // Should be string
				'bearer_sha256' => 'bearer_hash',
			],
			[
				'password_sha1_no_salt' => 'sha1_hash',
				'password_sha256' => 'sha256_hash',
				'bearer_sha256' => [], // Should be string
			],
		];

		foreach ($invalidTypes as $index => $invalidHashes) {
			try {
				/** @phpstan-ignore-next-line */
				$instance->callValidateHashesStructure($invalidHashes);
				$this->fail("Expected exception for invalid type #$index");
			} catch (GenericError $e) {
				$this->assertStringContainsString('Invalid hash structure: missing or invalid', $e->getResponseError());
			}
		}
	}

	public function testGenerateTokenHash(): void {
		$instance = $this->getTraitInstance();
		$token = 'test_token_123';
		$salt = 'test_salt';

		/** @phpstan-ignore-next-line */
		$result = $instance->callGenerateTokenHash($token, $salt);
		$this->assertIsString($result);

		// Should be a SHA256 hash (64 characters)
		$this->assertEquals(64, strlen($result));
		$this->assertTrue(ctype_xdigit($result)); // Should be hex string

		// Should be deterministic
		/** @phpstan-ignore-next-line */
		$result2 = $instance->callGenerateTokenHash($token, $salt);
		$this->assertEquals($result, $result2);

		// Different inputs should produce different results
		/** @phpstan-ignore-next-line */
		$result3 = $instance->callGenerateTokenHash('different_token', $salt);
		$this->assertNotEquals($result, $result3);

		/** @phpstan-ignore-next-line */
		$result4 = $instance->callGenerateTokenHash($token, 'different_salt');
		$this->assertNotEquals($result, $result4);

		// Verify the actual computation
		$expectedHash = hash('sha256', $salt . hash('sha256', $token));
		$this->assertEquals($expectedHash, $result);
	}

	public function testTokenHashConsistency(): void {
		$instance = $this->getTraitInstance();
		$password = 'password123';
		$token = 'token456';
		$salt = 'salt789';

		// Generate hashes with token
		/** @phpstan-ignore-next-line */
		$hashesJson = $instance->callGenerateHashesWithToken($password, $token, $salt);
		$hashes = json_decode(stripslashes($hashesJson), true);

		$this->assertIsArray($hashes);

		// Generate token hash separately
		/** @phpstan-ignore-next-line */
		$separateTokenHash = $instance->callGenerateTokenHash($token, $salt);

		// They should match
		$this->assertEquals($separateTokenHash, $hashes['bearer_sha256']);
	}

	public function testHashGenerationWithSpecialCharacters(): void {
		$instance = $this->getTraitInstance();

		$specialCases = [
			['password' => 'p@$$w0rd!', 'token' => 't0k3n#123', 'salt' => 's@lt&chars'],
			['password' => 'пароль', 'token' => 'токен', 'salt' => 'соль'],
			['password' => '', 'token' => '', 'salt' => ''], // Empty strings
			['password' => 'a', 'token' => 'b', 'salt' => 'c'], // Single characters
		];

		foreach ($specialCases as $case) {
			/** @phpstan-ignore-next-line */
			$result = $instance->callGenerateHashesWithToken($case['password'], $case['token'], $case['salt']);
			$this->assertIsString($result);

			$hashes = json_decode(stripslashes($result), true);
			$this->assertIsArray($hashes);
			$this->assertArrayHasKey('password_sha1_no_salt', $hashes);
			$this->assertArrayHasKey('password_sha256', $hashes);
			$this->assertArrayHasKey('bearer_sha256', $hashes);
		}
	}
}
