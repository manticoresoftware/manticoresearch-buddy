<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ReplaceSelect\Config;
use PHPUnit\Framework\TestCase;

class ReplaceSelectConfigTest extends TestCase {

	protected function setUp(): void {
		// Clear environment variables before each test
		unset($_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE']);
		unset($_ENV['BUDDY_REPLACE_SELECT_MAX_BATCH_SIZE']);
		unset($_ENV['BUDDY_REPLACE_SELECT_LOCK_TIMEOUT']);
		unset($_ENV['BUDDY_REPLACE_SELECT_QUERY_TIMEOUT']);
		unset($_ENV['BUDDY_REPLACE_SELECT_DEBUG']);
	}

	public function testDefaultValues(): void {
		echo "\nTesting default configuration values\n";

		$this->assertEquals(1000, Config::getBatchSize());
		$this->assertEquals(10000, Config::getMaxBatchSize());
		$this->assertEquals(3600, Config::getLockTimeout());
		$this->assertFalse(Config::isDebugEnabled());
	}

	public function testEnvironmentVariables(): void {
		echo "\nTesting environment variable overrides\n";

		$_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] = '2000';
		$_ENV['BUDDY_REPLACE_SELECT_MAX_BATCH_SIZE'] = '5000';
		$_ENV['BUDDY_REPLACE_SELECT_LOCK_TIMEOUT'] = '1800';
		$_ENV['BUDDY_REPLACE_SELECT_DEBUG'] = 'true';

		$this->assertEquals(2000, Config::getBatchSize());
		$this->assertEquals(5000, Config::getMaxBatchSize());
		$this->assertEquals(1800, Config::getLockTimeout());
		$this->assertTrue(Config::isDebugEnabled());
	}

	public function testBatchSizeBounds(): void {
		echo "\nTesting batch size bounds enforcement\n";

		// Test minimum bound
		$_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] = '0';
		$this->assertEquals(1, Config::getBatchSize());

		// Test maximum bound
		$_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] = '20000';
		$_ENV['BUDDY_REPLACE_SELECT_MAX_BATCH_SIZE'] = '10000';
		$this->assertEquals(10000, Config::getBatchSize());
	}

	public function testDebugBoolean(): void {
		echo "\nTesting debug boolean conversion\n";

		// Test various true values
		$trueValues = ['true', '1', 'yes', 'on'];
		foreach ($trueValues as $value) {
			$_ENV['BUDDY_REPLACE_SELECT_DEBUG'] = $value;
			$this->assertTrue(Config::isDebugEnabled(), "Should be true for: $value");
		}

		// Test various false values
		$falseValues = ['false', '0', 'no', 'off', ''];
		foreach ($falseValues as $value) {
			$_ENV['BUDDY_REPLACE_SELECT_DEBUG'] = $value;
			$this->assertFalse(Config::isDebugEnabled(), "Should be false for: $value");
		}
	}

	public function testInvalidValues(): void {
		echo "\nTesting invalid environment values\n";

		// Test non-numeric values
		$_ENV['BUDDY_REPLACE_SELECT_BATCH_SIZE'] = 'invalid';
		$this->assertEquals(1, Config::getBatchSize()); // Should default to minimum

		$_ENV['BUDDY_REPLACE_SELECT_LOCK_TIMEOUT'] = 'invalid';
		$this->assertEquals(0, Config::getLockTimeout()); // Should cast to 0
	}
}
