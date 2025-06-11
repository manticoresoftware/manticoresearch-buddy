# Rebalancing Logic and Algorithms

The rebalancing system is the core of the Manticore Buddy Sharding implementation, handling both node failures and new node additions with sophisticated algorithms tailored to different replication factors.

## Rebalancing Overview

### Main Rebalancing Entry Point

The enhanced rebalancing logic differentiates between different scenarios and routes them to appropriate handlers:

```php
public function rebalance(Queue $queue): void {
    try {
        // Create state instance for tracking rebalancing operations
        $state = new State($this->client);

        // Prevent concurrent rebalancing operations
        $rebalanceKey = "rebalance:{$this->name}";
        $currentRebalance = $state->get($rebalanceKey);

        if ($currentRebalance === 'running') {
            Buddy::debugvv("Sharding rebalance: operation already running for table {$this->name}, skipping");
            return;
        }

        // Mark rebalancing as running
        $state->set($rebalanceKey, 'running');

        $schema = $this->getShardSchema();
        $allNodes = $this->cluster->getNodes();
        $inactiveNodes = $this->cluster->getInactiveNodes();
        $activeNodes = $allNodes->diff($inactiveNodes);

        // Detect new nodes (not in current schema)
        $schemaNodes = new Set($schema->map(fn($row) => $row['node']));
        $newNodes = $activeNodes->diff($schemaNodes);

        // Handle different rebalancing scenarios
        if ($inactiveNodes->count() > 0) {
            // Existing logic: handle failed nodes
            $newSchema = Util::rebalanceShardingScheme($schema, $activeNodes);
            $this->handleFailedNodesRebalance($queue, $schema, $newSchema, $inactiveNodes);
        } elseif ($newNodes->count() > 0) {
            // New logic: handle new nodes
            $replicationFactor = $this->getReplicationFactor($schema);
            $newSchema = Util::rebalanceWithNewNodes($schema, $newNodes, $replicationFactor);
            $this->handleNewNodesRebalance($queue, $schema, $newSchema, $newNodes);
        } else {
            // No changes needed
            $state->set($rebalanceKey, 'idle');
            return;
        }

        // Mark rebalancing as completed
        $state->set($rebalanceKey, 'completed');
    } catch (\Throwable $t) {
        // Mark rebalancing as failed and reset state
        $state = new State($this->client);
        $rebalanceKey = "rebalance:{$this->name}";
        $state->set($rebalanceKey, 'failed');
        throw $t;
    }
}
```

## Scenario Detection Logic

The system differentiates between three scenarios:

1. **Failed Nodes**: Nodes that were in the schema but are now inactive
2. **New Nodes**: Nodes that are active but not in the current schema
3. **Stable State**: No changes needed

```php
// Current schema nodes
$schemaNodes = new Set($schema->map(fn($row) => $row['node']));

// All active nodes in cluster
$activeNodes = $allNodes->diff($inactiveNodes);

// New nodes = active nodes not in schema
$newNodes = $activeNodes->diff($schemaNodes);

// Failed nodes = schema nodes that are inactive
$failedNodes = $inactiveNodes->intersect($schemaNodes);
```

## Failed Node Rebalancing (Existing Logic)

### Process Overview

When nodes fail, the system redistributes orphaned shards to remaining active nodes:

```php
protected function handleFailedNodesRebalance(
    Queue $queue,
    Vector $schema,
    Vector $newSchema,
    Set $inactiveNodes
): void {
    // Initialize cluster map and shard nodes mapping
    $initData = $this->initializeClusterMap($schema, $inactiveNodes);
    $shardNodesMap = $initData['shardNodesMap'];
    $clusterMap = $initData['clusterMap'];

    $affectedSchema = $schema->filter(
        fn ($row) => $inactiveNodes->contains($row['node'])
    );

    // Process affected shards for rebalancing
    $this->processAffectedShards($queue, $affectedSchema, $newSchema, $shardNodesMap, $clusterMap);

    // Update schema in database FIRST, then create distributed tables
    $this->updateScheme($newSchema);
    $this->createDistributedTablesFromSchema($queue, $newSchema);
}
```

### Cluster Map Initialization

```php
private function initializeClusterMap(Vector $schema, Set $inactiveNodes): array {
    /** @var Map<string,Cluster> */
    $clusterMap = new Map;

    // Detect shard to nodes map with alive schema
    $shardNodesMap = $this->getShardNodesMap(
        $schema->filter(
            fn ($row) => !$inactiveNodes->contains($row['node'])
        )
    );

    // Preload current cluster map with configuration
    foreach ($shardNodesMap as $connections) {
        $clusterName = static::getClusterName($connections);
        $connections->sort();
        $node = $connections->first();
        $cluster = new Cluster($this->client, $clusterName, $node);
        $clusterMap[$clusterName] = $cluster;
    }

    return ['shardNodesMap' => $shardNodesMap, 'clusterMap' => $clusterMap];
}
```

## New Node Rebalancing (Enhanced Logic)

### Strategy Selection

The system routes new node handling based on replication factor:

```php
protected function handleNewNodesRebalance(Queue $queue, Vector $schema, Vector $newSchema, Set $newNodes): void {
    $replicationFactor = $this->getReplicationFactor($schema);

    if ($replicationFactor === 1) {
        $this->handleRF1NewNodes($queue, $schema, $newSchema, $newNodes);
    } else {
        $this->handleRFNNewNodes($queue, $schema, $newSchema, $newNodes);
    }
}
```

### RF=1 New Node Handling (Complex Case)

For RF=1, shards must be **moved** from existing nodes to new nodes using intermediate clusters:

```php
protected function handleRF1NewNodes(Queue $queue, Vector $schema, Vector $newSchema, Set $newNodes): void {
    // Calculate which shards need to move
    $shardsToMove = $this->calculateShardsToMove($schema, $newSchema, $newNodes);

    // Track the last queue ID from shard movements
    $lastMoveQueueId = 0;
    foreach ($shardsToMove as $shardId => $moveInfo) {
        $lastMoveQueueId = $this->moveShardWithIntermediateCluster(
            $queue,
            $shardId,
            $moveInfo['from'],
            $moveInfo['to']
        );
    }

    // Update schema ONLY AFTER all shard movements complete
    if ($lastMoveQueueId > 0) {
        $queue->setWaitForId($lastMoveQueueId);
    }

    $this->updateScheme($newSchema);
    $this->createDistributedTablesFromSchema($queue, $newSchema);

    // Reset wait for id
    $queue->resetWaitForId();
}
```

### Shard Movement Calculation

```php
protected function calculateShardsToMove(Vector $oldSchema, Vector $newSchema, Set $newNodes): array {
    $moves = [];

    foreach ($newSchema as $row) {
        if (!$newNodes->contains($row['node'])) {
            continue;
        }

        // This is a new node, find shards assigned to it
        foreach ($row['shards'] as $shard) {
            // Find where this shard was in the old schema
            $oldOwner = $this->findShardOwner($oldSchema, $shard);

            if (!$oldOwner) {
                continue;
            }

            $moves[$shard] = ['from' => $oldOwner, 'to' => $row['node']];
        }
    }

    return $moves;
}
```

### RF>=2 New Node Handling (Simpler Case)

For RF>=2, new nodes can receive replicas without moving existing data:

```php
protected function handleRFNNewNodes(Queue $queue, Vector $schema, Vector $newSchema, Set $newNodes): void {
    /** @var Map<string,Cluster> */
    $clusterMap = new Map;

    // For RF>=2, add new nodes as replicas to existing shards
    foreach ($newNodes as $newNode) {
        // Find shards that should be replicated to this new node
        $shardsForNewNode = $this->getShardsForNewNode($newSchema, $newNode);

        foreach ($shardsForNewNode as $shard) {
            // Create shard table on new node
            $queue->add($newNode, $this->getCreateTableShardSQL($shard));

            // Set up replication from existing nodes
            $existingNodes = $this->getExistingNodesForShard($schema, $shard);
            if ($existingNodes->count() <= 0) {
                continue;
            }

            $connectedNodes = $existingNodes->merge(new Set([$newNode]));
            $existingNodes->sort();
            $primaryNode = $existingNodes->first();

            $clusterMap = $this->handleReplication(
                $primaryNode,
                $queue,
                $connectedNodes,
                $clusterMap,
                $shard
            );
        }
    }

    // Update schema in database FIRST, then create distributed tables
    $this->updateScheme($newSchema);
    $this->createDistributedTablesFromSchema($queue, $newSchema);
}
```

## Util Algorithm Enhancements

### New Node Rebalancing Algorithm

```php
public static function rebalanceWithNewNodes(Vector $schema, Set $newNodes, int $replicationFactor): Vector {
    if (!$newNodes->count()) {
        return $schema;
    }

    // Add new nodes to schema with empty shards
    $newSchema = self::addNodesToSchema($schema, $newNodes);

    if ($replicationFactor === 1) {
        // For RF=1, we need to move shards to achieve better distribution
        return self::redistributeShardsForRF1($newSchema, $newNodes);
    }

    // For RF>=2, we can add replicas to new nodes
    return self::addReplicasToNewNodes($newSchema, $newNodes);
}
```

### RF=1 Redistribution Algorithm

```php
private static function redistributeShardsForRF1(Vector $schema, Set $newNodes): Vector {
    // Copy existing nodes as-is initially
    $newSchema = self::copyExistingNodesForRF1($schema, $newNodes);

    // Calculate how many shards each new node should get for balanced distribution
    $targetShardsPerNode = self::calculateTargetShardsPerNode($newSchema, $newNodes);

    // Add new nodes and determine which shards to move to them
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

### Shard Movement Logic

```php
private static function moveShardsToNewNode(Vector $newSchema, int $shardsToMove): Set {
    $shardsForNewNode = new Set();

    for ($i = 0; $i < $shardsToMove; $i++) {
        $loadedNodeInfo = self::findMostLoadedNode($newSchema);
        $sourceNodeIndex = $loadedNodeInfo['index'];
        $maxShards = $loadedNodeInfo['maxShards'];

        // If we found a source node with shards, mark one for movement
        if ($sourceNodeIndex < 0 || $maxShards <= 0) {
            break;
        }

        // Take one shard from the most loaded node
        $sourceRow = $newSchema[$sourceNodeIndex];
        if ($sourceRow === null) {
            break;
        }
        $shardToMove = $sourceRow['shards']->first();

        // For RF=1, remove the shard from source node immediately
        $sourceRow['shards']->remove($shardToMove);
        $newSchema[$sourceNodeIndex] = $sourceRow;
        $shardsForNewNode->add($shardToMove);
    }

    return $shardsForNewNode;
}
```

### RF>=2 Replica Addition Algorithm

```php
private static function addReplicasToNewNodes(Vector $schema, Set $newNodes): Vector {
    // Collect all existing shards
    $allShards = self::collectExistingShards($schema, $newNodes);

    // For RF>=2, add all shards to new nodes for load balancing
    $schema = self::assignShardsToNewNodes($schema, $newNodes, $allShards);

    // Update connections for all nodes to include all nodes that have each shard
    return self::updateShardConnections($schema, $allShards);
}
```

## Distributed Table Creation

### Schema-Aware Creation

The system creates distributed tables based on the calculated schema:

```php
protected function createDistributedTablesFromSchema(Queue $queue, Vector $newSchema): void {
    /** @var Set<int> */
    $dropQueueIds = new Set;

    // First, drop all distributed tables with proper force option
    foreach ($newSchema as $row) {
        $sql = "DROP TABLE IF EXISTS {$this->name} OPTION force=1";
        $queueId = $queue->add($row['node'], $sql);
        $dropQueueIds->add($queueId);
    }

    // Then create new distributed tables, waiting for all drops to complete
    $lastDropId = $dropQueueIds->count() > 0 ? max($dropQueueIds->toArray()) : 0;

    foreach ($newSchema as $row) {
        // Do nothing when no shards present for this node
        if (!$row['shards']->count()) {
            continue;
        }

        // Wait for all DROP operations to complete before creating new tables
        if ($lastDropId > 0) {
            $queue->setWaitForId($lastDropId);
        }

        // Pass the calculated schema to avoid database timing issues
        $sql = $this->getCreateShardedTableSQLWithSchema($row['shards'], $newSchema);
        $queue->add($row['node'], $sql);
    }

    // Reset wait for id
    $queue->resetWaitForId();
}
```

### RF-Aware Agent Configuration

```php
private function buildAgentDefinitions(Map $map, Set $shards, int $replicationFactor): Set {
    $agents = new Set;
    $currentNodeId = Node::findId($this->client);

    foreach ($map as $shard => $nodeConnections) {
        if ($replicationFactor === 1) {
            // RF=1: Create agents for shards that DON'T exist locally (remote shards)
            if ($shards->contains($shard)) {
                continue;
            }
        } else {
            // RF>=2: Create agents for shards that DO exist locally (replicated shards)
            if (!$shards->contains($shard)) {
                continue;
            }
        }

        // Filter out the current node from agents (don't point to yourself)
        $remoteConnections = $nodeConnections->filter(
            fn($connection) => !str_starts_with($connection, $currentNodeId . ':')
        );

        if ($remoteConnections->count() <= 0) {
            continue;
        }

        $agents->add("agent='{$remoteConnections->join('|')}'");
    }

    return $agents;
}
```

## Load Balancing Algorithms

### Target Shard Calculation

```php
private static function calculateTargetShardsPerNode(Vector $newSchema, Set $newNodes): int {
    $totalShards = 0;
    foreach ($newSchema as $row) {
        $totalShards += $row['shards']->count();
    }
    $totalNodes = $newSchema->count() + $newNodes->count();
    return $totalNodes > 0 ? (int)floor($totalShards / $totalNodes) : 0;
}
```

### Most Loaded Node Detection

```php
private static function findMostLoadedNode(Vector $newSchema): array {
    $maxShards = 0;
    $sourceNodeIndex = -1;

    foreach ($newSchema as $index => $existingRow) {
        if ($existingRow['shards']->count() <= $maxShards) {
            continue;
        }

        $maxShards = $existingRow['shards']->count();
        $sourceNodeIndex = $index;
    }

    return ['index' => $sourceNodeIndex, 'maxShards' => $maxShards];
}
```

## Edge Case Handling

### More Nodes Than Shards

```php
// Some nodes will have shards, others won't (since we only have limited shards)
$nodesWithShards = 0;
$nodesWithoutShards = 0;

foreach ($newSchema as $row) {
    if ($row['shards']->count() > 0) {
        $nodesWithShards++;
        // No node should have more than 1 shard when nodes > shards
        $this->assertLessThanOrEqual(1, $row['shards']->count());
    } else {
        $nodesWithoutShards++;
    }
}
```

### Empty Schema Handling

```php
if ($schema->count() === 0) {
    // All nodes are new - handle initial sharding
    return $this->handleInitialSharding($newNodes, $replicationFactor);
}
```

This comprehensive rebalancing system ensures proper shard distribution while maintaining data safety and system availability across all supported replication factors and edge cases.
