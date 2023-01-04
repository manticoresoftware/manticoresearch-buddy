<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

use Manticoresearch\BuddyTest\Trait\TestFunctionalTrait;
use PHPUnit\Framework\TestCase;

class MetricThreadTest extends TestCase {

	const SEARCHD_LOG_PATH = '/var/log/manticore-test/searchd.log';

	use TestFunctionalTrait;

	protected static string $configFileName = 'manticore-debug.conf';

	public function testMetricThreadPrintDebugMessages(): void {
		sleep(2);
		$labels = (string)system('tail -n 100 ' . static::SEARCHD_LOG_PATH . ' | grep ^labels');
		$this->assertStringContainsString('"collector":"buddy"', $labels);
		$this->assertStringContainsString('"buddy_version"', $labels);
		$this->assertStringContainsString('"manticore_version"', $labels);
		$this->assertStringContainsString('"manticore_binlog_enabled"', $labels);
		$this->assertStringContainsString('"manticore_binlog_enabled"', $labels);
		$this->assertStringContainsString('"manticore_secondary_indexes_enabled"', $labels);
	}
}
