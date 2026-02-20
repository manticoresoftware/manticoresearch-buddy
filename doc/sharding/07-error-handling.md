# Error Handling and Recovery

The sharding system implements comprehensive error handling and recovery mechanisms to ensure system reliability and data safety during failures. This section covers error scenarios, recovery strategies, rollback mechanisms, and troubleshooting procedures.

## ALTER CLUSTER ADD TABLE Special Handling

### The Blocking Query Problem

`ALTER CLUSTER ADD TABLE` commands have unique characteristics that require special handling:

**Why Special Handling is Needed:**
- **Blocking Nature**: These commands can block for extended periods during table synchronization
- **Network-Intensive**: Large tables may take significant time to sync across cluster nodes
- **Timeout vs Success**: Query may timeout/fail but table synchronization continues in background
- **False Negatives**: Command appears to fail but tables are actually being synchronized successfully

### Implementation Strategy

The system implements a two-phase verification approach for `ALTER CLUSTER ADD TABLE` commands:

#### Phase 1: Query Execution Detection
When an `ALTER CLUSTER ADD TABLE` command returns an error:

```php
// Check if query is still running using SHOW QUERIES
if ($this->checkQueryStillRunning($query)) {
    // Query still executing - let existing retry mechanism handle it
    return 'error'; // Will be retried later
}
```

#### Phase 2: Cluster Status Verification
If query is no longer running, verify actual cluster state:

```php
// Extract cluster and table information
$clusterName = $this->extractClusterNameFromQuery($query);
$tableNames = $this->extractTableNamesFromQuery($query);

// Verify tables are actually in cluster
if ($this->cluster->verifyTablesInCluster($clusterName, $tableNames)) {
    return 'processed'; // Success despite error response
}
```

### Technical Implementation

#### Query Detection
```php
protected function isAlterClusterAddTableQuery(string $query): bool {
    return (bool)preg_match('/^\s*ALTER\s+CLUSTER\s+\w+\s+ADD\s+/i', $query);
}
```

#### Running Query Check
```php
protected function checkQueryStillRunning(string $query): bool {
    $res = $this->client->sendRequest('SHOW QUERIES')->getResult();
    
    foreach ($res[0]['data'] as $runningQuery) {
        if (stripos($runningQuery['query'], 'ALTER CLUSTER') !== false && 
            stripos($runningQuery['query'], 'ADD') !== false) {
            return true; // Still running
        }
    }
    return false;
}
```

#### Cluster Verification
```php
public function verifyTablesInCluster(string $clusterName, array $tableNames): bool {
    $clusterResult = $this->client->sendRequest('SHOW CLUSTERS');
    $data = $clusterResult->getResult();
    
    foreach ($data[0]['data'] as $cluster) {
        if ($cluster['cluster'] === $clusterName) {
            $clusterTables = array_map('trim', explode(',', $cluster['tables']));
            
            // Check if all requested tables are in cluster
            foreach ($tableNames as $tableName) {
                if (!in_array($tableName, $clusterTables)) {
                    return false;
                }
            }
            return true;
        }
    }
    return false;
}
```

### Flow Diagram

```
ALTER CLUSTER ADD TABLE executed
           ↓
    Returns error/timeout
           ↓
    Check SHOW QUERIES
           ↓
    ┌─────────────────┐
    │ Still running?  │
    └─────────────────┘
           ↓
    ┌─────────────────┐         ┌─────────────────┐
    │      YES        │         │       NO        │
    │ Return 'error'  │         │ Check cluster   │
    │ (retry later)   │         │ status          │
    └─────────────────┘         └─────────────────┘
                                        ↓
                                ┌─────────────────┐
                                │ Tables synced?  │
                                └─────────────────┘
                                        ↓
                        ┌─────────────────┐         ┌─────────────────┐
                        │      YES        │         │       NO        │
                        │ Return          │         │ Return 'error'  │
                        │ 'processed'     │         │ (actual failure)│
                        └─────────────────┘         └─────────────────┘
```

### Benefits

1. **Reliability**: Prevents false failures for long-running synchronization operations
2. **Efficiency**: Avoids unnecessary retries when tables are already synchronized
3. **Transparency**: Maintains existing queue behavior for all other command types
4. **Robustness**: Handles network timeouts and large table synchronization gracefully

### Logging and Monitoring

The system provides detailed logging for troubleshooting:

```
[DEBUG] ALTER CLUSTER ADD TABLE still running, will retry later
[DEBUG] ALTER CLUSTER ADD TABLE no longer running, verifying cluster status  
[DEBUG] Tables verified in cluster, marking as processed
[INFO] Queue query error: ALTER CLUSTER cluster1 ADD table1,table2
```

### Configuration

No additional configuration is required. The feature:
- Automatically detects `ALTER CLUSTER ADD TABLE` commands
- Uses existing retry mechanisms and timeouts
- Maintains backward compatibility with all other command types
- Integrates seamlessly with existing error handling

## Rollback System

### Overview

The sharding system now includes a comprehensive rollback mechanism that automatically reverses failed operations to maintain system consistency.

### Operation Groups

All related operations are grouped together for atomic execution:

```php
public function shard(Queue $queue, int $shardCount, int $replicationFactor = 2): Map {
    // Create unique operation group
    $operationGroup = "shard_create_{$this->name}_" . uniqid();

    try {
        // All operations in this group (rollback required)
        $queue->add($node, $createSql, $rollbackSql, $operationGroup);
        // ... more operations

        return $result;
    } catch (\Throwable $t) {
        // Automatic rollback of entire group
        $queue->rollbackOperationGroup($operationGroup);
        throw $t;
    }
}
```

### Rollback Command Handling

The system requires explicit rollback commands for all operations:

```php
// Rollback commands must be provided when queuing operations
$forwardSql = "CREATE TABLE users (id bigint, name string)";
$rollbackSql = "DROP TABLE IF EXISTS users";
$queue->add($nodeId, $forwardSql, $rollbackSql, $operationGroup);

// Common rollback patterns:
"CREATE TABLE users" → "DROP TABLE IF EXISTS users"
"CREATE CLUSTER c1" → "DELETE CLUSTER c1"
"ALTER CLUSTER c1 ADD t1" → "ALTER CLUSTER c1 DROP t1"
"JOIN CLUSTER c1" → "DELETE CLUSTER c1"
```

### Rollback Execution Flow

1. **Failure Detection**: Operation fails and throws exception
2. **Group Identification**: Find all commands in operation group
3. **Rollback Sequence**: Execute rollback commands in reverse order
4. **State Cleanup**: Reset state and mark as failed
5. **Error Reporting**: Log details for debugging

```php
protected function executeRollbackSequence(array $rollbackCommands): bool {
    foreach ($rollbackCommands as $command) {
        try {
            $this->client->sendRequest($command['rollback_query']);
            Buddy::debugvv("Rollback successful: {$command['rollback_query']}");
        } catch (\Throwable $e) {
            Buddy::debugvv("Rollback failed: " . $e->getMessage());
            // Continue with other rollback commands
        }
    }
    return true;
}
```

## Rebalancing Control

### Stop/Pause/Resume Operations

The system now provides full control over rebalancing operations:

```php
// Graceful stop - completes current operation
$result = $table->stopRebalancing(true);

// Immediate stop with rollback
$result = $table->stopRebalancing(false);

// Pause operation
$table->pauseRebalancing();

// Resume paused operation
$table->resumeRebalancing();

// Get progress
$progress = $table->getRebalancingProgress();
```

### Stop Signal Mechanism

Operations check for stop signals between steps:

```php
if ($this->checkStopSignal()) {
    $queue->rollbackOperationGroup($operationGroup);
    $state->set($rebalanceKey, 'stopped');
    $this->clearStopSignal();
    return;
}
```

## Health Monitoring

### Automatic Health Checks

The HealthMonitor performs comprehensive system checks:

```php
$monitor = new HealthMonitor($client, $cluster);
$health = $monitor->performHealthCheck();

// Health check results
[
    'overall_status' => 'healthy|unhealthy',
    'checks' => [
        'stuck_operations' => [...],
        'failed_operations' => [...],
        'orphaned_resources' => [...],
        'queue_health' => [...]
    ],
    'issues' => [...],
    'warnings' => [...],
    'recommendations' => [...]
]
```

### Auto-Recovery

The system can automatically recover from common issues:

```php
if ($health['overall_status'] !== 'healthy') {
    $recovery = $monitor->performAutoRecovery();
    // Automatic recovery actions:
    // - Reset stuck operations
    // - Clean failed operation groups
    // - Remove orphaned resources
}
```

## Resource Cleanup

### Cleanup Manager

Automatic cleanup of orphaned resources:

```php
$cleanup = new CleanupManager($client, $cluster);
$results = $cleanup->performFullCleanup();

// Cleanup actions:
// - Remove orphaned temporary clusters (>1 hour old)
// - Delete failed operation groups (>24 hours old)
// - Clean expired queue items (>7 days old)
// - Remove stale state entries (>30 days old)
```

### Cleanup Schedule

Recommended cleanup schedule:
- **Hourly**: Check for orphaned temporary clusters
- **Daily**: Clean failed operation groups
- **Weekly**: Remove expired queue items
- **Monthly**: Clean stale state entries

## Error Handling Strategy

### Exception Handling Architecture

```php
public function rebalance(Queue $queue): void {
    try {
        // Mark as running
        $state->set($rebalanceKey, 'running');

        // Perform rebalancing operations
        $this->performRebalancing($queue);

        // Mark as completed
        $state->set($rebalanceKey, 'completed');

    } catch (\Throwable $t) {
        // Mark as failed and reset state
        $state = new State($this->client);
        $rebalanceKey = "rebalance:{$this->name}";
        $state->set($rebalanceKey, 'failed');

        // Log error details
        Buddy::debugvv("Rebalancing failed for table {$this->name}: " . $t->getMessage());

        // Store error information for recovery
        $this->storeErrorInformation($t);

        // Re-throw for upstream handling
        throw $t;
    }
}
```

### Error Classification

The system categorizes errors into different types for appropriate handling:

```php
class ShardingError extends \Exception {
    const TYPE_NETWORK = 'network';
    const TYPE_TIMEOUT = 'timeout';
    const TYPE_RESOURCE = 'resource';
    const TYPE_LOGIC = 'logic';
    const TYPE_STATE = 'state';
    const TYPE_CLUSTER = 'cluster';

    private string $errorType;
    private array $context;

    public function __construct(string $message, string $type, array $context = [], ?\Throwable $previous = null) {
        parent::__construct($message, 0, $previous);
        $this->errorType = $type;
        $this->context = $context;
    }

    public function getErrorType(): string {
        return $this->errorType;
    }

    public function getContext(): array {
        return $this->context;
    }
}
```

## Recovery Scenarios

### 1. Stuck Rebalancing Operations

**Symptoms**: State shows 'running' but no progress for extended time

**Diagnosis**:
```php
public function diagnoseStuckRebalancing(string $tableName): array {
    $state = new State($this->client);
    $rebalanceKey = "rebalance:{$tableName}";
    $status = $state->get($rebalanceKey);

    if ($status !== 'running') {
        return ['status' => 'not_stuck', 'message' => 'Rebalancing is not running'];
    }

    // Check operation start time
    $infoKey = "rebalance_info:{$tableName}";
    $info = $state->get($infoKey);

    $diagnosis = [
        'status' => 'stuck',
        'table' => $tableName,
        'running_since' => null,
        'queue_depth' => 0,
        'last_activity' => null,
        'recommended_action' => 'reset',
    ];

    if ($info) {
        $infoData = json_decode($info, true);
        $diagnosis['running_since'] = $infoData['started_at'] ?? null;
        $diagnosis['last_activity'] = $infoData['last_updated'] ?? null;
    }

    // Check queue status
    $queue = new Queue($this->client);
    $queueItems = $queue->getAll();
    $diagnosis['queue_depth'] = count($queueItems);

    return $diagnosis;
}
```

**Resolution**:
```php
public function resolveStuckRebalancing(string $tableName): bool {
    try {
        $table = new Table($this->client, $this->cluster, $tableName);

        // Reset state
        $table->resetRebalancingState();

        // Clear any stuck queue items
        $this->clearStuckQueueItems($tableName);

        // Wait for state propagation
        sleep(2);

        // Retry rebalancing
        $queue = new Queue($this->client);
        $table->rebalance($queue);

        return true;
    } catch (\Throwable $e) {
        Buddy::debugvv("Failed to resolve stuck rebalancing for {$tableName}: " . $e->getMessage());
        return false;
    }
}

private function clearStuckQueueItems(string $tableName): void {
    $queue = new Queue($this->client);
    $allItems = $queue->getAll();

    foreach ($allItems as $item) {
        // Remove queue items related to this table that are older than 30 minutes
        if (strpos($item['query'], $tableName) !== false &&
            (time() - $item['created_at']) > 1800) {
            $queue->remove($item['id']);
        }
    }
}
```

### 2. Failed Rebalancing Operations

**Symptoms**: State shows 'failed'

**Diagnosis**:
```php
public function diagnoseFailedRebalancing(string $tableName): array {
    $state = new State($this->client);
    $rebalanceKey = "rebalance:{$tableName}";
    $status = $state->get($rebalanceKey);

    if ($status !== 'failed') {
        return ['status' => 'not_failed'];
    }

    // Get error information
    $errorKey = "rebalance_error:{$tableName}";
    $errorInfo = $state->get($errorKey);

    $diagnosis = [
        'status' => 'failed',
        'table' => $tableName,
        'error_message' => 'Unknown error',
        'error_type' => 'unknown',
        'failed_at' => null,
        'recovery_strategy' => 'manual',
        'can_auto_recover' => false,
    ];

    if ($errorInfo) {
        $errorData = json_decode($errorInfo, true);
        $diagnosis = array_merge($diagnosis, $errorData);
    }

    // Determine recovery strategy
    $diagnosis['recovery_strategy'] = $this->determineRecoveryStrategy($diagnosis);
    $diagnosis['can_auto_recover'] = $this->canAutoRecover($diagnosis);

    return $diagnosis;
}

private function determineRecoveryStrategy(array $errorInfo): string {
    $errorType = $errorInfo['error_type'] ?? 'unknown';

    switch ($errorType) {
        case ShardingError::TYPE_NETWORK:
        case ShardingError::TYPE_TIMEOUT:
            return 'retry_with_backoff';

        case ShardingError::TYPE_RESOURCE:
            return 'check_resources_and_retry';

        case ShardingError::TYPE_STATE:
            return 'reset_state_and_retry';

        case ShardingError::TYPE_CLUSTER:
            return 'check_cluster_health';

        case ShardingError::TYPE_LOGIC:
        default:
            return 'manual_investigation';
    }
}
```

**Resolution**:
```php
public function resolveFailedRebalancing(string $tableName): array {
    $diagnosis = $this->diagnoseFailedRebalancing($tableName);

    if ($diagnosis['status'] !== 'failed') {
        return $diagnosis;
    }

    $result = [
        'resolved' => false,
        'strategy_used' => $diagnosis['recovery_strategy'],
        'error' => null,
    ];

    try {
        switch ($diagnosis['recovery_strategy']) {
            case 'retry_with_backoff':
                $result['resolved'] = $this->retryWithBackoff($tableName);
                break;

            case 'reset_state_and_retry':
                $result['resolved'] = $this->resetStateAndRetry($tableName);
                break;

            case 'check_resources_and_retry':
                $result['resolved'] = $this->checkResourcesAndRetry($tableName);
                break;

            case 'check_cluster_health':
                $result['resolved'] = $this->checkClusterHealthAndRetry($tableName);
                break;

            default:
                $result['error'] = 'Manual investigation required';
        }
    } catch (\Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}
```

### 3. Partial Completion Scenarios

**Symptoms**: Some commands executed, others failed

**Diagnosis**:
```php
public function diagnosePartialCompletion(string $tableName): array {
    $queue = new Queue($this->client);
    $table = new Table($this->client, $this->cluster, $tableName);

    // Get current schema
    $currentSchema = $table->getShardSchema();

    // Get expected schema (what should be after rebalancing)
    $expectedSchema = $this->getExpectedSchema($tableName);

    // Compare schemas
    $differences = $this->compareSchemas($currentSchema, $expectedSchema);

    return [
        'status' => 'partial_completion',
        'table' => $tableName,
        'current_schema' => $currentSchema,
        'expected_schema' => $expectedSchema,
        'differences' => $differences,
        'recovery_actions' => $this->generateRecoveryActions($differences),
    ];
}

private function compareSchemas(Vector $current, Vector $expected): array {
    $differences = [
        'missing_shards' => [],
        'extra_shards' => [],
        'misplaced_shards' => [],
        'missing_nodes' => [],
        'extra_nodes' => [],
    ];

    // Build maps for comparison
    $currentMap = $this->buildSchemaMap($current);
    $expectedMap = $this->buildSchemaMap($expected);

    // Find differences
    foreach ($expectedMap as $node => $expectedShards) {
        $currentShards = $currentMap[$node] ?? new Set();

        $missing = $expectedShards->diff($currentShards);
        $extra = $currentShards->diff($expectedShards);

        if ($missing->count() > 0) {
            $differences['missing_shards'][$node] = $missing->toArray();
        }

        if ($extra->count() > 0) {
            $differences['extra_shards'][$node] = $extra->toArray();
        }
    }

    return $differences;
}
```

**Resolution**:
```php
public function resolvePartialCompletion(string $tableName): bool {
    $diagnosis = $this->diagnosePartialCompletion($tableName);
    $differences = $diagnosis['differences'];

    $queue = new Queue($this->client);
    $table = new Table($this->client, $this->cluster, $tableName);

    try {
        // Create missing shards
        foreach ($differences['missing_shards'] as $node => $shards) {
            foreach ($shards as $shard) {
                $sql = $table->getCreateTableShardSQL($shard);
                $queue->add($node, $sql);
            }
        }

        // Remove extra shards
        foreach ($differences['extra_shards'] as $node => $shards) {
            foreach ($shards as $shard) {
                $shardName = $table->getShardName($shard);
                $queue->add($node, "DROP TABLE IF EXISTS {$shardName}");
            }
        }

        // Recreate distributed tables
        $table->createDistributedTablesFromSchema($queue, $diagnosis['expected_schema']);

        // Reset state to allow normal operation
        $table->resetRebalancingState();

        return true;
    } catch (\Throwable $e) {
        Buddy::debugvv("Failed to resolve partial completion for {$tableName}: " . $e->getMessage());
        return false;
    }
}
```

### 4. Temporary Cluster Cleanup Issues

**Symptoms**: Temporary clusters persist after rebalancing

**Diagnosis**:
```php
public function findOrphanedTemporaryClusters(): array {
    $client = $this->client;

    // Get all clusters
    $result = $client->sendRequest("SHOW CLUSTERS");
    $clusters = $result->getResult()[0]['data'] ?? [];

    $orphanedClusters = [];

    foreach ($clusters as $cluster) {
        $clusterName = $cluster['cluster'] ?? '';

        // Check if it's a temporary cluster
        if (strpos($clusterName, 'temp_move_') === 0) {
            // Check how old it is
            $createdAt = $this->getClusterCreationTime($clusterName);
            $age = time() - $createdAt;

            // If older than 1 hour, consider it orphaned
            if ($age > 3600) {
                $orphanedClusters[] = [
                    'name' => $clusterName,
                    'age_seconds' => $age,
                    'created_at' => $createdAt,
                ];
            }
        }
    }

    return $orphanedClusters;
}
```

**Resolution**:
```php
public function cleanupOrphanedTemporaryClusters(): array {
    $orphanedClusters = $this->findOrphanedTemporaryClusters();
    $cleaned = [];
    $failed = [];

    foreach ($orphanedClusters as $cluster) {
        try {
            $clusterName = $cluster['name'];

            // Try to delete the cluster
            $this->client->sendRequest("DELETE CLUSTER {$clusterName}");
            $cleaned[] = $clusterName;

            Buddy::debugvv("Cleaned up orphaned temporary cluster: {$clusterName}");
        } catch (\Throwable $e) {
            $failed[] = [
                'cluster' => $cluster['name'],
                'error' => $e->getMessage(),
            ];

            Buddy::debugvv("Failed to clean up orphaned cluster {$cluster['name']}: " . $e->getMessage());
        }
    }

    return [
        'cleaned' => $cleaned,
        'failed' => $failed,
        'total_found' => count($orphanedClusters),
    ];
}
```

## Monitoring and Alerting

### Health Check System

```php
class ShardingHealthChecker {
    public function performHealthCheck(): array {
        $health = [
            'overall_status' => 'healthy',
            'issues' => [],
            'warnings' => [],
            'tables_checked' => 0,
            'checks_performed' => [],
        ];

        // Check 1: Stuck rebalancing operations
        $stuckOperations = $this->checkForStuckOperations();
        if (!empty($stuckOperations)) {
            $health['issues'][] = [
                'type' => 'stuck_operations',
                'count' => count($stuckOperations),
                'tables' => $stuckOperations,
            ];
            $health['overall_status'] = 'unhealthy';
        }
        $health['checks_performed'][] = 'stuck_operations';

        // Check 2: Failed rebalancing operations
        $failedOperations = $this->checkForFailedOperations();
        if (!empty($failedOperations)) {
            $health['issues'][] = [
                'type' => 'failed_operations',
                'count' => count($failedOperations),
                'tables' => $failedOperations,
            ];
            $health['overall_status'] = 'unhealthy';
        }
        $health['checks_performed'][] = 'failed_operations';

        // Check 3: Orphaned temporary clusters
        $orphanedClusters = $this->findOrphanedTemporaryClusters();
        if (!empty($orphanedClusters)) {
            $health['warnings'][] = [
                'type' => 'orphaned_clusters',
                'count' => count($orphanedClusters),
                'clusters' => $orphanedClusters,
            ];
        }
        $health['checks_performed'][] = 'orphaned_clusters';

        // Check 4: Schema consistency
        $schemaIssues = $this->checkSchemaConsistency();
        if (!empty($schemaIssues)) {
            $health['issues'][] = [
                'type' => 'schema_inconsistency',
                'tables' => $schemaIssues,
            ];
            $health['overall_status'] = 'unhealthy';
        }
        $health['checks_performed'][] = 'schema_consistency';

        // Check 5: Queue depth
        $queueDepth = $this->checkQueueDepth();
        if ($queueDepth > 100) {
            $health['warnings'][] = [
                'type' => 'high_queue_depth',
                'depth' => $queueDepth,
            ];
        }
        $health['checks_performed'][] = 'queue_depth';

        return $health;
    }

    private function checkForStuckOperations(): array {
        $state = new State($this->client);
        $allStates = $state->listRegex('rebalance:.*');
        $stuck = [];

        foreach ($allStates as $item) {
            if ($item['value'] === 'running') {
                $tableName = substr($item['key'], strlen('rebalance:'));

                // Check if it's been running too long
                $diagnosis = $this->diagnoseStuckRebalancing($tableName);
                if ($diagnosis['status'] === 'stuck') {
                    $stuck[] = $tableName;
                }
            }
        }

        return $stuck;
    }

    private function checkForFailedOperations(): array {
        $state = new State($this->client);
        $allStates = $state->listRegex('rebalance:.*');
        $failed = [];

        foreach ($allStates as $item) {
            if ($item['value'] === 'failed') {
                $tableName = substr($item['key'], strlen('rebalance:'));
                $failed[] = $tableName;
            }
        }

        return $failed;
    }
}
```

### Automated Recovery System

```php
class AutoRecoverySystem {
    private int $maxRetries = 3;
    private int $retryDelaySeconds = 60;

    public function performAutoRecovery(): array {
        $healthCheck = (new ShardingHealthChecker($this->client))->performHealthCheck();
        $recoveryResults = [];

        if ($healthCheck['overall_status'] === 'healthy') {
            return ['status' => 'no_recovery_needed'];
        }

        // Handle stuck operations
        foreach ($healthCheck['issues'] as $issue) {
            if ($issue['type'] === 'stuck_operations') {
                foreach ($issue['tables'] as $tableName) {
                    $result = $this->recoverStuckOperation($tableName);
                    $recoveryResults['stuck_operations'][$tableName] = $result;
                }
            }

            if ($issue['type'] === 'failed_operations') {
                foreach ($issue['tables'] as $tableName) {
                    $result = $this->recoverFailedOperation($tableName);
                    $recoveryResults['failed_operations'][$tableName] = $result;
                }
            }
        }

        // Handle warnings
        foreach ($healthCheck['warnings'] as $warning) {
            if ($warning['type'] === 'orphaned_clusters') {
                $result = $this->cleanupOrphanedTemporaryClusters();
                $recoveryResults['orphaned_clusters'] = $result;
            }
        }

        return $recoveryResults;
    }

    private function recoverStuckOperation(string $tableName): array {
        $attempts = 0;
        $lastError = null;

        while ($attempts < $this->maxRetries) {
            try {
                $success = $this->resolveStuckRebalancing($tableName);
                if ($success) {
                    return ['status' => 'recovered', 'attempts' => $attempts + 1];
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }

            $attempts++;
            if ($attempts < $this->maxRetries) {
                sleep($this->retryDelaySeconds);
            }
        }

        return [
            'status' => 'failed_to_recover',
            'attempts' => $attempts,
            'last_error' => $lastError,
        ];
    }
}
```

## Best Practices for Error Handling

### 1. Defensive Programming

```php
// Always validate inputs
public function rebalance(Queue $queue): void {
    if (!$queue instanceof Queue) {
        throw new ShardingError('Invalid queue instance', ShardingError::TYPE_LOGIC);
    }

    // Check cluster health before starting
    if (!$this->cluster->isActive()) {
        throw new ShardingError('Cluster is not active', ShardingError::TYPE_CLUSTER);
    }

    // Check if table exists
    if (!$this->client->hasTable($this->name)) {
        throw new ShardingError("Table {$this->name} does not exist", ShardingError::TYPE_LOGIC);
    }

    // Proceed with rebalancing...
}
```

### 2. Idempotent Operations

```php
// Design operations to be safely retryable
public function createShardTable(string $node, int $shardId): void {
    $shardName = $this->getShardName($shardId);

    // Use IF NOT EXISTS for idempotency
    $sql = "CREATE TABLE IF NOT EXISTS {$shardName} {$this->structure} type='rt'";

    try {
        $this->client->sendRequest($sql);
    } catch (\Throwable $e) {
        // Check if error is due to table already existing
        if (strpos($e->getMessage(), 'already exists') !== false) {
            // This is fine - table already exists
            return;
        }

        // Re-throw other errors
        throw $e;
    }
}
```

### 3. Graceful Degradation

```php
public function handleClusterPartition(): void {
    try {
        // Try normal rebalancing
        $this->performNormalRebalancing();
    } catch (NetworkException $e) {
        // Fall back to local-only mode
        $this->enterLocalOnlyMode();

        // Log the degradation
        Buddy::info("Entering degraded mode due to network partition: " . $e->getMessage());
    }
}
```

### 4. Comprehensive Logging

```php
public function logRebalancingOperation(string $operation, array $context = []): void {
    $logEntry = [
        'timestamp' => time(),
        'table' => $this->name,
        'operation' => $operation,
        'context' => $context,
        'node_id' => Node::findId($this->client),
    ];

    // Log to multiple destinations
    Buddy::debugvv("Sharding operation: " . json_encode($logEntry));

    // Store in state for debugging
    $state = new State($this->client);
    $logKey = "operation_log:{$this->name}:" . time();
    $state->set($logKey, json_encode($logEntry));
}
```

The comprehensive error handling and recovery system ensures that the sharding system can handle failures gracefully and recover automatically from common issues while providing detailed diagnostics for manual intervention when needed.
