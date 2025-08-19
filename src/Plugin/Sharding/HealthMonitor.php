<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Sharding;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

/**
 * Monitors sharding system health and performs auto-recovery
 */
final class HealthMonitor {

	public function __construct(
		private Client $client,
		private Cluster $cluster
	) {
	}

	/**
	 * Perform comprehensive health check
	 * @return array Health status
	 */
	public function performHealthCheck(): array {
		$health = [
			'overall_status' => 'healthy',
			'timestamp' => time(),
			'checks' => [],
			'issues' => [],
			'warnings' => [],
			'recommendations' => [],
		];

		// Check 1: Stuck rebalancing operations
		$stuckCheck = $this->checkStuckOperations();
		$health['checks']['stuck_operations'] = $stuckCheck;
		if (!empty($stuckCheck['stuck_tables'])) {
			$health['overall_status'] = 'unhealthy';
			$health['issues'][] = [
				'type' => 'stuck_operations',
				'count' => count($stuckCheck['stuck_tables']),
				'tables' => $stuckCheck['stuck_tables'],
			];
		}

		// Check 2: Failed operations
		$failedCheck = $this->checkFailedOperations();
		$health['checks']['failed_operations'] = $failedCheck;
		if (!empty($failedCheck['failed_tables'])) {
			$health['overall_status'] = 'unhealthy';
			$health['issues'][] = [
				'type' => 'failed_operations',
				'count' => count($failedCheck['failed_tables']),
				'tables' => $failedCheck['failed_tables'],
			];
		}

		// Check 3: Orphaned resources
		$orphanedCheck = $this->checkOrphanedResources();
		$health['checks']['orphaned_resources'] = $orphanedCheck;
		if ($orphanedCheck['orphaned_count'] > 0) {
			$health['warnings'][] = [
				'type' => 'orphaned_resources',
				'count' => $orphanedCheck['orphaned_count'],
				'details' => $orphanedCheck['details'],
			];
		}

		// Check 4: Queue health
		$queueCheck = $this->checkQueueHealth();
		$health['checks']['queue_health'] = $queueCheck;
		if ($queueCheck['high_depth']) {
			$health['warnings'][] = [
				'type' => 'high_queue_depth',
				'depth' => $queueCheck['depth'],
				'threshold' => $queueCheck['threshold'],
			];
		}

		// Generate recommendations
		$health['recommendations'] = $this->generateRecommendations($health);

		return $health;
	}

	/**
	 * Attempt automatic recovery for detected issues
	 * @return array Recovery results
	 */
	public function performAutoRecovery(): array {
		$results = [
			'timestamp' => time(),
			'actions_taken' => [],
			'recovered_tables' => [],
			'failed_recoveries' => [],
			'cleanup_performed' => false,
		];

		$healthCheck = $this->performHealthCheck();

		if ($healthCheck['overall_status'] === 'healthy') {
			$results['actions_taken'][] = 'No recovery needed - system is healthy';
			return $results;
		}

		// Recover stuck operations
		foreach ($healthCheck['issues'] as $issue) {
			if ($issue['type'] === 'stuck_operations') {
				foreach ($issue['tables'] as $tableName) {
					$recovery = $this->recoverStuckOperation($tableName);
					if ($recovery['success']) {
						$results['recovered_tables'][] = $tableName;
						$results['actions_taken'][] = "Recovered stuck operation for {$tableName}";
					} else {
						$results['failed_recoveries'][] = [
							'table' => $tableName,
							'error' => $recovery['error'],
						];
					}
				}
			}

			if ($issue['type'] !== 'failed_operations') {
				continue;
			}

			foreach ($issue['tables'] as $tableName) {
				$recovery = $this->recoverFailedOperation($tableName);
				if ($recovery['success']) {
					$results['recovered_tables'][] = $tableName;
					$results['actions_taken'][] = "Recovered failed operation for {$tableName}";
				} else {
					$results['failed_recoveries'][] = [
						'table' => $tableName,
						'error' => $recovery['error'],
					];
				}
			}
		}

		// Perform cleanup for warnings
		if (!empty($healthCheck['warnings'])) {
			$cleanupManager = new CleanupManager($this->client, $this->cluster);
			$cleanupResults = $cleanupManager->performFullCleanup();
			$results['cleanup_performed'] = true;
			$results['actions_taken'][] = "Performed cleanup: {$cleanupResults['resources_cleaned']} resources cleaned";
		}

		return $results;
	}

	/**
	 * Check for stuck rebalancing operations
	 * @return array Check results
	 */
	private function checkStuckOperations(): array {
		$results = ['stuck_tables' => [], 'check_time' => time()];

		try {
			$state = new State($this->client);
			$stateTable = $this->cluster->getSystemTableName('system.sharding_state');

			// Find operations running for more than 30 minutes
			$cutoffTime = time() - 1800; // 30 minutes ago

			$runningOps = $this->client->sendRequest(
				"
				SELECT key, value, updated_at
				FROM {$stateTable}
				WHERE key LIKE 'rebalance:%'
				AND value[0] = '\"running\"'
				AND updated_at < {$cutoffTime}
			"
			);

			/** @var array{0?:array{data?:array<array{key:string,value:string,updated_at:int}>}} */
			$data = $runningOps->getResult();
			$operations = $data[0]['data'] ?? [];

			foreach ($operations as $op) {
				// Extract table name from key (format: rebalance:tablename)
				if (!preg_match('/^rebalance:(.+)$/', $op['key'], $matches)) {
					continue;
				}

				$tableName = $matches[1];
				$results['stuck_tables'][] = $tableName;
			}
		} catch (\Throwable $e) {
			$results['error'] = $e->getMessage();
		}

		return $results;
	}

	/**
	 * Check for failed operations
	 * @return array Check results
	 */
	private function checkFailedOperations(): array {
		$results = ['failed_tables' => [], 'check_time' => time()];

		try {
			$state = new State($this->client);
			$stateTable = $this->cluster->getSystemTableName('system.sharding_state');

			$failedOps = $this->client->sendRequest(
				"
				SELECT key, value
				FROM {$stateTable}
				WHERE key LIKE 'rebalance:%'
				AND value[0] = '\"failed\"'
			"
			);

			/** @var array{0?:array{data?:array<array{key:string,value:string}>}} */
			$data = $failedOps->getResult();
			$operations = $data[0]['data'] ?? [];

			foreach ($operations as $op) {
				// Extract table name from key
				if (!preg_match('/^rebalance:(.+)$/', $op['key'], $matches)) {
					continue;
				}

				$tableName = $matches[1];
				$results['failed_tables'][] = $tableName;
			}
		} catch (\Throwable $e) {
			$results['error'] = $e->getMessage();
		}

		return $results;
	}

	/**
	 * Check for orphaned resources
	 * @return array Check results
	 */
	private function checkOrphanedResources(): array {
		$results = ['orphaned_count' => 0, 'details' => [], 'check_time' => time()];

		try {
			// Check for orphaned temporary clusters
			$clusterResult = $this->client->sendRequest('SHOW CLUSTERS');
			/** @var array{0?:array{data?:array<array{cluster:string}>}} */
			$data = $clusterResult->getResult();
			$clusters = $data[0]['data'] ?? [];

			foreach ($clusters as $cluster) {
				$clusterName = $cluster['cluster'] ?? '';
				if (strpos($clusterName, 'temp_move_') !== 0) {
					continue;
				}

				$results['orphaned_count']++;
				$results['details'][] = "Orphaned cluster: {$clusterName}";
			}
		} catch (\Throwable $e) {
			$results['error'] = $e->getMessage();
		}

		return $results;
	}

	/**
	 * Check queue health
	 * @return array Check results
	 */
	private function checkQueueHealth(): array {
		$results = ['depth' => 0, 'threshold' => 100, 'high_depth' => false, 'check_time' => time()];

		try {
			$queueTable = $this->cluster->getSystemTableName('system.sharding_queue');

			$depthResult = $this->client->sendRequest(
				"
				SELECT COUNT(*) as count
				FROM {$queueTable}
				WHERE status IN ('created', 'processing')
			"
			);

			/** @var array{0?:array{data?:array{0?:array{count:int}}}} */
			$data = $depthResult->getResult();
			$depth = $data[0]['data'][0]['count'] ?? 0;
			$results['depth'] = $depth;
			$results['high_depth'] = $depth > $results['threshold'];
		} catch (\Throwable $e) {
			$results['error'] = $e->getMessage();
		}

		return $results;
	}

	/**
	 * Generate recommendations based on health check
	 * @param array $health
	 * @return array Recommendations
	 */
	private function generateRecommendations(array $health): array {
		$recommendations = [];

		foreach ($health['issues'] as $issue) {
			switch ($issue['type']) {
				case 'stuck_operations':
					$recommendations[] = 'Reset stuck operations for tables: ' . implode(', ', $issue['tables']);
					break;
				case 'failed_operations':
					$recommendations[] = 'Investigate and recover failed operations for tables: ' . implode(', ', $issue['tables']);
					break;
			}
		}

		foreach ($health['warnings'] as $warning) {
			switch ($warning['type']) {
				case 'orphaned_resources':
					$recommendations[] = "Run cleanup to remove {$warning['count']} orphaned resources";
					break;
				case 'high_queue_depth':
					$recommendations[] = "Queue depth is high ({$warning['depth']}). Check for processing bottlenecks.";
					break;
			}
		}

		if (empty($recommendations)) {
			$recommendations[] = 'System is healthy - no actions needed';
		}

		return $recommendations;
	}

	/**
	 * Recover stuck operation
	 * @param string $tableName
	 * @return array Recovery result
	 */
	private function recoverStuckOperation(string $tableName): array {
		try {
			$table = new Table($this->client, $this->cluster, $tableName, '', '');
			$table->resetRebalancingState();

			return ['success' => true, 'message' => 'State reset successfully'];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Recover failed operation
	 * @param string $tableName
	 * @return array Recovery result
	 */
	private function recoverFailedOperation(string $tableName): array {
		try {
			$table = new Table($this->client, $this->cluster, $tableName, '', '');
			$table->resetRebalancingState();

			// Could also attempt to restart the operation here
			return ['success' => true, 'message' => 'Failed state cleared'];
		} catch (\Throwable $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}
}
