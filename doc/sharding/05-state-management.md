# State Management and Concurrency Control

The state management system ensures safe concurrent operations, tracks rebalancing progress, and provides recovery mechanisms for failed operations. It uses persistent storage to maintain consistency across cluster restarts.

## State Management Overview

### Core Functionality

The State class provides:

- **Key-Value Storage**: Persistent state storage in `system.sharding_state` table
- **Concurrent Operation Prevention**: Prevents multiple simultaneous rebalancing operations
- **Progress Tracking**: Monitors operation status and completion
- **Recovery Support**: Enables recovery from failed or interrupted operations
- **Cluster-Aware**: Maintains state across cluster topology changes

### State Storage Architecture

```php
// State is stored in system.sharding_state table
CREATE TABLE system.sharding_state (
    key string,
    value json,
    updated_at timestamp
);
```

## Rebalancing State Management

### State Key Format

The system uses table-specific keys to track rebalancing operations:

```php
// State key format
$rebalanceKey = "rebalance:{$tableName}";

// Examples
"rebalance:users"     // For users table
"rebalance:products"  // For products table
"rebalance:logs"      // For logs table
```

### State Values and Transitions

```php
// State values
'idle'      // No operation in progress (initial state)
'running'   // Rebalancing operation in progress
'completed' // Last operation completed successfully
'failed'    // Last operation failed (requires intervention)
```

### State Transition Diagram

```
     ┌─────────┐
     │  idle   │◄──────────────────┐
     └─────────┘                   │
          │                        │
          │ start rebalancing      │ reset/recovery
          ▼                        │
     ┌─────────┐    success   ┌─────────────┐
     │ running │─────────────►│  completed  │
     └─────────┘              └─────────────┘
          │                        │
          │ failure                │ timeout/reset
          ▼                        │
     ┌─────────┐                   │
     │ failed  │───────────────────┘
     └─────────┘
```

## Concurrent Operation Prevention

### Basic Concurrency Control

```php
public function rebalance(Queue $queue): void {
    $state = new State($this->client);
    $rebalanceKey = "rebalance:{$this->name}";
    $currentRebalance = $state->get($rebalanceKey);

    if ($currentRebalance === 'running') {
        Buddy::debugvv("Sharding rebalance: operation already running for table {$this->name}, skipping");
        return;
    }

    // Mark as running
    $state->set($rebalanceKey, 'running');

    try {
        // Perform rebalancing operations...
        $this->performRebalancing($queue);

        // Mark as completed
        $state->set($rebalanceKey, 'completed');
    } catch (\Throwable $t) {
        // Mark as failed
        $state->set($rebalanceKey, 'failed');
        throw $t;
    }
}
```

### Advanced Concurrency Control

For more sophisticated scenarios, the system can implement additional locking mechanisms:

```php
private function acquireRebalanceLock(string $tableName, int $timeoutSeconds = 300): bool {
    $state = new State($this->client);
    $lockKey = "rebalance_lock:{$tableName}";
    $currentTime = time();

    // Try to acquire lock
    $existingLock = $state->get($lockKey);

    if ($existingLock !== null) {
        $lockData = json_decode($existingLock, true);
        $lockTime = $lockData['timestamp'] ?? 0;
        $lockHolder = $lockData['holder'] ?? 'unknown';

        // Check if lock has expired
        if (($currentTime - $lockTime) < $timeoutSeconds) {
            Buddy::debugvv("Rebalance lock held by {$lockHolder}, acquired at " . date('Y-m-d H:i:s', $lockTime));
            return false;
        }

        // Lock has expired, we can take it
        Buddy::debugvv("Rebalance lock expired, taking over from {$lockHolder}");
    }

    // Acquire lock
    $lockData = [
        'holder' => $this->getNodeId(),
        'timestamp' => $currentTime,
        'table' => $tableName,
    ];

    $state->set($lockKey, json_encode($lockData));
    return true;
}

private function releaseRebalanceLock(string $tableName): void {
    $state = new State($this->client);
    $lockKey = "rebalance_lock:{$tableName}";
    $state->set($lockKey, null); // Remove lock
}
```

## State Management Methods

### Core State Operations

```php
class Table {
    /**
     * Check if rebalancing can be started for this table
     * @return bool
     */
    public function canStartRebalancing(): bool {
        $state = new State($this->client);
        $rebalanceKey = "rebalance:{$this->name}";
        $currentRebalance = $state->get($rebalanceKey);

        return $currentRebalance !== 'running';
    }

    /**
     * Reset rebalancing state (useful for recovery)
     * @return void
     */
    public function resetRebalancingState(): void {
        $state = new State($this->client);
        $rebalanceKey = "rebalance:{$this->name}";
        $state->set($rebalanceKey, 'idle');
    }

    /**
     * Get current rebalancing status
     * @return string
     */
    public function getRebalancingStatus(): string {
        $state = new State($this->client);
        $rebalanceKey = "rebalance:{$this->name}";
        $status = $state->get($rebalanceKey);
        return is_string($status) ? $status : 'idle';
    }
}
```

### Extended State Information

```php
/**
 * Get detailed rebalancing information
 * @return array
 */
public function getRebalancingInfo(): array {
    $state = new State($this->client);
    $rebalanceKey = "rebalance:{$this->name}";
    $infoKey = "rebalance_info:{$this->name}";

    $status = $state->get($rebalanceKey) ?? 'idle';
    $info = $state->get($infoKey);

    $result = [
        'status' => $status,
        'table' => $this->name,
        'last_updated' => null,
        'operation_type' => null,
        'nodes_involved' => [],
        'error_message' => null,
    ];

    if ($info) {
        $infoData = json_decode($info, true);
        $result = array_merge($result, $infoData);
    }

    return $result;
}

/**
 * Set detailed rebalancing information
 * @param array $info
 * @return void
 */
private function setRebalancingInfo(array $info): void {
    $state = new State($this->client);
    $infoKey = "rebalance_info:{$this->name}";

    $info['last_updated'] = time();
    $state->set($infoKey, json_encode($info));
}
```

## Recovery Mechanisms

### Recovery Scenarios

1. **Stuck Rebalancing**: State shows 'running' but operation is not progressing
2. **Failed Rebalancing**: State shows 'failed' and needs investigation
3. **Partial Completion**: Some commands executed, others failed
4. **System Restart**: Recovery after cluster restart during rebalancing

### Recovery Methods

```php
/**
 * Recover from stuck rebalancing operation
 * @return bool Success of recovery
 */
public function recoverFromStuckRebalancing(): bool {
    $state = new State($this->client);
    $rebalanceKey = "rebalance:{$this->name}";
    $currentStatus = $state->get($rebalanceKey);

    if ($currentStatus !== 'running') {
        return false; // Not stuck
    }

    // Check how long it's been running
    $infoKey = "rebalance_info:{$this->name}";
    $info = $state->get($infoKey);

    if ($info) {
        $infoData = json_decode($info, true);
        $lastUpdated = $infoData['last_updated'] ?? 0;
        $currentTime = time();

        // If running for more than 30 minutes, consider it stuck
        if (($currentTime - $lastUpdated) > 1800) {
            Buddy::debugvv("Rebalancing appears stuck for table {$this->name}, resetting state");
            $this->resetRebalancingState();
            return true;
        }
    }

    return false;
}

/**
 * Recover from failed rebalancing operation
 * @return array Recovery information
 */
public function recoverFromFailedRebalancing(): array {
    $state = new State($this->client);
    $rebalanceKey = "rebalance:{$this->name}";
    $currentStatus = $state->get($rebalanceKey);

    if ($currentStatus !== 'failed') {
        return ['status' => 'not_failed', 'message' => 'Rebalancing is not in failed state'];
    }

    // Get failure information
    $info = $this->getRebalancingInfo();
    $errorMessage = $info['error_message'] ?? 'Unknown error';

    // Analyze the failure and provide recovery steps
    $recoverySteps = $this->analyzeFailureAndGetRecoverySteps($info);

    return [
        'status' => 'failed',
        'error_message' => $errorMessage,
        'recovery_steps' => $recoverySteps,
        'can_auto_recover' => $this->canAutoRecover($info),
    ];
}

/**
 * Attempt automatic recovery from failed rebalancing
 * @return bool Success of auto-recovery
 */
public function attemptAutoRecovery(): bool {
    $recoveryInfo = $this->recoverFromFailedRebalancing();

    if ($recoveryInfo['status'] !== 'failed' || !$recoveryInfo['can_auto_recover']) {
        return false;
    }

    try {
        // Reset state and retry
        $this->resetRebalancingState();

        // Wait a moment for state to propagate
        sleep(1);

        // Retry rebalancing
        $queue = new Queue($this->client);
        $this->rebalance($queue);

        return true;
    } catch (\Throwable $e) {
        Buddy::debugvv("Auto-recovery failed for table {$this->name}: " . $e->getMessage());
        return false;
    }
}
```

## Cluster-Wide State Management

### Cluster Hash Tracking

The system tracks cluster topology changes using hash-based detection:

```php
public function checkBalance(): static {
    $cluster = $this->getCluster();
    $allNodes = $cluster->getNodes();
    $inactiveNodes = $cluster->getInactiveNodes();
    $activeNodes = $allNodes->diff($inactiveNodes);

    // Check if cluster topology changed
    $clusterHash = Cluster::getNodesHash($activeNodes);
    $currentHash = $this->state->get('cluster_hash');

    // If no topology change, nothing to do
    if ($clusterHash === $currentHash) {
        return $this;
    }

    // Update cluster hash
    $this->state->set('cluster_hash', $clusterHash);

    // Trigger rebalancing for all tables
    $this->triggerRebalancingForAllTables();

    return $this;
}
```

### Master Node State

```php
/**
 * Determine and set master node for coordination
 * @return string Master node ID
 */
private function determineMasterNode(): string {
    $state = new State($this->client);
    $cluster = $this->getCluster();
    $activeNodes = $cluster->getActiveNodes();

    // Sort nodes for consistent master selection
    $sortedNodes = $activeNodes->toArray();
    sort($sortedNodes);

    $masterNode = $sortedNodes[0] ?? null;

    if ($masterNode) {
        $state->set('master_node', $masterNode);
        $state->set('master_elected_at', time());
    }

    return $masterNode;
}

/**
 * Check if current node is master
 * @return bool
 */
private function isMasterNode(): bool {
    $state = new State($this->client);
    $masterNode = $state->get('master_node');
    $currentNode = Node::findId($this->client);

    return $masterNode === $currentNode;
}
```

## State Monitoring and Diagnostics

### State Inspection Tools

```php
/**
 * Get all rebalancing states across tables
 * @return array
 */
public function getAllRebalancingStates(): array {
    $state = new State($this->client);
    $states = $state->listRegex('rebalance:.*');

    $result = [];
    foreach ($states as $item) {
        $tableName = substr($item['key'], strlen('rebalance:'));
        $result[$tableName] = [
            'status' => $item['value'],
            'key' => $item['key'],
        ];
    }

    return $result;
}

/**
 * Get system-wide state information
 * @return array
 */
public function getSystemState(): array {
    $state = new State($this->client);

    return [
        'cluster_hash' => $state->get('cluster_hash'),
        'master_node' => $state->get('master_node'),
        'master_elected_at' => $state->get('master_elected_at'),
        'rebalancing_states' => $this->getAllRebalancingStates(),
        'active_operations' => $this->getActiveOperations(),
    ];
}

/**
 * Get currently active operations
 * @return array
 */
private function getActiveOperations(): array {
    $allStates = $this->getAllRebalancingStates();

    return array_filter($allStates, function($state) {
        return $state['status'] === 'running';
    });
}
```

### State Cleanup and Maintenance

```php
/**
 * Clean up old state entries
 * @param int $olderThanSeconds
 * @return int Number of entries cleaned
 */
public function cleanupOldStates(int $olderThanSeconds = 86400): int {
    $state = new State($this->client);
    $allStates = $state->listRegex('.*');
    $cleaned = 0;
    $currentTime = time();

    foreach ($allStates as $item) {
        // Skip active rebalancing states
        if (strpos($item['key'], 'rebalance:') === 0 && $item['value'] === 'running') {
            continue;
        }

        // Check if state is old (this would require timestamp tracking)
        // For now, we clean completed/failed states older than threshold
        if (in_array($item['value'], ['completed', 'failed'])) {
            // In a real implementation, you'd check timestamp
            // For now, we'll clean based on heuristics
            $state->set($item['key'], null); // Remove the state
            $cleaned++;
        }
    }

    return $cleaned;
}

/**
 * Reset all failed rebalancing states
 * @return array List of reset tables
 */
public function resetAllFailedStates(): array {
    $allStates = $this->getAllRebalancingStates();
    $reset = [];

    foreach ($allStates as $tableName => $stateInfo) {
        if ($stateInfo['status'] === 'failed') {
            $table = new Table($this->client, $this->cluster, $tableName);
            $table->resetRebalancingState();
            $reset[] = $tableName;
        }
    }

    return $reset;
}
```

## Best Practices

### State Management Guidelines

1. **Always Check State**: Check rebalancing state before starting operations
2. **Proper Error Handling**: Always update state in exception handlers
3. **Timeout Considerations**: Implement timeouts for long-running operations
4. **Recovery Planning**: Design operations to be recoverable from any point
5. **State Cleanup**: Regularly clean up old state entries

### Monitoring Recommendations

1. **Regular State Checks**: Monitor for stuck operations
2. **Alert on Failures**: Set up alerts for failed rebalancing operations
3. **Track Operation Duration**: Monitor how long rebalancing takes
4. **Cluster Health**: Monitor cluster topology changes
5. **Master Node Tracking**: Ensure master node is healthy and responsive

### Production Deployment

```php
// Example monitoring script
class ShardingMonitor {
    public function checkSystemHealth(): array {
        $operator = new Operator($this->client);
        $systemState = $operator->getSystemState();

        $issues = [];

        // Check for stuck operations
        foreach ($systemState['rebalancing_states'] as $table => $state) {
            if ($state['status'] === 'running') {
                // Check if operation has been running too long
                $issues[] = "Table {$table} rebalancing running for extended time";
            }

            if ($state['status'] === 'failed') {
                $issues[] = "Table {$table} rebalancing failed";
            }
        }

        // Check master node health
        if (!$systemState['master_node']) {
            $issues[] = "No master node elected";
        }

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'system_state' => $systemState,
        ];
    }
}
```

The state management system provides robust concurrency control and recovery mechanisms essential for reliable distributed sharding operations.
