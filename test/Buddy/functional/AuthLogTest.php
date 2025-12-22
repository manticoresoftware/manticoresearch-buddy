<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

final class AuthLogTest extends TestCase {
	use TestFunctionalTrait;

	protected static function getConfigFileName(): string {
		return 'manticore-auth.conf';
	}


	/**
	 * @param array<int,string> $paths
	 * @return void
	 */
	private static function removeAuthLogFiles(array $paths): void {
		foreach ($paths as $path) {
			if (!str_ends_with($path, '.auth')) {
				continue;
			}
			// Don't unlink: searchd keeps an open FD and would continue writing to the removed inode.
			// Truncate/create instead, so we can reliably tail the same file path.
			file_put_contents($path, '');
		}
	}

	/**
	 * @param array<int,string> $paths
	 * @param string $needle
	 * @return string
	 */
	private static function waitForLogMessage(array $paths, string $needle): string {
		for ($i = 0; $i < 30; $i++) {
			foreach ($paths as $path) {
				if (!is_file($path)) {
					continue;
				}
				$data = (string)file_get_contents($path);
				if (!str_contains($data, $needle)) {
					continue;
				}
				return $data;
			}
			usleep(100000);
		}

		return '';
	}

	public function testAuthLogIsWrittenFromBuddyLogEntity(): void {
		$logPaths = [
			'/var/log/manticore-test/searchd.log.auth',
			'/var/log/manticore-test/searchd.log',
			'/var/log/manticore/searchd.log.auth',
		];
		static::removeAuthLogFiles($logPaths);

		// First, verify Buddy produces a log entity in the protocol response.
		$marker = uniqid('buddy_authlog_', true);
		$query = "CREATE USER 'john_$marker' IDENTIFIED BY 'secret' EXTRA";
		$originalError = 'P03: syntax error, unexpected tablename, expecting ' .
			"CLUSTER or FUNCTION or PLUGIN or TABLE near 'USER";
		$buddyResponse = static::runHttpBuddyRequest($query, ['message' => $originalError]);
		$this->assertArrayHasKey('log', $buddyResponse);

		// Then, try to observe it in Manticore logs (requires daemon-side support).
		// Make a failed auth attempt to force auth log file creation.
		$port = static::getListenHttpPort();
		$discard = [];
		$forceAuthLog = "curl -s 127.0.0.1:$port/sql?mode=raw -H 'Content-type: application/x-www-form-urlencoded' " .
			'--data-binary query=SHOW%20TABLES 2>/dev/null';
		exec(
			$forceAuthLog,
			$discard
		);

		$error = 'Invalid payload: Does not match CREATE USER or DROP USER command.';
		static::runHttpQuery($query);
		$contents = static::waitForLogMessage($logPaths, $marker);
		$this->assertNotSame('', $contents, 'Expected auth log entry was not found in any configured auth log file.');

		$this->assertStringContainsString($error, $contents);
		$this->assertStringContainsString($marker, $contents);
	}
}
