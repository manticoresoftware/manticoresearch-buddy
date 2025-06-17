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

### Queue Command Structure

```php
// Basic queue command structure
$command = [
    'id' => $queueId,
    'node' => $nodeId,
    'query' => $sqlCommand,
    'wait_for_id' => $dependencyId, // Optional dependency
];
```

## Command Dependencies and Synchronization

### Basic Dependency Chain

The queue system uses `wait_for_id` to ensure proper command ordering:

```php
// Command A
$idA = $queue->add($node, "COMMAND A");

// Command B waits for A to complete
$queue->setWaitForId($idA);
$idB = $queue->add($node, "COMMAND B");

// Command C waits for B to complete
$queue->setWaitForId($idB);
$idC = $queue->add($node, "COMMAND C");

// Reset dependencies for independent commands
$queue->resetWaitForId();
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
    $createQueueId = $queue->add($targetNode, $this->getCreateTableShardSQL($shardId));

    // Step 2: Create temporary cluster on SOURCE node (where the data IS)
    // CRITICAL: Use cluster name as path to ensure uniqueness
    $clusterQueueId = $queue->add(
        $sourceNode,
        "CREATE CLUSTER {$tempClusterName} '{$tempClusterName}' as path"
    );

    // Step 3: Add shard to cluster on SOURCE node FIRST (before JOIN)
    $queue->setWaitForId($clusterQueueId);
    $queue->add($sourceNode, "ALTER CLUSTER {$tempClusterName} ADD {$shardName}");

    // Step 4: TARGET node joins the cluster that SOURCE created
    // Wait for table creation on target node to complete first
    $queue->setWaitForId($createQueueId);
    $joinQueueId = $queue->add(
        $targetNode,
        "JOIN CLUSTER {$tempClusterName} AT '{$sourceNode}' '{$tempClusterName}' as path"
    );

    // Step 5: CRITICAL - Wait for JOIN to complete (data is now synced)
    // JOIN CLUSTER is synchronous, so once it's processed, data is fully copied
    $queue->setWaitForId($joinQueueId);
    $dropQueueId = $queue->add($sourceNode, "ALTER CLUSTER {$tempClusterName} DROP {$shardName}");

    // Step 6: Only after DROP from cluster, remove the table from source
    $queue->setWaitForId($dropQueueId);
    $deleteQueueId = $queue->add($sourceNode, "DROP TABLE {$shardName}");

    // Step 7: Clean up temporary cluster ONLY on SOURCE node after all operations complete
    $queue->setWaitForId($deleteQueueId);
    return $queue->add($sourceNode, "DELETE CLUSTER {$tempClusterName}");
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
            $queue->add($newNode, $this->getCreateTableShardSQL($shard));

            // Set up replication (no wait needed - parallel creation)
            $existingNodes = $this->getExistingNodesForShard($schema, $shard);
            $connectedNodes = $existingNodes->merge(new Set([$newNode]));
            $primaryNode = $existingNodes->first();

            // Use existing cluster replication mechanism
            $this->handleReplication($primaryNode, $queue, $connectedNodes, $clusterMap, $shard);
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
        $queueId = $queue->add($row['node'], $sql);
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
        $queue->add($row['node'], $sql);
    }

    // Reset wait dependencies
    $queue->resetWaitForId();
}
```

## Queue Implementation Details

### Queue Command Addition

```php
public function add(string $nodeId, string $query): int {
    $queueId = $this->generateNextId();

    $command = [
        'id' => $queueId,
        'node' => $nodeId,
        'query' => $query,
        'wait_for_id' => $this->currentWaitForId,
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
// Pattern: Each operation waits for the previous to complete
$step1Id = $queue->add($node, "STEP 1 COMMAND");

$queue->setWaitForId($step1Id);
$step2Id = $queue->add($node, "STEP 2 COMMAND");

$queue->setWaitForId($step2Id);
$step3Id = $queue->add($node, "STEP 3 COMMAND");

$queue->resetWaitForId();
```

### Parallel Operations with Synchronization Point

When some operations can run in parallel but must converge:

```php
// Phase 1: Parallel operations
$queue->resetWaitForId(); // Ensure no dependencies
$parallel1Id = $queue->add($node1, "PARALLEL COMMAND 1");
$parallel2Id = $queue->add($node2, "PARALLEL COMMAND 2");
$parallel3Id = $queue->add($node3, "PARALLEL COMMAND 3");

// Phase 2: Wait for all parallel operations to complete
$maxParallelId = max($parallel1Id, $parallel2Id, $parallel3Id);
$queue->setWaitForId($maxParallelId);

// Phase 3: Sequential operations that depend on all parallel operations
$finalStepId = $queue->add($node1, "FINAL COMMAND");
```

### Cross-Node Synchronization

When operations on different nodes must be coordinated:

```php
// Node A prepares
$prepareId = $queue->add($nodeA, "PREPARE OPERATION");

// Node B waits for Node A to prepare, then joins
$queue->setWaitForId($prepareId);
$joinId = $queue->add($nodeB, "JOIN OPERATION");

// Node A waits for Node B to join, then finalizes
$queue->setWaitForId($joinId);
$finalizeId = $queue->add($nodeA, "FINALIZE OPERATION");
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

The queue system provides the foundation for reliable, ordered execution of complex distributed operations while maintaining data safety and system consistency.
