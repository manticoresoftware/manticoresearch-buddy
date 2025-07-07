# Troubleshooting Guide

This comprehensive troubleshooting guide covers common issues, diagnostic procedures, and resolution steps for the Manticore Buddy Sharding system.

## Common Issues

### 1. Rebalancing Stuck in 'running' State

**Symptoms:**
- Status shows 'running' but no progress for extended time (>30 minutes)
- Queue appears to have pending commands but they're not executing
- No visible activity in logs

**Diagnosis:**
```php
// Check rebalancing status
$table = new Table($client, $cluster, $tableName);
$status = $table->getRebalancingStatus(); // Returns 'running'

// Check how long it's been running
$info = $table->getRebalancingInfo();
$runningTime = time() - $info['last_updated'];
echo "Running for: " . $runningTime . " seconds";

// Check queue status
$queue = new Queue($client);
$queueItems = $queue->getAll();
echo "Queue depth: " . count($queueItems);

// Check for specific table commands
$tableCommands = array_filter($queueItems, function($item) use ($tableName) {
    return strpos($item['query'], $tableName) !== false;
});
echo "Table-specific commands: " . count($tableCommands);
```

**Resolution Steps:**

1. **Check Node Connectivity:**
```bash
# Test connectivity between nodes
telnet node2 9312
telnet node3 9312

# Check Manticore logs for connection errors
tail -f /var/log/manticore/searchd.log | grep -i error
```

2. **Reset State and Retry:**
```php
// Reset the stuck operation
$table->resetRebalancingState();

// Wait for state propagation
sleep(2);

// Clear any stuck queue items
$queue = new Queue($client);
$allItems = $queue->getAll();
foreach ($allItems as $item) {
    if (strpos($item['query'], $tableName) !== false &&
        (time() - $item['created_at']) > 1800) { // 30 minutes
        $queue->remove($item['id']);
    }
}

// Retry rebalancing
$table->rebalance($queue);
```

3. **Manual Cleanup if Needed:**
```sql
-- Check for temporary clusters
SHOW CLUSTERS;

-- Clean up any temp_move_* clusters manually
DELETE CLUSTER temp_move_1_abc123;
DELETE CLUSTER temp_move_2_def456;
```

### 2. New Nodes Not Getting Shards

**Symptoms:**
- New nodes remain empty after rebalancing
- Rebalancing completes successfully but distribution is unchanged
- No errors in logs

**Diagnosis:**
```php
// Check if nodes are properly detected
$cluster = new Cluster($client, 'your_cluster');
$allNodes = $cluster->getNodes();
$inactiveNodes = $cluster->getInactiveNodes();
$activeNodes = $allNodes->diff($inactiveNodes);

echo "All nodes: " . $allNodes->join(', ');
echo "Active nodes: " . $activeNodes->join(', ');
echo "Inactive nodes: " . $inactiveNodes->join(', ');

// Check current schema
$table = new Table($client, $cluster, $tableName);
$schema = $table->getShardSchema();
$schemaNodes = new Set($schema->map(fn($row) => $row['node']));

echo "Schema nodes: " . $schemaNodes->join(', ');

// Check for new nodes
$newNodes = $activeNodes->diff($schemaNodes);
echo "New nodes detected: " . $newNodes->join(', ');

// Check replication factor
$replicationFactor = $table->getReplicationFactor($schema);
echo "Replication factor: " . $replicationFactor;
```

**Common Causes and Solutions:**

1. **Nodes Not Properly Joined to Cluster:**
```sql
-- Check cluster membership
SHOW STATUS LIKE 'cluster_name';

-- Join missing nodes
JOIN CLUSTER your_cluster AT 'existing_node:9312';
```

2. **Incorrect Node Detection:**
```php
// Verify node IDs are correct
$currentNodeId = Node::findId($client);
echo "Current node ID: " . $currentNodeId;

// Check if node appears in cluster
$allNodes = $cluster->getNodes();
if (!$allNodes->contains($currentNodeId)) {
    echo "Current node not in cluster - check cluster configuration";
}
```

3. **Replication Factor Issues:**
```php
// For RF>=2, new nodes get replicas automatically
// For RF=1, shards must be moved - check if movement occurred

if ($replicationFactor === 1) {
    // Check if shard movement commands were generated
    $moves = $table->calculateShardsToMove($oldSchema, $newSchema, $newNodes);
    if (empty($moves)) {
        echo "No shard movements calculated - check redistribution algorithm";
    }
} else {
    // Check if replica creation commands were generated
    $shardsForNewNode = $table->getShardsForNewNode($newSchema, $newNode);
    if ($shardsForNewNode->count() === 0) {
        echo "No shards assigned to new node - check replica distribution";
    }
}
```

### 3. Temporary Clusters Not Cleaned Up

**Symptoms:**
- `SHOW CLUSTERS` shows temp_move_* clusters persisting
- Clusters remain after rebalancing completion
- Disk space usage from orphaned clusters

**Diagnosis:**
```sql
-- List all clusters
SHOW CLUSTERS;

-- Look for temporary clusters (temp_move_*)
-- Check their age and status
```

```php
// Programmatic detection
function findOrphanedTemporaryClusters(): array {
    $client = $this->client;
    $result = $client->sendRequest("SHOW CLUSTERS");
    $clusters = $result->getResult()[0]['data'] ?? [];

    $orphaned = [];
    foreach ($clusters as $cluster) {
        $clusterName = $cluster['cluster'] ?? '';
        if (strpos($clusterName, 'temp_move_') === 0) {
            $orphaned[] = $clusterName;
        }
    }

    return $orphaned;
}
```

**Resolution:**
```php
// Clean up orphaned temporary clusters
function cleanupOrphanedClusters(): void {
    $orphaned = $this->findOrphanedTemporaryClusters();

    foreach ($orphaned as $clusterName) {
        try {
            $this->client->sendRequest("DELETE CLUSTER {$clusterName}");
            echo "Cleaned up: {$clusterName}\n";
        } catch (\Throwable $e) {
            echo "Failed to clean up {$clusterName}: " . $e->getMessage() . "\n";
        }
    }
}
```

### 4. Data Inconsistency After Rebalancing

**Symptoms:**
- Shards missing or duplicated across nodes
- Search results inconsistent between nodes
- Distributed table shows errors

**Diagnosis:**
```php
// Check current shard distribution
$table = new Table($client, $cluster, $tableName);
$schema = $table->getShardSchema();

echo "Current schema:\n";
foreach ($schema as $row) {
    echo "Node: {$row['node']}, Shards: [" . $row['shards']->join(',') . "]\n";
}

// Verify all shards are accounted for
$allShards = new Set();
foreach ($schema as $row) {
    $allShards->add(...$row['shards']);
}

$expectedShards = range(0, $totalShardCount - 1);
$missingShards = array_diff($expectedShards, $allShards->toArray());
$extraShards = array_diff($allShards->toArray(), $expectedShards);

if (!empty($missingShards)) {
    echo "Missing shards: " . implode(',', $missingShards) . "\n";
}

if (!empty($extraShards)) {
    echo "Extra shards: " . implode(',', $extraShards) . "\n";
}
```

```sql
-- Check individual shard tables exist
SHOW TABLES LIKE 'table_name_s%';

-- Verify distributed table configuration
DESCRIBE table_name;

-- Check for data in each shard
SELECT COUNT(*) FROM table_name_s0;
SELECT COUNT(*) FROM table_name_s1;
-- ... for each shard
```

**Resolution:**
```php
// Reconstruct missing shards
function reconstructMissingShards(array $missingShards): void {
    foreach ($missingShards as $shardId) {
        $targetNode = $this->findBestNodeForShard($shardId);

        // Create missing shard table
        $sql = $this->getCreateTableShardSQL($shardId);
        $this->client->sendRequest($sql);

        echo "Created missing shard {$shardId} on {$targetNode}\n";
    }
}

// Remove duplicate shards
function removeDuplicateShards(array $extraShards): void {
    foreach ($extraShards as $shardId) {
        $duplicateNodes = $this->findDuplicateShardNodes($shardId);

        // Keep shard on first node, remove from others
        array_shift($duplicateNodes); // Keep first

        foreach ($duplicateNodes as $node) {
            $shardName = $this->getShardName($shardId);
            $sql = "DROP TABLE IF EXISTS {$shardName}";
            // Execute on specific node
            echo "Removed duplicate shard {$shardId} from {$node}\n";
        }
    }
}

// Recreate distributed table
$table->createDistributedTablesFromSchema($queue, $schema);
```

### 5. Queue Commands Failing

**Symptoms:**
- Queue depth keeps growing
- Commands marked as failed
- Specific SQL errors in logs

**Diagnosis:**
```php
// Check queue status
$queue = new Queue($client);
$allItems = $queue->getAll();

$failedCommands = array_filter($allItems, fn($item) => ($item['status'] ?? '') === 'failed');
$pendingCommands = array_filter($allItems, fn($item) => ($item['status'] ?? 'pending') === 'pending');

echo "Failed commands: " . count($failedCommands) . "\n";
echo "Pending commands: " . count($pendingCommands) . "\n";

// Analyze failed commands
foreach ($failedCommands as $cmd) {
    echo "Failed: {$cmd['query']} on {$cmd['node']} - Error: {$cmd['error'] ?? 'Unknown'}\n";
}

// Check for patterns
$errorPatterns = [];
foreach ($failedCommands as $cmd) {
    $error = $cmd['error'] ?? 'Unknown';
    $errorPatterns[$error] = ($errorPatterns[$error] ?? 0) + 1;
}

echo "Error patterns:\n";
foreach ($errorPatterns as $error => $count) {
    echo "  {$error}: {$count} occurrences\n";
}
```

**Common Queue Failures and Solutions:**

1. **Table Already Exists:**
```sql
-- Error: table 'table_s1' already exists
-- Solution: Use IF NOT EXISTS in commands
CREATE TABLE IF NOT EXISTS table_s1 (id bigint) type='rt';
```

2. **Cluster Not Found:**
```sql
-- Error: cluster 'temp_move_123' not found
-- Solution: Check cluster creation sequence
SHOW CLUSTERS; -- Verify cluster exists before operations
```

3. **Node Connectivity Issues:**
```bash
# Check network connectivity
ping node2
telnet node2 9312

# Check Manticore daemon status
systemctl status manticore
```

4. **Permission Issues:**
```sql
-- Check user permissions
SHOW STATUS LIKE 'cluster_%';

-- Verify cluster permissions
-- Ensure nodes can create/modify clusters
```

### 6. Performance Issues During Rebalancing

**Symptoms:**
- Rebalancing takes extremely long time
- High CPU/memory usage during operations
- Cluster becomes unresponsive

**Diagnosis:**
```php
// Monitor resource usage
function getResourceUsage(): array {
    return [
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true),
        'cpu_load' => sys_getloadavg(),
        'queue_depth' => count($queue->getAll()),
    ];
}

// Check for large data movements
function analyzeDataMovement(): array {
    $movements = [];
    $queueItems = $queue->getAll();

    foreach ($queueItems as $item) {
        if (strpos($item['query'], 'ALTER CLUSTER') !== false &&
            strpos($item['query'], 'ADD') !== false) {
            // This is a data movement operation
            $movements[] = $item;
        }
    }

    return $movements;
}
```

**Optimization Strategies:**

1. **Reduce Concurrent Operations:**
```php
// Limit concurrent rebalancing operations
$maxConcurrent = 1; // Reduce from default
$currentRunning = count($this->getRunningRebalanceOperations());

if ($currentRunning >= $maxConcurrent) {
    echo "Too many concurrent operations, waiting...\n";
    return;
}
```

2. **Batch Operations:**
```php
// Process queue in smaller batches
function processQueueInBatches(int $batchSize = 10): void {
    $queue = new Queue($this->client);
    $pendingItems = $queue->getPendingItems();

    $batches = array_chunk($pendingItems, $batchSize);

    foreach ($batches as $batch) {
        foreach ($batch as $item) {
            $queue->processItem($item);
        }

        // Small delay between batches
        sleep(1);
    }
}
```

3. **Monitor and Throttle:**
```php
// Monitor system load and throttle accordingly
function adaptiveProcessing(): void {
    $load = sys_getloadavg()[0]; // 1-minute load average
    $cpuCount = $this->getCpuCount();
    $loadPercentage = ($load / $cpuCount) * 100;

    if ($loadPercentage > 80) {
        // High load - slow down processing
        sleep(5);
    } elseif ($loadPercentage > 60) {
        // Medium load - moderate delay
        sleep(2);
    }
    // Low load - proceed normally
}
```

## Debugging Tools

### 1. State Inspection

```php
class StateInspector {
    public function inspectAllStates(): array {
        $state = new State($this->client);
        $allStates = $state->listRegex('.*');

        $categorized = [
            'rebalancing' => [],
            'cluster' => [],
            'other' => [],
        ];

        foreach ($allStates as $item) {
            $key = $item['key'];
            $value = $item['value'];

            if (strpos($key, 'rebalance:') === 0) {
                $tableName = substr($key, strlen('rebalance:'));
                $categorized['rebalancing'][$tableName] = $value;
            } elseif (strpos($key, 'cluster') !== false) {
                $categorized['cluster'][$key] = $value;
            } else {
                $categorized['other'][$key] = $value;
            }
        }

        return $categorized;
    }

    public function getRebalancingTimeline(string $tableName): array {
        $state = new State($this->client);
        $timeline = [];

        // Get main status
        $status = $state->get("rebalance:{$tableName}");
        $timeline[] = [
            'timestamp' => time(),
            'event' => 'current_status',
            'data' => $status,
        ];

        // Get detailed info if available
        $info = $state->get("rebalance_info:{$tableName}");
        if ($info) {
            $infoData = json_decode($info, true);
            $timeline[] = [
                'timestamp' => $infoData['started_at'] ?? time(),
                'event' => 'operation_started',
                'data' => $infoData,
            ];
        }

        return $timeline;
    }
}
```

### 2. Schema Analysis

```php
class SchemaAnalyzer {
    public function analyzeSchemaDistribution(string $tableName): array {
        $table = new Table($this->client, $this->cluster, $tableName);
        $schema = $table->getShardSchema();

        $analysis = [
            'total_nodes' => $schema->count(),
            'total_shards' => 0,
            'replication_factor' => $table->getReplicationFactor($schema),
            'distribution_balance' => 0,
            'node_details' => [],
        ];

        // Analyze each node
        $shardCounts = [];
        foreach ($schema as $row) {
            $nodeShardCount = $row['shards']->count();
            $shardCounts[] = $nodeShardCount;
            $analysis['total_shards'] += $nodeShardCount;

            $analysis['node_details'][] = [
                'node' => $row['node'],
                'shard_count' => $nodeShardCount,
                'shards' => $row['shards']->toArray(),
                'connections' => $row['connections']->toArray(),
            ];
        }

        // Calculate distribution balance (coefficient of variation)
        if (!empty($shardCounts)) {
            $mean = array_sum($shardCounts) / count($shardCounts);
            $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $shardCounts)) / count($shardCounts);
            $stdDev = sqrt($variance);
            $analysis['distribution_balance'] = $mean > 0 ? ($stdDev / $mean) : 0;
        }

        return $analysis;
    }

    public function compareSchemas(Vector $schema1, Vector $schema2): array {
        $differences = [
            'nodes_added' => [],
            'nodes_removed' => [],
            'shards_moved' => [],
            'replication_changed' => false,
        ];

        // Build node maps
        $nodes1 = new Set($schema1->map(fn($row) => $row['node']));
        $nodes2 = new Set($schema2->map(fn($row) => $row['node']));

        $differences['nodes_added'] = $nodes2->diff($nodes1)->toArray();
        $differences['nodes_removed'] = $nodes1->diff($nodes2)->toArray();

        // Compare shard distributions
        $shardMap1 = $this->buildShardMap($schema1);
        $shardMap2 = $this->buildShardMap($schema2);

        foreach ($shardMap2 as $shard => $nodes2) {
            $nodes1 = $shardMap1[$shard] ?? new Set();

            if (!$nodes1->equals($nodes2)) {
                $differences['shards_moved'][] = [
                    'shard' => $shard,
                    'from_nodes' => $nodes1->toArray(),
                    'to_nodes' => $nodes2->toArray(),
                ];
            }
        }

        return $differences;
    }
}
```

### 3. Queue Inspection

```php
class QueueInspector {
    public function analyzeQueueStatus(): array {
        $queue = new Queue($this->client);
        $allItems = $queue->getAll();

        $analysis = [
            'total_items' => count($allItems),
            'by_status' => [],
            'by_node' => [],
            'by_command_type' => [],
            'dependency_chains' => [],
            'stuck_items' => [],
        ];

        foreach ($allItems as $item) {
            $status = $item['status'] ?? 'pending';
            $node = $item['node'];
            $commandType = $this->extractCommandType($item['query']);

            // Count by status
            $analysis['by_status'][$status] = ($analysis['by_status'][$status] ?? 0) + 1;

            // Count by node
            $analysis['by_node'][$node] = ($analysis['by_node'][$node] ?? 0) + 1;

            // Count by command type
            $analysis['by_command_type'][$commandType] = ($analysis['by_command_type'][$commandType] ?? 0) + 1;

            // Check for stuck items
            $age = time() - $item['created_at'];
            if ($age > 600 && $status === 'pending') { // 10 minutes
                $analysis['stuck_items'][] = [
                    'id' => $item['id'],
                    'age_seconds' => $age,
                    'query' => $item['query'],
                    'node' => $node,
                ];
            }
        }

        // Analyze dependency chains
        $analysis['dependency_chains'] = $this->analyzeDependencyChains($allItems);

        return $analysis;
    }

    private function analyzeDependencyChains(array $queueItems): array {
        $chains = [];
        $itemsById = [];

        // Index items by ID
        foreach ($queueItems as $item) {
            $itemsById[$item['id']] = $item;
        }

        // Find chain heads (items with no dependencies)
        foreach ($queueItems as $item) {
            if (empty($item['wait_for_id'])) {
                $chain = $this->buildChain($item, $itemsById);
                if (count($chain) > 1) {
                    $chains[] = $chain;
                }
            }
        }

        return $chains;
    }

    private function buildChain(array $startItem, array $itemsById): array {
        $chain = [$startItem['id']];
        $currentId = $startItem['id'];

        // Find items that depend on this one
        foreach ($itemsById as $item) {
            if ($item['wait_for_id'] === $currentId) {
                $subChain = $this->buildChain($item, $itemsById);
                $chain = array_merge($chain, $subChain);
                break; // Only follow one chain branch
            }
        }

        return $chain;
    }
}
```

## Log Analysis

### Key Log Patterns

Look for these patterns in Manticore logs:

#### Normal Operation
```
// Successful operations
"Sharding rebalance: detecting new nodes for table {table_name}"
"Sharding rebalance: moving shard {shard_id} from {source} to {target}"
"Sharding rebalance: completed for table {table_name}"
```

#### Error Conditions
```
// Stuck operations
"Sharding rebalance: operation already running for table {table_name}"

// State errors
"Sharding: Error while setting state key '{key}': {error}"

// Rebalancing failures
"Rebalancing failed for table {table_name}: {error_message}"

// Queue errors
"Queue: Failed to execute command {command_id}: {error}"

// Cluster connectivity issues
"Failed to connect to cluster node {node_id}"
"Cluster communication timeout with {node_id}"
```

### Log Analysis Script

```bash
#!/bin/bash

# Analyze sharding logs
LOG_FILE="/var/log/manticore/searchd.log"
TABLE_NAME="${1:-all}"

echo "=== Sharding Log Analysis for Table: $TABLE_NAME ==="

# Count rebalancing operations
echo "Rebalancing Operations:"
if [ "$TABLE_NAME" = "all" ]; then
    grep -c "Sharding rebalance:" "$LOG_FILE"
else
    grep -c "Sharding rebalance.*$TABLE_NAME" "$LOG_FILE"
fi

# Find errors
echo -e "\nErrors:"
if [ "$TABLE_NAME" = "all" ]; then
    grep "Sharding.*error\|Rebalancing failed" "$LOG_FILE" | tail -10
else
    grep "Sharding.*error\|Rebalancing failed.*$TABLE_NAME" "$LOG_FILE" | tail -10
fi

# Find stuck operations
echo -e "\nStuck Operations:"
grep "operation already running" "$LOG_FILE" | tail -5

# Find recent activity
echo -e "\nRecent Activity (last 10 entries):"
if [ "$TABLE_NAME" = "all" ]; then
    grep "Sharding" "$LOG_FILE" | tail -10
else
    grep "Sharding.*$TABLE_NAME" "$LOG_FILE" | tail -10
fi
```

## Recovery Procedures

### Emergency Recovery Checklist

1. **Assess Situation:**
   - [ ] Check cluster health
   - [ ] Identify affected tables
   - [ ] Determine scope of issue

2. **Immediate Actions:**
   - [ ] Stop new rebalancing operations
   - [ ] Reset stuck operations if safe
   - [ ] Clean up orphaned resources

3. **Data Safety:**
   - [ ] Verify no data loss occurred
   - [ ] Check shard integrity
   - [ ] Validate distributed table consistency

4. **Recovery:**
   - [ ] Restore from backup if needed
   - [ ] Recreate missing shards
   - [ ] Restart rebalancing if appropriate

5. **Verification:**
   - [ ] Test cluster functionality
   - [ ] Verify search operations work
   - [ ] Monitor for recurring issues

### Complete Recovery Script

```php
class EmergencyRecovery {
    public function performEmergencyRecovery(): array {
        $results = [
            'timestamp' => time(),
            'steps_completed' => [],
            'issues_found' => [],
            'actions_taken' => [],
            'recommendations' => [],
        ];

        try {
            // Step 1: Health assessment
            $healthCheck = $this->performHealthAssessment();
            $results['issues_found'] = $healthCheck['issues'];
            $results['steps_completed'][] = 'health_assessment';

            // Step 2: Stop ongoing operations
            $stoppedOperations = $this->stopOngoingOperations();
            $results['actions_taken'][] = "Stopped {$stoppedOperations} operations";
            $results['steps_completed'][] = 'stop_operations';

            // Step 3: Clean up resources
            $cleanupResults = $this->cleanupOrphanedResources();
            $results['actions_taken'][] = "Cleaned up {$cleanupResults['cleaned_count']} resources";
            $results['steps_completed'][] = 'cleanup_resources';

            // Step 4: Verify data integrity
            $integrityResults = $this->verifyDataIntegrity();
            if (!$integrityResults['all_ok']) {
                $results['issues_found'][] = 'data_integrity_issues';
                $results['recommendations'][] = 'Manual data recovery may be required';
            }
            $results['steps_completed'][] = 'data_integrity_check';

            // Step 5: Restore basic functionality
            $restorationResults = $this->restoreBasicFunctionality();
            $results['actions_taken'][] = "Restored functionality for {$restorationResults['tables_restored']} tables";
            $results['steps_completed'][] = 'restore_functionality';

            $results['status'] = 'completed';

        } catch (\Throwable $e) {
            $results['status'] = 'failed';
            $results['error'] = $e->getMessage();
        }

        return $results;
    }
}
```

This troubleshooting guide provides comprehensive coverage of common issues and their resolutions, enabling effective diagnosis and recovery of sharding system problems.
