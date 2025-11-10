<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\ConversationalRag;

use Exception;
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * LLM-driven dynamic threshold manager (based on original calculateDynamicThreshold)
 * Uses LLM to detect expansion intent instead of hardcoded patterns
 */
class DynamicThresholdManager {
	private const MAX_EXPANSIONS = 5;
	private const MAX_EXPANSION_PERCENT = 0.2; // 20% maximum expansion
	private const EXPANSION_STEPS = 4;

	private static array $expansionState = [];

	/**
	 * Calculate dynamic threshold based on LLM expansion intent detection
	 * Based on original calculateDynamicThreshold and detectExpansionIntent
	 *
	 * @param string $userQuery
	 * @param array $conversationHistory
	 * @param LLMProviderManager $llmProvider
	 * @param array $modelConfig
	 * @param float $baseThreshold
	 * @return array
	 */
	public function calculateDynamicThreshold(
		string $userQuery,
		string $conversationHistory,
		LLMProviderManager $llmProvider,
		array $modelConfig,
		float $baseThreshold = 0.8
	): array {
		$conversationId = $this->getConversationId($conversationHistory);

		// Reset expansion count for new conversations
		if (!isset(self::$expansionState[$conversationId])) {
			self::$expansionState[$conversationId] = [
				'count' => 0,
				'last_conversation_hash' => md5($conversationHistory),
			];
		}

		// Reset expansion count if conversation changed significantly
		$currentHash = md5($conversationHistory);
		if (self::$expansionState[$conversationId]['last_conversation_hash'] !== $currentHash) {
			self::$expansionState[$conversationId] = [
				'count' => 0,
				'last_conversation_hash' => $currentHash,
			];
		}

		$expansionCount = self::$expansionState[$conversationId]['count'];

		// Check if we've hit maximum expansions
		if ($expansionCount >= self::MAX_EXPANSIONS) {
			$maxThreshold = $baseThreshold * (1 + self::MAX_EXPANSION_PERCENT);
			return [
				'threshold' => $maxThreshold,
				'expansion_level' => $expansionCount,
				'is_expanded' => true,
				'max_threshold' => $maxThreshold,
				'expansion_percent' => round((($maxThreshold - $baseThreshold) / $baseThreshold) * 100, 1),
				'expansion_limit_reached' => true,
			];
		}

		// Detect if user wants broader search using LLM (from original detectExpansionIntent)
		$wantsExpansion = $this->detectExpansionIntent($userQuery, $conversationHistory, $llmProvider, $modelConfig);

		Buddy::info("\n[DEBUG DYNAMIC THRESHOLD]");
		Buddy::info('├─ Wants expansion: ' . ($wantsExpansion ? 'YES' : 'NO'));

		if ($wantsExpansion) {
			self::$expansionState[$conversationId]['count']++;
			$expansionCount = self::$expansionState[$conversationId]['count'];

			// Calculate maximum threshold based on percentage (original logic)
			$maxThreshold = $baseThreshold * (1 + self::MAX_EXPANSION_PERCENT);

			// Calculate step size: divide expansion range into 4 steps (original logic)
			$expansionRange = $maxThreshold - $baseThreshold;
			$step = $expansionRange / self::EXPANSION_STEPS;

			// Progressive expansion with calculated step (original logic)
			$threshold = min($baseThreshold + ($expansionCount * $step), $maxThreshold);

			Buddy::info("├─ Expansion level: {$expansionCount} / " . self::MAX_EXPANSIONS);
			Buddy::info("├─ Base threshold: {$baseThreshold}");
			Buddy::info("├─ Max threshold: {$maxThreshold} (+" . (self::MAX_EXPANSION_PERCENT * 100) . '%)');
			Buddy::info("├─ Step size: {$step}");
			Buddy::info("├─ Calculated threshold: {$threshold}");
			Buddy::info(
				'└─ Expansion percent: ' .
				round((($threshold - $baseThreshold) / $baseThreshold) * 100, 1) . '%'
			);

			return [
				'threshold' => $threshold,
				'expansion_level' => $expansionCount,
				'is_expanded' => true,
				'max_threshold' => $maxThreshold,
				'expansion_percent' => round((($threshold - $baseThreshold) / $baseThreshold) * 100, 1),
			];
		}

		// Reset on non-expansion queries (original logic)
		self::$expansionState[$conversationId]['count'] = 0;

		Buddy::info("├─ Using base threshold: {$baseThreshold}");
		Buddy::info('└─ Expansion count reset to 0');

		return [
			'threshold' => $baseThreshold,
			'expansion_level' => 0,
			'is_expanded' => false,
			'max_threshold' => $baseThreshold,
			'expansion_percent' => 0,
		];
	}

	/**
	 * Get conversation ID from history
	 *
	 * @param array $conversationHistory
	 * @return string
	 */
	private function getConversationId(string $conversationHistory): string {
		// Generate a consistent ID based on conversation content (first 200 chars for consistency)
		return md5(substr($conversationHistory, 0, 200));
	}

	/**
	 * Detect if user wants to broaden their search (expansion intent)
	 *
	 * @param string $userQuery
	 * @param string $conversationHistory
	 * @param LLMProviderManager $llmProvider
	 * @param array $modelConfig
	 * @return bool
	 */
	private function detectExpansionIntent(
		string $userQuery,
		string $conversationHistory,
		LLMProviderManager $llmProvider,
		array $modelConfig
	): bool {
		try {
			// CRITICAL: If no conversation history, cannot be expansion (from original)
			if (empty(trim($conversationHistory))) {
				Buddy::info("\n[DEBUG EXPANSION CHECK]");
				Buddy::info('├─ No conversation history');
				Buddy::info('└─ Expansion: NO (no prior results to expand from)');
				return false;
			}

			$historyText = $conversationHistory;

			// Use original expansion prompt
			$expansionPrompt = "
Analyze if the user wants to BROADEN their search beyond previously shown results.

EXPANSION CONCEPT:
- User has seen specific results before and wants MORE options beyond what was shown
- User wants to discover additional content in the same general area
- User wants to widen the search scope, not change topics or narrow focus

REQUIREMENTS for expansion:
1. Previous results must exist in conversation history
2. User wants additional options (not replacement of what was shown)
3. User wants broader scope (not more specific criteria)

This is NOT expansion:
- First requests with no prior results
- Asking for different genre/topic (that's topic change)
- Asking for more specific criteria (that's refinement)
- Questions about shown content (that's clarification)

Conversation history:
{$historyText}

Current query: {$userQuery}

Does this query request BROADENING beyond previous results?
Answer: YES or NO";

			$provider = $llmProvider->getConnection('expansion_detector', $modelConfig);
			$response = $provider->generateResponse($expansionPrompt, [], ['temperature' => 0.1, 'max_tokens' => 10]);

			if (!$response['success']) {
				return false;
			}

			$result = trim(strtolower($response['content']));

			Buddy::info("\n[DEBUG EXPANSION CHECK]");
			Buddy::info('├─ Has conversation history: YES');
			Buddy::info("├─ LLM response: {$result}");
			Buddy::info('└─ Expansion: ' . ($result === 'yes' ? 'YES' : 'NO'));

			return $result === 'yes';
		} catch (Exception $e) {
			// Fallback to false on error
			return false;
		}
	}

	/**
	 * Format conversation history for LLM prompt (conversationHistory is already formatted)
	 *
	 * @param string $conversationHistory
	 * @return string
	 */
	private function formatConversationHistory(string $conversationHistory): string {
		// Conversation history is already formatted as "role: message\nrole: message\n"
		return $conversationHistory;
	}
}
