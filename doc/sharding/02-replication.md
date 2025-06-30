# Replication Factors and Strategies

The Manticore Buddy Sharding system supports different replication factors, each with its own characteristics and rebalancing strategies. Understanding these differences is crucial for proper system operation and troubleshooting.

## Replication Factor Overview

### RF=1 (Single Copy)
Each shard exists on exactly one node.

**Characteristics:**
- Highest performance (no replication overhead)
- No fault tolerance (node failure = data loss)
- Requires shard movement for rebalancing

**Use Cases:**
- High-performance scenarios where data loss is acceptable
- Development and testing environments
- Scenarios with external backup strategies

### RF=2 (Double Copy)
Each shard exists on exactly two nodes.

**Characteristics:**
- Good balance of performance and fault tolerance
- Can survive single node failure
- Requires replica addition for rebalancing

**Use Cases:**
- Production environments with moderate fault tolerance requirements
- Cost-effective high availability
- Most common production configuration

### RF>=3 (Multiple Copies)
Each shard exists on three or more nodes.

**Characteristics:**
- High fault tolerance
- Can survive multiple node failures
- Higher storage and replication overhead

**Use Cases:**
- Mission-critical applications
- High availability requirements
- Scenarios with frequent node failures

## Replication Factor Detection

The system automatically detects the current replication factor from the schema:

```php
protected function getReplicationFactor(Vector $schema): int {
    if ($schema->count() === 0) {
        return 1;
    }

    // Find the maximum number of connections for any shard
    $maxConnections = 1;
    foreach ($schema as $row) {
        $maxConnections = max($maxConnections, $row['connections']->count());
    }

    return $maxConnections;
}
```

## Rebalancing Strategies by Replication Factor

### RF=1 Rebalancing Strategy

When new nodes join, existing shards must be **moved** (not copied) to achieve better distribution.

```
Before: Node1[S0,S1], Node2[S2,S3]
After:  Node1[S0], Node2[S2], Node3[S1,S3]
```

**Technical Implementation:**
- Uses intermediate temporary clusters for safe data movement
- Synchronous data copy followed by source cleanup
- Complex queue orchestration to ensure data safety

**Key Challenge:** Moving data between nodes while maintaining availability and preventing data loss.

**Solution:** Intermediate Cluster Strategy

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

    // Step 2: Create temporary cluster with unique path
    $clusterQueueId = $queue->add(
        $sourceNode,
        "CREATE CLUSTER {$tempClusterName} '{$tempClusterName}' as path"
    );

    // Step 3: Join target node to temporary cluster
    $queue->setWaitForId($createQueueId);
    $joinQueueId = $queue->add(
        $targetNode,
        "JOIN CLUSTER {$tempClusterName} AT '{$sourceNode}' '{$tempClusterName}' as path"
    );

    // Step 4: SYNCHRONOUS data copy
    $queue->setWaitForId($joinQueueId);
    $dropQueueId = $queue->add($sourceNode, "ALTER CLUSTER {$tempClusterName} DROP {$shardName}");

    // Step 5: Remove table from source node
    $queue->setWaitForId($dropQueueId);
    $deleteQueueId = $queue->add($sourceNode, "DROP TABLE {$shardName}");

    // Step 6: Clean up temporary cluster
    $queue->setWaitForId($deleteQueueId);
    return $queue->add($sourceNode, "DELETE CLUSTER {$tempClusterName}");
}
```

**Key Innovation:** Using the cluster name as the path (`'temp_move_1_xyz' as path`) ensures uniqueness and avoids conflicts with existing clusters.

### RF=2 Rebalancing Strategy

When new nodes join, existing shards can be **replicated** to new nodes without moving data.

```
Before: Node1[S0,S1], Node2[S0,S1] (RF=2)
After:  Node1[S0,S1], Node2[S0,S1], Node3[S0,S1] (RF=3, can be optimized)
```

**Technical Implementation:**
- Creates shard tables on new nodes
- Uses existing cluster replication mechanisms
- No intermediate clusters needed

```php
protected function handleRFNNewNodes(Queue $queue, Vector $schema, Vector $newSchema, Set $newNodes): void {
    foreach ($newNodes as $newNode) {
        $shardsForNewNode = $this->getShardsForNewNode($newSchema, $newNode);

        foreach ($shardsForNewNode as $shard) {
            // Create shard table on new node
            $queue->add($newNode, $this->getCreateTableShardSQL($shard));

            // Set up replication from existing nodes
            $existingNodes = $this->getExistingNodesForShard($schema, $shard);
            $connectedNodes = $existingNodes->merge(new Set([$newNode]));
            $primaryNode = $existingNodes->first();

            // Use existing cluster replication mechanism
            $this->handleReplication($primaryNode, $queue, $connectedNodes, $clusterMap, $shard);
        }
    }
}
```

### RF>=3 Rebalancing Strategy

Similar to RF=2, new nodes receive replicas of existing shards.

**Characteristics:**
- High fault tolerance maintained
- Multiple node failures can be survived
- Load distribution across more nodes

## Schema Representation by Replication Factor

### RF=1 Schema Example
```php
$rf1Schema = new Vector([
    [
        'node' => 'node1',
        'shards' => new Set([0, 1]),
        'connections' => new Set(['node1']), // Self-connection only
    ],
    [
        'node' => 'node2',
        'shards' => new Set([2, 3]),
        'connections' => new Set(['node2']), // Self-connection only
    ],
]);
```

### RF=2 Schema Example
```php
$rf2Schema = new Vector([
    [
        'node' => 'node1',
        'shards' => new Set([0, 1]),
        'connections' => new Set(['node1', 'node2']), // Connected to replica
    ],
    [
        'node' => 'node2',
        'shards' => new Set([0, 1]),
        'connections' => new Set(['node1', 'node2']), // Same shards, connected
    ],
]);
```

### RF=3 Schema Example
```php
$rf3Schema = new Vector([
    [
        'node' => 'node1',
        'shards' => new Set([0, 1]),
        'connections' => new Set(['node1', 'node2', 'node3']),
    ],
    [
        'node' => 'node2',
        'shards' => new Set([0, 1]),
        'connections' => new Set(['node1', 'node2', 'node3']),
    ],
    [
        'node' => 'node3',
        'shards' => new Set([0, 1]),
        'connections' => new Set(['node1', 'node2', 'node3']),
    ],
]);
```

## Distributed Table Creation by Replication Factor

The system creates different distributed table configurations based on replication factor:

### RF=1 Distributed Table
```sql
CREATE TABLE `users`
    type='distributed'
    local='users_s0,users_s1'
    agent='node2:users_s2,users_s3'
```

### RF=2 Distributed Table
```sql
CREATE TABLE `users`
    type='distributed'
    local='users_s0,users_s1'
    agent='node2:users_s0,users_s1'
```

### RF=3 Distributed Table
```sql
CREATE TABLE `users`
    type='distributed'
    local='users_s0,users_s1'
    agent='node2:users_s0,users_s1'
    agent='node3:users_s0,users_s1'
```

## Algorithm Differences

### RF=1 Redistribution Algorithm
```php
private static function redistributeShardsForRF1(Vector $schema, Set $newNodes): Vector {
    // Copy existing nodes as-is initially
    $newSchema = self::copyExistingNodesForRF1($schema, $newNodes);

    // Calculate balanced distribution
    $targetShardsPerNode = self::calculateTargetShardsPerNode($newSchema, $newNodes);

    // Move shards from existing nodes to new nodes
    foreach ($newNodes as $newNode) {
        $shardsToMove = max(1, $targetShardsPerNode);
        $shardsForNewNode = self::moveShardsToNewNode($newSchema, $shardsToMove);

        // Add the new node with its target shards
        $newSchema[] = [
            'node' => $newNode,
            'shards' => $shardsForNewNode,
            'connections' => new Set([$newNode]),
        ];
    }

    return $newSchema;
}
```

### RF>=2 Replica Addition Algorithm
```php
private static function addReplicasToNewNodes(Vector $schema, Set $newNodes): Vector {
    // Collect all existing shards
    $allShards = self::collectExistingShards($schema, $newNodes);

    // Add all shards to new nodes for load balancing
    $schema = self::assignShardsToNewNodes($schema, $newNodes, $allShards);

    // Update connections for all nodes
    return self::updateShardConnections($schema, $allShards);
}
```

## Performance Considerations

### RF=1 Performance Characteristics
- **Pros:** Highest read/write performance, minimal storage overhead
- **Cons:** Complex rebalancing, no fault tolerance, data movement required

### RF=2 Performance Characteristics
- **Pros:** Good performance, fault tolerance, simple rebalancing
- **Cons:** 2x storage overhead, moderate replication latency

### RF>=3 Performance Characteristics
- **Pros:** High fault tolerance, can survive multiple failures
- **Cons:** Higher storage overhead, increased replication latency

## Best Practices

### Choosing Replication Factor

1. **RF=1**: Use only when:
   - Performance is critical
   - Data loss is acceptable
   - External backup systems exist

2. **RF=2**: Recommended for:
   - Most production environments
   - Balanced performance and reliability
   - Cost-effective high availability

3. **RF>=3**: Use when:
   - Maximum fault tolerance required
   - Multiple simultaneous failures expected
   - Storage cost is not a primary concern

### Monitoring Replication Factor

```php
// Check current replication factor
$currentSchema = $table->getShardSchema();
$replicationFactor = $this->getReplicationFactor($currentSchema);

echo "Current replication factor: $replicationFactor";

// Verify proper replication
foreach ($currentSchema as $row) {
    $expectedConnections = $replicationFactor;
    $actualConnections = $row['connections']->count();

    if ($actualConnections !== $expectedConnections) {
        echo "Warning: Node {$row['node']} has {$actualConnections} connections, expected {$expectedConnections}";
    }
}
```

Understanding these replication factor differences is essential for proper system operation, performance optimization, and troubleshooting rebalancing issues.
