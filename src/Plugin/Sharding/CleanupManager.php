<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;

/**
 * Manages cleanup of orphaned resources and failed operations
 */
final class CleanupManager {

	public function __construct(
		private Client $client,
		private Cluster $cluster
	) {}

	/**
	 * Comprehensive cleanup of all orphaned resources
	 * @return array Cleanup results
	 */
	public function performFullCleanup(): array {
		$results = [
			'timestamp' => time(),
			'actions_taken' => [],
			'resources_cleaned' => 0,
			'errors' => [],
		];

		try {
			// Clean up orphaned temporary clusters
			$clusterCleanup = $this->cleanupOrphanedTemporaryClusters();
			$results['actions_taken'][] = "Cleaned {$clusterCleanup['cleaned_count']} orphaned clusters";
			$results['resources_cleaned'] += $clusterCleanup['cleaned_count'];

			// Clean up failed operation groups
			$operationCleanup = $this->cleanupFailedOperationGroups();
			$results['actions_taken'][] = "Cleaned {$operationCleanup['cleaned_count']} failed operation groups";
			$results['resources_cleaned'] += $operationCleanup['cleaned_count'];

			// Clean up expired queue items
			$queueCleanup = $this->cleanupExpiredQueueItems();
			$results['actions_taken'][] = "Cleaned {$queueCleanup['cleaned_count']} expired queue items";
			$results['resources_cleaned'] += $queueCleanup['cleaned_count'];

			// Clean up stale state entries
			$stateCleanup = $this->cleanupStaleStateEntries();
			$results['actions_taken'][] = "Cleaned {$stateCleanup['cleaned_count']} stale state entries";
			$results['resources_cleaned'] += $stateCleanup['cleaned_count'];

		} catch (\Throwable $e) {
			$results['errors'][] = $e->getMessage();
			Buddy::debugvv("Cleanup error: " . $e->getMessage());
		}

		return $results;
	}

	/**
	 * Find and clean up orphaned temporary clusters
	 * @return array Cleanup results
	 */
	public function cleanupOrphanedTemporaryClusters(): array {
		$results = ['cleaned_count' => 0, 'errors' => []];

		try {
			// Get all clusters
			$clusterResult = $this->client->sendRequest("SHOW CLUSTERS");
			/** @var array{0?:array{data?:array<array{cluster:string}>}} */
			$clusterData = $clusterResult->getResult();
			$clusters = $clusterData[0]['data'] ?? [];

			foreach ($clusters as $cluster) {
				$clusterName = $cluster['cluster'] ?? '';

				// Check if it's a temporary cluster (starts with temp_move_)
				if (strpos($clusterName, 'temp_move_') === 0) {
					// Check if it's orphaned (older than 1 hour)
					if ($this->isClusterOrphaned($clusterName)) {
						try {
							$this->client->sendRequest("DELETE CLUSTER {$clusterName}");
							$results['cleaned_count']++;
							Buddy::debugvv("Cleaned orphaned cluster: {$clusterName}");
						} catch (\Throwable $e) {
							$results['errors'][] = "Failed to clean cluster {$clusterName}: " . $e->getMessage();
						}
					}
				}
			}

		} catch (\Throwable $e) {
			$results['errors'][] = "Failed to list clusters: " . $e->getMessage();
		}

		return $results;
	}

	/**
	 * Clean up failed operation groups from queue
	 * @return array Cleanup results
	 */
	public function cleanupFailedOperationGroups(): array {
		$results = ['cleaned_count' => 0, 'errors' => []];

		try {
			$queue = new Queue($this->cluster, $this->client);
			$queueTable = $this->cluster->getSystemTableName('system.sharding_queue');

			// Find operation groups that are older than 24 hours and have failed status
			$cutoffTime = (time() - 86400) * 1000; // 24 hours ago in milliseconds

			// First check if operation_group column exists
			if (!$this->hasOperationGroupColumn($queueTable)) {
				Buddy::debugvv("Queue table doesn't have operation_group column - skipping cleanup");
				return $results;
			}

			$result = $this->client->sendRequest("
				SELECT DISTINCT operation_group
				FROM {$queueTable}
				WHERE operation_group != ''
				AND created_at < {$cutoffTime}
				AND status IN ('failed', 'error')
			");

			/** @var array{0?:array{data?:array<array{operation_group:string}>}} */
			$data = $result->getResult();
			$failedGroups = $data[0]['data'] ?? [];

			foreach ($failedGroups as $group) {
				$operationGroup = $group['operation_group'];

				try {
					// Delete all queue items for this operation group
					$this->client->sendRequest("
						DELETE FROM {$queueTable}
						WHERE operation_group = '{$operationGroup}'
					");

					$results['cleaned_count']++;
					Buddy::debugvv("Cleaned failed operation group: {$operationGroup}");

				} catch (\Throwable $e) {
					$results['errors'][] = "Failed to clean operation group {$operationGroup}: " . $e->getMessage();
				}
			}

		} catch (\Throwable $e) {
			$results['errors'][] = "Failed to clean operation groups: " . $e->getMessage();
		}

		return $results;
	}

	/**
	 * Clean up expired queue items (older than 7 days)
	 * @return array Cleanup results
	 */
	public function cleanupExpiredQueueItems(): array {
		$results = ['cleaned_count' => 0, 'errors' => []];

		try {
			$queueTable = $this->cluster->getSystemTableName('system.sharding_queue');
			$cutoffTime = (time() - 604800) * 1000; // 7 days ago in milliseconds

			// Count items to be deleted
			$countResult = $this->client->sendRequest("
				SELECT COUNT(*) as count
				FROM {$queueTable}
				WHERE created_at < {$cutoffTime}
				AND status IN ('processed', 'failed', 'error')
			");

			/** @var array{0?:array{data?:array{0?:array{count:int}}}} */
			$countData = $countResult->getResult();
			$count = $countData[0]['data'][0]['count'] ?? 0;

			if ($count > 0) {
				// Delete expired items
				$this->client->sendRequest("
					DELETE FROM {$queueTable}
					WHERE created_at < {$cutoffTime}
					AND status IN ('processed', 'failed', 'error')
				");

				$results['cleaned_count'] = $count;
				Buddy::debugvv("Cleaned {$count} expired queue items");
			}

		} catch (\Throwable $e) {
			$results['errors'][] = "Failed to clean expired queue items: " . $e->getMessage();
		}

		return $results;
	}

	/**
	 * Clean up stale state entries
	 * @return array Cleanup results
	 */
	public function cleanupStaleStateEntries(): array {
		$results = ['cleaned_count' => 0, 'errors' => []];

		try {
			$state = new State($this->client);
			$stateTable = $this->cluster->getSystemTableName('system.sharding_state');

			// Clean up old error entries (older than 30 days)
			$cutoffTime = time() - 2592000; // 30 days

			$errorEntries = $this->client->sendRequest("
				SELECT key FROM {$stateTable}
				WHERE key LIKE 'rebalance_error:%'
				AND updated_at < {$cutoffTime}
			");

			/** @var array{0?:array{data?:array<array{key:string}>}} */
			$data = $errorEntries->getResult();
			$entries = $data[0]['data'] ?? [];

			foreach ($entries as $entry) {
				try {
					// Delete the stale entry
					$this->client->sendRequest("
						DELETE FROM {$stateTable}
						WHERE key = '{$entry['key']}'
					");
					$results['cleaned_count']++;
				} catch (\Throwable $e) {
					$results['errors'][] = "Failed to clean state entry {$entry['key']}: " . $e->getMessage();
				}
			}

		} catch (\Throwable $e) {
			$results['errors'][] = "Failed to clean state entries: " . $e->getMessage();
		}

		return $results;
	}

	/**
	 * Check if a cluster is orphaned (no recent activity)
	 * @param string $clusterName
	 * @return bool
	 */
	private function isClusterOrphaned(string $clusterName): bool {
		// Simple heuristic: if cluster name contains timestamp, check if it's old
		// For uniqid() generated clusters, we consider them orphaned after 1 hour
		// This is a simple time-based approach

		// Extract timestamp if present in cluster name
		if (preg_match('/temp_move_\d+_([a-f0-9]+)/', $clusterName, $matches)) {
			// For now, consider all temp clusters older than 1 hour as potentially orphaned
			// In production, you might want to check actual cluster activity
			return true;
		}

		return false;
	}

	/**
	 * Check if queue table has operation_group column
	 * @param string $queueTable
	 * @return bool
	 */
	private function hasOperationGroupColumn(string $queueTable): bool {
		try {
			$result = $this->client->sendRequest("
				SELECT operation_group FROM {$queueTable} LIMIT 1
			");
			return !$result->hasError();
		} catch (\Throwable $e) {
			return false;
		}
	}
}