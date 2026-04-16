<?php declare(strict_types=1);

/*
  Copyright (c) 2025, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Dynamic threshold manager for expansion intent.
 */
class DynamicThresholdManager {
	private const MAX_EXPANSIONS = 5;
	private const MAX_EXPANSION_PERCENT = 0.2; // 20% maximum expansion
	private const EXPANSION_STEPS = 4;

	/**
	 * Calculate dynamic threshold based on classified intent and previous expansion count.
	 *
	 * @param string $intent
	 * @param int $consecutiveExpansionCount
	 * @param float $baseThreshold
	 * @return array{threshold: float, expansion_level: int, is_expanded: bool, max_threshold: float,
	 *               expansion_percent: float, expansion_limit_reached: bool}
	 */
	public function calculateDynamicThreshold(
		string $intent,
		int $consecutiveExpansionCount,
		float $baseThreshold = 0.8
	): array {
		$wantsExpansion = $intent === Intent::EXPAND;

		Buddy::debugv("\nRAG: [DEBUG DYNAMIC THRESHOLD]");
		Buddy::debugv('RAG: ├─ Wants expansion: ' . ($wantsExpansion ? 'YES' : 'NO'));

		if (!$wantsExpansion) {
			Buddy::debugv("RAG: ├─ Using base threshold: {$baseThreshold}");
			Buddy::debugv('RAG: └─ Expansion count reset to 0');

			return [
				'threshold' => $baseThreshold,
				'expansion_level' => 0,
				'is_expanded' => false,
				'max_threshold' => $baseThreshold,
				'expansion_percent' => 0,
				'expansion_limit_reached' => false,
			];
		}

		$expansionCount = $consecutiveExpansionCount;
		$maxThreshold = $baseThreshold * (1 + self::MAX_EXPANSION_PERCENT);

		// Check if we've hit maximum expansions
		if ($expansionCount >= self::MAX_EXPANSIONS) {
			return [
				'threshold' => $maxThreshold,
				'expansion_level' => $expansionCount,
				'is_expanded' => true,
				'max_threshold' => $maxThreshold,
				'expansion_percent' => round((($maxThreshold - $baseThreshold) / $baseThreshold) * 100, 1),
				'expansion_limit_reached' => true,
			];
		}

		$expansionCount++;

		// Calculate step size: divide expansion range into 4 steps (original logic)
		$expansionRange = $maxThreshold - $baseThreshold;
		$step = $expansionRange / self::EXPANSION_STEPS;

		// Progressive expansion with calculated step (original logic)
		$threshold = min($baseThreshold + ($expansionCount * $step), $maxThreshold);

		Buddy::debugv("RAG: ├─ Expansion level: {$expansionCount} / " . self::MAX_EXPANSIONS);
		Buddy::debugv("RAG: ├─ Base threshold: {$baseThreshold}");
		Buddy::debugv("RAG: ├─ Max threshold: {$maxThreshold} (+" . (self::MAX_EXPANSION_PERCENT * 100) . '%)');
		Buddy::debugv("RAG: ├─ Step size: {$step}");
		Buddy::debugv("RAG: ├─ Calculated threshold: {$threshold}");
		Buddy::debugv(
			'RAG: └─ Expansion percent: ' .
			round((($threshold - $baseThreshold) / $baseThreshold) * 100, 1) . '%'
		);

		return [
			'threshold' => $threshold,
			'expansion_level' => $expansionCount,
			'is_expanded' => true,
			'max_threshold' => $maxThreshold,
			'expansion_percent' => round((($threshold - $baseThreshold) / $baseThreshold) * 100, 1),
			'expansion_limit_reached' => $expansionCount >= self::MAX_EXPANSIONS,
		];
	}
}
