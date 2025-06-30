# Production Considerations and Monitoring

This section covers important considerations for deploying and operating the Manticore Buddy Sharding system in production environments, including performance characteristics, monitoring requirements, and operational procedures.

## Performance Characteristics

### Resource Usage

#### Memory Usage
- **Minimal Additional Memory**: The sharding system adds minimal memory overhead
- **State Storage**: Small key-value pairs in `system.sharding_state` table
- **Queue Operations**: Lightweight command structures
- **Temporary Clusters**: Short-lived, minimal resource consumption

#### CPU Usage
- **Asynchronous Processing**: Operations are queued and processed asynchronously
- **Minimal Main Thread Blocking**: Queue processing doesn't block main application threads
- **Algorithm Efficiency**: O(n) complexity for most distribution algorithms where n = number of nodes

#### Network Usage
- **Efficient Replication**: Uses existing Manticore cluster replication mechanisms
- **Minimal Overhead**: Only coordination traffic between nodes
- **Batch Operations**: Commands are batched where possible

#### Storage Usage
- **RF=1**: No additional storage overhead (single copy)
- **RF=2**: 2x storage requirement (double copy)
- **RF>=3**: N x storage requirement (N copies)

### Performance Optimization

#### Queue Processing Optimization

```php
// Configure queue processing frequency
class QueueConfig {
    public static function getOptimalProcessingInterval(): int {
        $nodeCount = Cluster::getActiveNodeCount();

        // Adjust processing frequency based on cluster size
        if ($nodeCount <= 3) {
            return 1; // Process every second for small clusters
        } elseif ($nodeCount <= 10) {
            return 2; // Process every 2 seconds for medium clusters
        } else {
            return 5; // Process every 5 seconds for large clusters
        }
    }
}
```

#### Concurrent Operations Configuration

```php
// Allow multiple table rebalancing if resources permit
class ConcurrencyConfig {
    public static function getMaxConcurrentRebalances(): int {
        $availableMemory = self::getAvailableMemoryMB();
        $nodeCount = Cluster::getActiveNodeCount();

        // Conservative approach: 1 rebalance per 4GB RAM, max based on nodes
        $memoryBasedLimit = max(1, intval($availableMemory / 4096));
        $nodeBasedLimit = max(1, intval($nodeCount / 3));

        return min($memoryBasedLimit, $nodeBasedLimit, 5); // Max 5 concurrent
    }
}
```

## Monitoring Requirements

### Key Metrics to Monitor

#### 1. Rebalancing Operations

```php
class RebalancingMetrics {
    public function getRebalancingMetrics(): array {
        $state = new State($this->client);
        $allStates = $state->listRegex('rebalance:.*');

        $metrics = [
            'total_tables' => 0,
            'idle_tables' => 0,
            'running_operations' => 0,
            'completed_operations' => 0,
            'failed_operations' => 0,
            'average_duration' => 0,
            'longest_running' => 0,
        ];

        $runningStartTimes = [];

        foreach ($allStates as $item) {
            $metrics['total_tables']++;

            switch ($item['value']) {
                case 'idle':
                    $metrics['idle_tables']++;
                    break;
                case 'running':
                    $metrics['running_operations']++;
                    $runningStartTimes[] = $this->getOperationStartTime($item['key']);
                    break;
                case 'completed':
                    $metrics['completed_operations']++;
                    break;
                case 'failed':
                    $metrics['failed_operations']++;
                    break;
            }
        }

        // Calculate longest running operation
        if (!empty($runningStartTimes)) {
            $currentTime = time();
            $durations = array_map(fn($start) => $currentTime - $start, $runningStartTimes);
            $metrics['longest_running'] = max($durations);
        }

        return $metrics;
    }
}
```

#### 2. Queue Depth and Processing

```php
class QueueMetrics {
    public function getQueueMetrics(): array {
        $queue = new Queue($this->client);
        $allItems = $queue->getAll();

        $metrics = [
            'total_queue_depth' => count($allItems),
            'pending_commands' => 0,
            'failed_commands' => 0,
            'average_wait_time' => 0,
            'queue_depth_by_node' => [],
            'command_types' => [],
        ];

        $waitTimes = [];
        $currentTime = time();

        foreach ($allItems as $item) {
            $nodeId = $item['node'];
            $commandType = $this->extractCommandType($item['query']);

            // Count by node
            $metrics['queue_depth_by_node'][$nodeId] =
                ($metrics['queue_depth_by_node'][$nodeId] ?? 0) + 1;

            // Count by command type
            $metrics['command_types'][$commandType] =
                ($metrics['command_types'][$commandType] ?? 0) + 1;

            // Track status
            switch ($item['status'] ?? 'pending') {
                case 'pending':
                    $metrics['pending_commands']++;
                    $waitTimes[] = $currentTime - $item['created_at'];
                    break;
                case 'failed':
                    $metrics['failed_commands']++;
                    break;
            }
        }

        if (!empty($waitTimes)) {
            $metrics['average_wait_time'] = array_sum($waitTimes) / count($waitTimes);
        }

        return $metrics;
    }
}
```

#### 3. Cluster Health

```php
class ClusterHealthMetrics {
    public function getClusterHealthMetrics(): array {
        $cluster = $this->getCluster();

        $allNodes = $cluster->getNodes();
        $inactiveNodes = $cluster->getInactiveNodes();
        $activeNodes = $allNodes->diff($inactiveNodes);

        return [
            'total_nodes' => $allNodes->count(),
            'active_nodes' => $activeNodes->count(),
            'inactive_nodes' => $inactiveNodes->count(),
            'cluster_health_percentage' => $this->calculateHealthPercentage($activeNodes, $allNodes),
            'master_node' => $this->getCurrentMasterNode(),
            'cluster_hash' => Cluster::getNodesHash($activeNodes),
            'topology_changes_last_hour' => $this->getTopologyChangesCount(3600),
        ];
    }

    private function calculateHealthPercentage(Set $activeNodes, Set $allNodes): float {
        if ($allNodes->count() === 0) {
            return 100.0;
        }

        return ($activeNodes->count() / $allNodes->count()) * 100.0;
    }
}
```

### Monitoring Dashboard Example

```php
class ShardingDashboard {
    public function getDashboardData(): array {
        $rebalancingMetrics = (new RebalancingMetrics($this->client))->getRebalancingMetrics();
        $queueMetrics = (new QueueMetrics($this->client))->getQueueMetrics();
        $clusterMetrics = (new ClusterHealthMetrics($this->client))->getClusterHealthMetrics();

        return [
            'timestamp' => time(),
            'overall_status' => $this->determineOverallStatus($rebalancingMetrics, $queueMetrics, $clusterMetrics),
            'rebalancing' => $rebalancingMetrics,
            'queue' => $queueMetrics,
            'cluster' => $clusterMetrics,
            'alerts' => $this->generateAlerts($rebalancingMetrics, $queueMetrics, $clusterMetrics),
        ];
    }

    private function determineOverallStatus(array $rebalancing, array $queue, array $cluster): string {
        // Critical issues
        if ($cluster['cluster_health_percentage'] < 50) {
            return 'critical';
        }

        if ($rebalancing['failed_operations'] > 0) {
            return 'warning';
        }

        if ($rebalancing['longest_running'] > 1800) { // 30 minutes
            return 'warning';
        }

        if ($queue['total_queue_depth'] > 100) {
            return 'warning';
        }

        return 'healthy';
    }
}
```

## Alerting Configuration

### Alert Conditions

#### Critical Alerts

```php
class CriticalAlerts {
    public function checkCriticalConditions(): array {
        $alerts = [];

        // 1. Cluster health below 50%
        $clusterMetrics = (new ClusterHealthMetrics($this->client))->getClusterHealthMetrics();
        if ($clusterMetrics['cluster_health_percentage'] < 50) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'cluster_health',
                'message' => "Cluster health at {$clusterMetrics['cluster_health_percentage']}% - Only {$clusterMetrics['active_nodes']}/{$clusterMetrics['total_nodes']} nodes active",
                'action' => 'Investigate node failures immediately',
            ];
        }

        // 2. No master node
        if (empty($clusterMetrics['master_node'])) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'no_master',
                'message' => 'No master node elected',
                'action' => 'Check cluster connectivity and elect master',
            ];
        }

        // 3. Multiple failed rebalancing operations
        $rebalancingMetrics = (new RebalancingMetrics($this->client))->getRebalancingMetrics();
        if ($rebalancingMetrics['failed_operations'] >= 3) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'multiple_failures',
                'message' => "{$rebalancingMetrics['failed_operations']} rebalancing operations failed",
                'action' => 'Investigate rebalancing failures and reset if needed',
            ];
        }

        return $alerts;
    }
}
```

#### Warning Alerts

```php
class WarningAlerts {
    public function checkWarningConditions(): array {
        $alerts = [];

        // 1. Long-running rebalancing operations
        $rebalancingMetrics = (new RebalancingMetrics($this->client))->getRebalancingMetrics();
        if ($rebalancingMetrics['longest_running'] > 1800) { // 30 minutes
            $alerts[] = [
                'level' => 'warning',
                'type' => 'long_running_operation',
                'message' => "Rebalancing operation running for {$rebalancingMetrics['longest_running']} seconds",
                'action' => 'Monitor operation progress or consider reset if stuck',
            ];
        }

        // 2. High queue depth
        $queueMetrics = (new QueueMetrics($this->client))->getQueueMetrics();
        if ($queueMetrics['total_queue_depth'] > 50) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'high_queue_depth',
                'message' => "Queue depth at {$queueMetrics['total_queue_depth']} commands",
                'action' => 'Check queue processing and node connectivity',
            ];
        }

        // 3. Cluster health degraded
        $clusterMetrics = (new ClusterHealthMetrics($this->client))->getClusterHealthMetrics();
        if ($clusterMetrics['cluster_health_percentage'] < 80) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'degraded_cluster',
                'message' => "Cluster health at {$clusterMetrics['cluster_health_percentage']}%",
                'action' => 'Check inactive nodes and restore if possible',
            ];
        }

        return $alerts;
    }
}
```

## Operational Procedures

### Starting Rebalancing

```php
class RebalancingOperations {
    /**
     * Safely start rebalancing for a table
     */
    public function startRebalancing(string $tableName): array {
        try {
            $table = new Table($this->client, $this->cluster, $tableName);

            // Pre-flight checks
            $preflightResult = $this->performPreflightChecks($table);
            if (!$preflightResult['can_proceed']) {
                return [
                    'success' => false,
                    'reason' => 'preflight_failed',
                    'details' => $preflightResult,
                ];
            }

            // Check if rebalancing can start
            if (!$table->canStartRebalancing()) {
                return [
                    'success' => false,
                    'reason' => 'already_running',
                    'status' => $table->getRebalancingStatus(),
                ];
            }

            // Start rebalancing
            $queue = new Queue($this->client);
            $table->rebalance($queue);

            return [
                'success' => true,
                'message' => "Rebalancing started for table {$tableName}",
                'status' => $table->getRebalancingStatus(),
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'reason' => 'exception',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function performPreflightChecks(Table $table): array {
        $checks = [
            'table_exists' => false,
            'cluster_healthy' => false,
            'sufficient_resources' => false,
            'no_conflicting_operations' => false,
        ];

        // Check if table exists
        $checks['table_exists'] = $this->client->hasTable($table->getName());

        // Check cluster health
        $clusterMetrics = (new ClusterHealthMetrics($this->client))->getClusterHealthMetrics();
        $checks['cluster_healthy'] = $clusterMetrics['cluster_health_percentage'] >= 60;

        // Check resources (simplified)
        $checks['sufficient_resources'] = $this->checkSufficientResources();

        // Check for conflicting operations
        $rebalancingMetrics = (new RebalancingMetrics($this->client))->getRebalancingMetrics();
        $checks['no_conflicting_operations'] = $rebalancingMetrics['running_operations'] < 3;

        $canProceed = array_reduce($checks, fn($carry, $check) => $carry && $check, true);

        return [
            'can_proceed' => $canProceed,
            'checks' => $checks,
        ];
    }
}
```

### Monitoring Progress

```php
class ProgressMonitoring {
    /**
     * Monitor rebalancing progress
     */
    public function monitorProgress(string $tableName): array {
        $table = new Table($this->client, $this->cluster, $tableName);
        $queue = new Queue($this->client);

        $status = $table->getRebalancingStatus();
        $queueItems = $queue->getAll();

        // Filter queue items for this table
        $tableQueueItems = array_filter($queueItems, function($item) use ($tableName) {
            return strpos($item['query'], $tableName) !== false;
        });

        return [
            'table' => $tableName,
            'status' => $status,
            'queue_items_pending' => count($tableQueueItems),
            'estimated_completion' => $this->estimateCompletion($tableQueueItems),
            'last_activity' => $this->getLastActivity($tableName),
        ];
    }

    private function estimateCompletion(array $queueItems): ?int {
        if (empty($queueItems)) {
            return null;
        }

        // Estimate based on average command execution time
        $avgExecutionTime = 5; // seconds per command (conservative estimate)
        $pendingCommands = count($queueItems);

        return time() + ($pendingCommands * $avgExecutionTime);
    }
}
```

### Recovery from Failures

```php
class FailureRecovery {
    /**
     * Comprehensive recovery procedure
     */
    public function performRecovery(): array {
        $recoveryResults = [
            'timestamp' => time(),
            'actions_taken' => [],
            'tables_recovered' => [],
            'issues_remaining' => [],
        ];

        // Step 1: Clean up orphaned temporary clusters
        $orphanedClusters = $this->cleanupOrphanedTemporaryClusters();
        if (!empty($orphanedClusters['cleaned'])) {
            $recoveryResults['actions_taken'][] = "Cleaned up {count($orphanedClusters['cleaned'])} orphaned clusters";
        }

        // Step 2: Reset stuck operations
        $stuckTables = $this->findStuckRebalancingOperations();
        foreach ($stuckTables as $tableName) {
            try {
                $table = new Table($this->client, $this->cluster, $tableName);
                $table->resetRebalancingState();
                $recoveryResults['tables_recovered'][] = $tableName;
                $recoveryResults['actions_taken'][] = "Reset stuck operation for {$tableName}";
            } catch (\Throwable $e) {
                $recoveryResults['issues_remaining'][] = "Failed to reset {$tableName}: " . $e->getMessage();
            }
        }

        // Step 3: Attempt auto-recovery of failed operations
        $failedTables = $this->findFailedRebalancingOperations();
        foreach ($failedTables as $tableName) {
            $autoRecoveryResult = $this->attemptAutoRecovery($tableName);
            if ($autoRecoveryResult['success']) {
                $recoveryResults['tables_recovered'][] = $tableName;
                $recoveryResults['actions_taken'][] = "Auto-recovered {$tableName}";
            } else {
                $recoveryResults['issues_remaining'][] = "Failed to auto-recover {$tableName}: " . $autoRecoveryResult['error'];
            }
        }

        return $recoveryResults;
    }
}
```

## Configuration Options

### Production Configuration

```php
class ProductionConfig {
    public static function getProductionSettings(): array {
        return [
            // Rebalancing settings
            'max_concurrent_rebalances' => 2,
            'rebalance_timeout_seconds' => 3600, // 1 hour
            'queue_processing_interval_seconds' => 2,

            // Monitoring settings
            'health_check_interval_seconds' => 30,
            'metrics_retention_days' => 7,
            'alert_cooldown_seconds' => 300, // 5 minutes

            // Recovery settings
            'auto_recovery_enabled' => true,
            'max_auto_recovery_attempts' => 3,
            'recovery_backoff_seconds' => 60,

            // Resource limits
            'max_queue_depth' => 1000,
            'max_operation_duration_seconds' => 7200, // 2 hours
            'temp_cluster_cleanup_interval_seconds' => 3600, // 1 hour
        ];
    }
}
```

### Environment-Specific Settings

```php
class EnvironmentConfig {
    public static function getConfigForEnvironment(string $environment): array {
        $baseConfig = ProductionConfig::getProductionSettings();

        switch ($environment) {
            case 'development':
                return array_merge($baseConfig, [
                    'queue_processing_interval_seconds' => 1,
                    'health_check_interval_seconds' => 10,
                    'rebalance_timeout_seconds' => 600, // 10 minutes
                ]);

            case 'staging':
                return array_merge($baseConfig, [
                    'max_concurrent_rebalances' => 1,
                    'auto_recovery_enabled' => false, // Manual recovery in staging
                ]);

            case 'production':
            default:
                return $baseConfig;
        }
    }
}
```

## Backup and Recovery Procedures

### Pre-Rebalancing Backup

```php
class BackupProcedures {
    /**
     * Create backup before rebalancing
     */
    public function createPreRebalancingBackup(string $tableName): array {
        try {
            $timestamp = date('Y-m-d_H-i-s');
            $backupPath = "/backup/sharding/{$tableName}_{$timestamp}";

            // Create schema backup
            $table = new Table($this->client, $this->cluster, $tableName);
            $currentSchema = $table->getShardSchema();

            $backupData = [
                'timestamp' => time(),
                'table' => $tableName,
                'schema' => $currentSchema->toArray(),
                'cluster_hash' => $this->getClusterHash(),
                'backup_path' => $backupPath,
            ];

            // Store backup metadata
            $state = new State($this->client);
            $backupKey = "backup:{$tableName}:{$timestamp}";
            $state->set($backupKey, json_encode($backupData));

            return [
                'success' => true,
                'backup_id' => $backupKey,
                'backup_data' => $backupData,
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Restore from backup
     */
    public function restoreFromBackup(string $backupId): array {
        try {
            $state = new State($this->client);
            $backupData = $state->get($backupId);

            if (!$backupData) {
                return [
                    'success' => false,
                    'error' => 'Backup not found',
                ];
            }

            $backup = json_decode($backupData, true);
            $tableName = $backup['table'];

            // Restore schema
            $table = new Table($this->client, $this->cluster, $tableName);
            $restoredSchema = new Vector($backup['schema']);

            // Update schema in database
            $table->updateScheme($restoredSchema);

            // Recreate distributed tables
            $queue = new Queue($this->client);
            $table->createDistributedTablesFromSchema($queue, $restoredSchema);

            return [
                'success' => true,
                'table' => $tableName,
                'restored_schema' => $restoredSchema->toArray(),
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
```

## Security Considerations

### Access Control

```php
class SecurityConfig {
    public static function getSecuritySettings(): array {
        return [
            // State access control
            'state_table_access' => 'cluster_admin_only',
            'queue_table_access' => 'cluster_admin_only',

            // Command validation
            'validate_queue_commands' => true,
            'sanitize_cluster_names' => true,
            'prevent_sql_injection' => true,

            // Network security
            'require_tls_for_cluster_communication' => true,
            'validate_node_certificates' => true,

            // Audit logging
            'log_all_rebalancing_operations' => true,
            'log_state_changes' => true,
            'retention_days' => 90,
        ];
    }
}
```

### Command Sanitization

```php
class CommandSanitizer {
    public static function sanitizeClusterName(string $clusterName): string {
        // Remove any potentially dangerous characters
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $clusterName);

        // Ensure it starts with a letter or underscore
        if (!preg_match('/^[a-zA-Z_]/', $sanitized)) {
            $sanitized = 'cluster_' . $sanitized;
        }

        // Limit length
        return substr($sanitized, 0, 64);
    }

    public static function validateQueueCommand(string $command): bool {
        // List of allowed command prefixes
        $allowedPrefixes = [
            'CREATE TABLE',
            'DROP TABLE',
            'CREATE CLUSTER',
            'DELETE CLUSTER',
            'ALTER CLUSTER',
            'JOIN CLUSTER',
        ];

        foreach ($allowedPrefixes as $prefix) {
            if (stripos($command, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}
```

This comprehensive production guide ensures that the sharding system can be deployed, monitored, and maintained effectively in production environments while maintaining high availability and performance.
