# Queue System and Synchronization

The queue system is a critical component that ensures proper command ordering and synchronization across cluster nodes during sharding operations. It provides asynchronous command execution with dependency management.

## Queue System Overview

### Core Functionality

The Queue class manages distributed command execution with the following key features:

- **Command Queuing**: Stores commands for execution on specific nodes
- **Dependency Management**: Uses `wait_for_id` to ensure proper command ordering
- **Node Targeting**: Routes commands to specific cluster nodes
- **Parallel Execution**: Supports concurrent operations where safe
- **Synchronization Points**: Ensures critical operations complete before proceeding
- **Rollback Support**: Stores rollback commands alongside forward commands (REQUIRED)
- **Operation Groups**: Groups related commands for atomic execution
- **Automatic Rollback**: Executes rollback sequence on failure

### Queue Table Structure

```sql
CREATE TABLE system.sharding_queue (
    `id` bigint,                    -- Primary key
    `node` string,                   -- Target node
    `query` string,                  -- Forward command
    `rollback_query` string,         -- Rollback command (REQUIRED)
    `wait_for_id` bigint,           -- Forward dependency
    `rollback_wait_for_id` bigint,  -- Rollback dependency
    `operation_group` string,        -- Operation group ID
    `tries` int,                     -- Retry count
    `status` string,                 -- Command status
    `created_at` bigint,            -- Creation timestamp
    `updated_at` bigint,            -- Last update timestamp
    `duration` int                  -- Execution duration
)
```

### Queue Command Structure

```php
// Basic queue command structure
$command = [
    'id' => $queueId,
    'node' => $nodeId,
    'query' => $sqlCommand,
    'wait_for_id' => $dependencyId, // Optional dependency
];

// Enhanced command with rollback support
$command = [
    'id' => $queueId,
    'node' => $nodeId,
    'query' => $sqlCommand,
    'rollback_query' => $rollbackCommand,     // Reverse operation
    'wait_for_id' => $dependencyId,
    'rollback_wait_for_id' => 0,              // Rollback dependency
    'operation_group' => $groupId,             // Group identifier
];
```

## Command Dependencies and Synchronization

### Basic Dependency Chain

The queue system uses `wait_for_id` to ensure proper command ordering:

```php
// Command A with mandatory rollback
$idA = $queue->add($node, "COMMAND A", "ROLLBACK A", $operationGroup);

// Command B waits for A to complete
$queue->setWaitForId($idA);
$idB = $queue->add($node, "COMMAND B", "ROLLBACK B", $operationGroup);

// Command C waits for B to complete
$queue->setWaitForId($idB);
$idC = $queue->add($node, "COMMAND C", "ROLLBACK C", $operationGroup);

// Reset dependencies for independent commands
$queue->resetWaitForId();
```

## Rollback Operations

### Adding Commands with Rollback

All queue commands now require explicit rollback commands:

```php
// Add command with explicit rollback (rollback is mandatory)
$queue->add(
    $nodeId,
    "CREATE TABLE users_s0 (id bigint)",
    "DROP TABLE IF EXISTS users_s0",  // Explicit rollback required
    $operationGroup
);

// Add cluster command with rollback
$queue->add(
    $nodeId,
    "ALTER CLUSTER c1 ADD users_s0",
    "ALTER CLUSTER c1 DROP users_s0",  // Explicit rollback
    $operationGroup
);
```

### Operation Groups

Related commands are grouped for atomic execution:

```php
$operationGroup = "shard_create_users_" . uniqid();

// All commands in the same group (rollback required for each)
$queue->add($node1, $cmd1, $rollback1, $operationGroup);
$queue->add($node2, $cmd2, $rollback2, $operationGroup);
$queue->add($node3, $cmd3, $rollback3, $operationGroup);

// On failure, rollback entire group
if ($error) {
    $queue->rollbackOperationGroup($operationGroup);
}
```

### Rollback Execution Flow

When a rollback is triggered:

1. **Get Rollback Commands**: Retrieve all completed commands in the group
2. **Reverse Order**: Sort commands by ID descending (reverse order)
3. **Execute Rollbacks**: Run each rollback command
4. **Continue on Error**: If a rollback fails, continue with others
5. **Report Status**: Return overall rollback success/failure

```php
protected function executeRollbackSequence(array $rollbackCommands): bool {
    $allSuccess = true;

    foreach ($rollbackCommands as $command) {
        try {
            $this->client->sendRequest($command['rollback_query']);
            Buddy::debugvv("Rollback successful: {$command['rollback_query']}");
        } catch (\Throwable $e) {
            Buddy::debugvv("Rollback failed: " . $e->getMessage());
            $allSuccess = false;
            // Continue with other rollback commands
        }
    }

    return $allSuccess;
}
```

### Critical Synchronization Points

1. **Table Creation**: Must complete before cluster operations
2. **Cluster Setup**: Must complete before adding shards
3. **Data Sync**: `ALTER CLUSTER ADD` is synchronous - data is fully copied when it completes
4. **Data Removal**: Only remove from source after sync confirmation
5. **Cleanup**: Only clean up temporary resources after all data operations complete

## RF=1 Shard Movement Queue Flow

### Complete Command Sequence

For RF=1 shard movement, the queue orchestrates a complex sequence:

```php
protected function moveShardWithIntermediateCluster(
    Queue $queue,
    int $shardId,
    string $sourceNode,
    string $targetNode
): int {
    $shardName = $this->getShardName($shardId);
    $tempClusterName = "temp_move_{$shardId}_" . uniqid();

    // Step 1: Create shard table on target node
    $createQueueId = $queue->add($targetNode, $this->getCreateTableShardSQL($shardId), "DROP TABLE IF EXISTS {$shardName}");

    // Step 2: Create temporary cluster on SOURCE node (where the data IS)
    // CRITICAL: Use cluster name as path to ensure uniqueness
    $clusterQueueId = $queue->add(
        $sourceNode,
        "CREATE CLUSTER {$tempClusterName} '{$tempClusterName}' as path",
        "DELETE CLUSTER {$tempClusterName}"
    );

    // Step 3: Add shard to cluster on SOURCE node FIRST (before JOIN)
    $queue->setWaitForId($clusterQueueId);
    $queue->add($sourceNode, "ALTER CLUSTER {$tempClusterName} ADD {$shardName}", "ALTER CLUSTER {$tempClusterName} DROP {$shardName}");

    // Step 4: TARGET node joins the cluster that SOURCE created
    // Wait for table creation on target node to complete first
    $queue->setWaitForId($createQueueId);
    $joinQueueId = $queue->add(
        $targetNode,
        "JOIN CLUSTER {$tempClusterName} AT '{$sourceNode}' '{$tempClusterName}' as path",
        "DELETE CLUSTER {$tempClusterName}"
    );

    // Step 5: CRITICAL - Wait for JOIN to complete (data is now synced)
    // JOIN CLUSTER is synchronous, so once it's processed, data is fully copied
    $queue->setWaitForId($joinQueueId);
    $dropQueueId = $queue->add($sourceNode, "ALTER CLUSTER {$tempClusterName} DROP {$shardName}", "ALTER CLUSTER {$tempClusterName} ADD {$shardName}");

    // Step 6: Only after DROP from cluster, remove the table from source
    $queue->setWaitForId($dropQueueId);
    $deleteQueueId = $queue->add($sourceNode, "DROP TABLE {$shardName}", "");

    // Step 7: Clean up temporary cluster ONLY on SOURCE node after all operations complete
    $queue->setWaitForId($deleteQueueId);
    return $queue->add($sourceNode, "DELETE CLUSTER {$tempClusterName}", "");
}
```

### Command Timeline Visualization

```
Timeline: ──────────────────────────────────────────────────────►

Step 1:   CREATE TABLE shard_s1 ON target_node
          │
          ▼ (wait_for_id)
Step 2:   CREATE CLUSTER temp_move_1_xyz 'temp_move_1_xyz' as path ON source_node
          │
          ▼ (wait_for_id)
Step 3:   ALTER CLUSTER temp_move_1_xyz ADD shard_s1 ON source_node
          │
          ▼ (wait_for_id)
Step 4:   JOIN CLUSTER temp_move_1_xyz AT 'source_node' ON target_node
          │                    ▲
          │                    │ CRITICAL: Data is fully synced here
          ▼ (wait_for_id)      │
Step 5:   ALTER CLUSTER temp_move_1_xyz DROP shard_s1 ON source_node
          │
          ▼ (wait_for_id)
Step 6:   DROP TABLE shard_s1 ON source_node
          │
          ▼ (wait_for_id)
Step 7:   DELETE CLUSTER temp_move_1_xyz ON source_node
```

## RF>=2 Replication Queue Flow

### Simpler Command Sequence

For RF>=2, the queue flow is simpler since no data movement is required:

```php
protected function handleRFNNewNodes(Queue $queue, Vector $schema, Vector $newSchema, Set $newNodes): void {
    foreach ($newNodes as $newNode) {
        $shardsForNewNode = $this->getShardsForNewNode($newSchema, $newNode);

        foreach ($shardsForNewNode as $shard) {
            // Create shard table on new node
            $queue->add($newNode, $this->getCreateTableShardSQL($shard), "DROP TABLE IF EXISTS {$shardName}");

            // Set up replication (no wait needed - parallel creation)
            $existingNodes = $this->getExistingNodesForShard($schema, $shard);
            $connectedNodes = $existingNodes->merge(new Set([$newNode]));
            $primaryNode = $existingNodes->first();

            // Use existing cluster replication mechanism
            $this->handleReplication($primaryNode, $queue, $connectedNodes, $clusterMap, $shard, $operationGroup);
        }
    }
}
```

## Distributed Table Creation Synchronization

### Drop-Then-Create Pattern

```php
protected function createDistributedTablesFromSchema(Queue $queue, Vector $newSchema): void {
    /** @var Set<int> */
    $dropQueueIds = new Set;

    // Phase 1: Drop all distributed tables with proper force option
    foreach ($newSchema as $row) {
        $sql = "DROP TABLE IF EXISTS {$this->name} OPTION force=1";
        $queueId = $queue->add($row['node'], $sql, "");
        $dropQueueIds->add($queueId);
    }

    // Phase 2: Create new distributed tables, waiting for all drops to complete
    $lastDropId = $dropQueueIds->count() > 0 ? max($dropQueueIds->toArray()) : 0;

    foreach ($newSchema as $row) {
        if (!$row['shards']->count()) {
            continue;
        }

        // CRITICAL: Wait for all DROP operations to complete
        if ($lastDropId > 0) {
            $queue->setWaitForId($lastDropId);
        }

        $sql = $this->getCreateShardedTableSQLWithSchema($row['shards'], $newSchema);
        $queue->add($row['node'], $sql, "DROP TABLE IF EXISTS {$this->name}");
    }

    // Reset wait dependencies
    $queue->resetWaitForId();
}
```

## Queue Implementation Details

### Queue Command Addition

```php
public function add(string $nodeId, string $query, string $rollbackQuery, ?string $operationGroup = null): int {
    $queueId = $this->generateNextId();

    $command = [
        'id' => $queueId,
        'node' => $nodeId,
        'query' => $query,
        'rollback_query' => $rollbackQuery,
        'wait_for_id' => $this->currentWaitForId,
        'operation_group' => $operationGroup ?? '',
        'created_at' => time(),
        'status' => 'pending',
    ];

    $this->storeCommand($command);
    return $queueId;
}
```

### Dependency Management

```php
private ?int $currentWaitForId = null;

public function setWaitForId(int $waitForId): static {
    $this->currentWaitForId = $waitForId;
    return $this;
}

public function resetWaitForId(): static {
    $this->currentWaitForId = null;
    return $this;
}
```

### Command Processing

```php
public function process(Node $node): void {
    $pendingCommands = $this->getPendingCommandsForNode($node->getId());

    foreach ($pendingCommands as $command) {
        // Check if dependencies are satisfied
        if ($command['wait_for_id'] && !$this->isDependencySatisfied($command['wait_for_id'])) {
            continue; // Skip until dependency is satisfied
        }

        try {
            $this->executeCommand($command);
            $this->markCommandCompleted($command['id']);
        } catch (\Throwable $e) {
            $this->markCommandFailed($command['id'], $e->getMessage());
            throw $e;
        }
    }
}
```

## Synchronization Patterns

### Sequential Operations

When operations must be strictly ordered:

```php
// Pattern: Each operation waits for the previous to complete (rollback required)
$step1Id = $queue->add($node, "STEP 1 COMMAND", "STEP 1 ROLLBACK");

$queue->setWaitForId($step1Id);
$step2Id = $queue->add($node, "STEP 2 COMMAND", "STEP 2 ROLLBACK");

$queue->setWaitForId($step2Id);
$step3Id = $queue->add($node, "STEP 3 COMMAND", "STEP 3 ROLLBACK");

$queue->resetWaitForId();
```

### Parallel Operations with Synchronization Point

When some operations can run in parallel but must converge:

```php
// Phase 1: Parallel operations (rollback required for each)
$queue->resetWaitForId(); // Ensure no dependencies
$parallel1Id = $queue->add($node1, "PARALLEL COMMAND 1", "PARALLEL ROLLBACK 1");
$parallel2Id = $queue->add($node2, "PARALLEL COMMAND 2", "PARALLEL ROLLBACK 2");
$parallel3Id = $queue->add($node3, "PARALLEL COMMAND 3", "PARALLEL ROLLBACK 3");

// Phase 2: Wait for all parallel operations to complete
$maxParallelId = max($parallel1Id, $parallel2Id, $parallel3Id);
$queue->setWaitForId($maxParallelId);

// Phase 3: Sequential operations that depend on all parallel operations
$finalStepId = $queue->add($node1, "FINAL COMMAND", "FINAL ROLLBACK");
```

### Cross-Node Synchronization

When operations on different nodes must be coordinated:

```php
// Node A prepares (rollback required)
$prepareId = $queue->add($nodeA, "PREPARE OPERATION", "PREPARE ROLLBACK");

// Node B waits for Node A to prepare, then joins
$queue->setWaitForId($prepareId);
$joinId = $queue->add($nodeB, "JOIN OPERATION", "JOIN ROLLBACK");

// Node A waits for Node B to join, then finalizes
$queue->setWaitForId($joinId);
$finalizeId = $queue->add($nodeA, "FINALIZE OPERATION", "FINALIZE ROLLBACK");
```

## Error Handling in Queue Operations

### Command Failure Recovery

```php
try {
    $this->executeCommand($command);
    $this->markCommandCompleted($command['id']);
} catch (\Throwable $e) {
    $this->markCommandFailed($command['id'], $e->getMessage());

    // Check if this is a critical command that should stop the chain
    if ($this->isCriticalCommand($command)) {
        $this->markDependentCommandsFailed($command['id']);
    }

    throw $e;
}
```

### Dependency Chain Failure

```php
private function markDependentCommandsFailed(int $failedCommandId): void {
    $dependentCommands = $this->getCommandsWaitingFor($failedCommandId);

    foreach ($dependentCommands as $dependentCommand) {
        $this->markCommandFailed(
            $dependentCommand['id'],
            "Dependency {$failedCommandId} failed"
        );

        // Recursively mark further dependencies as failed
        $this->markDependentCommandsFailed($dependentCommand['id']);
    }
}
```

## Queue Monitoring and Debugging

### Queue Status Inspection

```php
// Get all pending commands
$pendingCommands = $queue->getPendingCommands();

// Get commands for specific node
$nodeCommands = $queue->getCommandsForNode($nodeId);

// Get command by ID
$command = $queue->getById($commandId);

// Get dependency chain
$dependencyChain = $queue->getDependencyChain($commandId);
```

### Queue Metrics

```php
// Queue depth by node
$queueDepth = [];
foreach ($allNodes as $node) {
    $queueDepth[$node] = $queue->getPendingCountForNode($node);
}

// Average command execution time
$avgExecutionTime = $queue->getAverageExecutionTime();

// Failed command rate
$failureRate = $queue->getFailureRate();
```

## Best Practices

### Command Ordering Guidelines

1. **Create Before Use**: Always create resources before referencing them
2. **Sync Before Remove**: Ensure data is fully synchronized before removing sources
3. **Clean After Complete**: Only clean up temporary resources after main operations
4. **Batch Similar Operations**: Group similar operations for efficiency
5. **Minimize Dependencies**: Use dependencies only when necessary for correctness

### Performance Optimization

1. **Parallel Where Possible**: Allow parallel execution when operations are independent
2. **Batch Operations**: Combine multiple similar operations when safe
3. **Minimize Cross-Node Dependencies**: Reduce synchronization overhead
4. **Use Appropriate Timeouts**: Set reasonable timeouts for long-running operations

### Error Recovery Strategies

1. **Idempotent Operations**: Design operations to be safely retryable
2. **Checkpoint Progress**: Track progress to enable partial recovery
3. **Graceful Degradation**: Continue with reduced functionality when possible
4. **Clear Error Messages**: Provide actionable error information

## ALTER CLUSTER ADD TABLE Verification System

### Background

`ALTER CLUSTER ADD TABLE` commands present unique challenges in distributed environments:

- **Blocking Operations**: Commands can block for extended periods during table synchronization
- **Network Intensive**: Large tables require significant time to sync across cluster nodes  
- **Timeout Issues**: Client timeouts may occur while background synchronization continues
- **False Failures**: Commands may appear to fail but tables sync successfully

### Verification Mechanism

The queue system implements intelligent verification for `ALTER CLUSTER ADD TABLE` commands:

#### Detection Phase
```php
// Automatic detection of ALTER CLUSTER ADD TABLE commands
if ($this->isAlterClusterAddTableQuery($query['query'])) {
    // Apply special handling
}
```

#### Verification Flow
1. **Initial Execution**: Command executes normally through queue system
2. **Error Detection**: If command returns error/timeout
3. **Running Check**: Use `SHOW QUERIES` to check if command still executing
4. **Status Verification**: If not running, use `SHOW CLUSTERS` to verify table synchronization
5. **Final Status**: Mark as 'processed' if tables verified, 'error' if not

#### Implementation Details
```php
protected function handleAlterClusterAddTableError(string $query, Struct $errorResult): string {
    // Check if query still running
    if ($this->checkQueryStillRunning($query)) {
        return 'error'; // Will retry with existing mechanism
    }
    
    // Verify cluster status
    $clusterName = $this->extractClusterNameFromQuery($query);
    $tableNames = $this->extractTableNamesFromQuery($query);
    
    if ($this->cluster->verifyTablesInCluster($clusterName, $tableNames)) {
        return 'processed'; // Success despite timeout
    }
    
    return 'error'; // Genuine failure
}
```

### Benefits

- **Reliability**: Eliminates false failures for long-running operations
- **Efficiency**: Reduces unnecessary retries when tables are already synchronized
- **Transparency**: No impact on other command types
- **Robustness**: Handles network issues and large table synchronization gracefully

### Monitoring

The system provides detailed logging for operational visibility:

```
[DEBUG] Checking if ALTER CLUSTER ADD TABLE still running
[DEBUG] Query no longer running, verifying cluster status
[DEBUG] Tables verified in cluster, marking as processed
```

The queue system provides the foundation for reliable, ordered execution of complex distributed operations while maintaining data safety and system consistency.
