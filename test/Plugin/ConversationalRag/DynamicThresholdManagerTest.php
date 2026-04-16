<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\DynamicThresholdManager;
use Manticoresearch\Buddy\Base\Plugin\ConversationalRag\Intent;
use PHPUnit\Framework\TestCase;

class DynamicThresholdManagerTest extends TestCase {
	public function testCalculateDynamicThresholdNoExpansion(): void {
		$thresholdManager = new DynamicThresholdManager();

		$result = $thresholdManager->calculateDynamicThreshold(Intent::NEW, 0, 0.8);

		$this->assertEquals(0.8, $result['threshold']);
		$this->assertEquals(0, $result['expansion_level']);
		$this->assertFalse($result['is_expanded']);
		$this->assertEquals(0, $result['expansion_percent']);
	}

	public function testCalculateDynamicThresholdWithExpansion(): void {
		$thresholdManager = new DynamicThresholdManager();

		$result = $thresholdManager->calculateDynamicThreshold(Intent::EXPAND, 0, 0.8);

		$this->assertGreaterThan(0.8, $result['threshold']);
		$this->assertEquals(1, $result['expansion_level']);
		$this->assertTrue($result['is_expanded']);
		$this->assertGreaterThan(0, $result['expansion_percent']);
	}

	public function testCalculateDynamicThresholdMaxExpansion(): void {
		$thresholdManager = new DynamicThresholdManager();

		$result = $thresholdManager->calculateDynamicThreshold(Intent::EXPAND, 4, 0.8);

		$this->assertEquals(5, $result['expansion_level']);
		$this->assertTrue($result['expansion_limit_reached']);
		$this->assertEquals(0.8 * 1.2, $result['max_threshold']);
	}

	public function testExpansionStateResetOnTopicChange(): void {
		$thresholdManager = new DynamicThresholdManager();

		$result = $thresholdManager->calculateDynamicThreshold(Intent::NEW, 3, 0.8);

		$this->assertEquals(0, $result['expansion_level']);
		$this->assertFalse($result['is_expanded']);
		$this->assertEquals(0.8, $result['threshold']);
	}
}
