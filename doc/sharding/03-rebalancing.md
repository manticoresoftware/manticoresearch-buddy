# Rebalancing Logic and Algorithms

The rebalancing system handles both node failures and new node additions with algorithms tailored to different replication factors.

## Rebalancing Overview

### Main Entry Point

The `rebalance()` method differentiates scenarios and routes to appropriate handlers. All queue items are tagged with an `$operationGroup` and gated by a commit flag — nodes only process items after the master finishes queuing ALL of them.

```php
public function rebalance(Queue $queue): void {
    $operationGroup = "rebalance_{$this->name}_" . time();
    $state = $this->state;

    // Prevent concurrent rebalancing
    $rebalanceKey = "rebalance:{$this->name}";
    if ($state->get($rebalanceKey) === 'running' || $state->get($rebalanceKey) === 'queued') {
        return;
    }

    $state->set($rebalanceKey, 'running');
    $state->set("rebalance_group:{$this->name}", $operationGroup);

    // ... detect inactive/new nodes, route to handler ...

    // After ALL queue items are added — set commit flag
    $state->set("rebalance_committed:{$this->name}", $operationGroup);
    $state->set($rebalanceKey, 'queued');
    $state->set("rebalance_queue_ids:{$this->name}", $lastQueueId);
}
```

If master dies before setting `rebalance_committed`, the new master detects the uncommitted group via `cleanupUncommittedRebalances()` and purges orphaned items.

## Scenario Detection

The system differentiates between three scenarios:

1. **Failed Nodes**: Nodes in schema but now inactive — redistributes orphaned shards
2. **New Nodes**: Active nodes not in schema — adds replicas or redistributes
3. **Partial State**: Inactive + new nodes simultaneously — skips rebalancing, waits for recovery
4. **Stable State**: No changes needed

Additional guards:
- RF=1 with inactive nodes: skips (data unrecoverable)
- Active nodes < RF: skips (can't maintain replication factor)

## Failed Node Rebalancing

Redistributes orphaned shards to remaining active nodes. All calls pass `$operationGroup`.

```php
protected function handleFailedNodesRebalance(
    Queue $queue, Vector $schema, Vector $newSchema,
    Set $inactiveNodes, string $operationGroup = ''
): array {
    $initData = $this->initializeClusterMap($schema, $inactiveNodes);

    $affectedSchema = $schema->filter(
        fn ($row) => $inactiveNodes->contains($row['node'])
    );

    $this->processAffectedShards(
        $queue, $affectedSchema, $newSchema,
        $initData['shardNodesMap'], $initData['clusterMap'], $operationGroup
    );

    $this->updateScheme($newSchema);
    return $this->createDistributedTablesFromSchema($queue, $newSchema, $operationGroup);
}
```

## New Node Rebalancing

### Strategy Selection

Routes based on replication factor:

```php
protected function handleNewNodesRebalance(
    Queue $queue, Vector $schema, Vector $newSchema,
    Set $newNodes, string $operationGroup = ''
): array {
    $replicationFactor = $this->getReplicationFactor($schema);

    if ($replicationFactor === 1) {
        return $this->handleRF1NewNodes($queue, $schema, $newSchema, $newNodes, $operationGroup);
    }
    return $this->handleRFNNewNodes($queue, $schema, $newSchema, $newNodes, $operationGroup);
}
```

### RF=1: Shard Movement via Intermediate Clusters

For RF=1, shards must be **moved** (not copied) using temporary clusters:

```php
protected function handleRF1NewNodes(..., string $operationGroup = ''): array {
    $shardsToMove = $this->calculateShardsToMove($schema, $newSchema, $newNodes);

    $lastMoveQueueId = 0;
    foreach ($shardsToMove as $shardId => $moveInfo) {
        $lastMoveQueueId = $this->moveShardWithIntermediateCluster(
            $queue, $shardId, $moveInfo['from'], $moveInfo['to']
        );
    }

    if ($lastMoveQueueId > 0) {
        $queue->setWaitForId($lastMoveQueueId);
    }

    $this->updateScheme($newSchema);
    $nodeTailIds = $this->createDistributedTablesFromSchema($queue, $newSchema, $operationGroup);
    $queue->resetWaitForId();
    return $nodeTailIds;
}
```

### RF>=2: Balanced Replica Addition

For RF>=2, new nodes receive replicas. All `queue->add()` calls include `$operationGroup`:

```php
protected function handleRFNNewNodes(..., string $operationGroup = ''): array {
    $clusterMap = new Map;
    $lastQueueId = 0;

    foreach ($newNodes as $newNode) {
        $shardsForNewNode = $this->getShardsForNewNode($newSchema, $newNode);

        foreach ($shardsForNewNode as $shard) {
            $shardName = $this->getShardName($shard);
            $rollback = "DROP TABLE IF EXISTS {$shardName}";
            $lastQueueId = $queue->add(
                $newNode, $this->getCreateTableShardSQL($shard), $rollback, $operationGroup
            );

            // Set up replication from existing nodes
            $clusterMap = $this->handleReplication(
                $primaryNode, $queue, $connectedNodes, $clusterMap, $shard, $operationGroup
            );
        }
    }

    foreach ($clusterMap as $cluster) {
        $cluster->processPendingTables($queue, $operationGroup);
    }

    // Defer schema update until all queue operations complete
    if ($lastQueueId > 0) {
        $queue->setWaitForId($lastQueueId);
    }
    $this->updateScheme($newSchema);
    $nodeTailIds = $this->createDistributedTablesFromSchema($queue, $newSchema, $operationGroup);
    $queue->resetWaitForId();
    return $nodeTailIds;
}
```

## Util Algorithms

### `rebalanceWithNewNodes()` — RF-Respecting Schema Calculation

For RF>=2, recalculates a fully balanced schema across all nodes using the same assignment algorithm as initial creation. This ensures the original RF is maintained.

```php
public static function rebalanceWithNewNodes(
    Vector $schema, Set $newNodes, int $replicationFactor
): Vector {
    if (!$newNodes->count()) {
        return $schema;
    }

    $newSchema = self::addNodesToSchema($schema, $newNodes);

    if ($replicationFactor === 1) {
        return self::redistributeShardsForRF1($newSchema, $newNodes);
    }

    // For RF>=2, recalculate balanced schema respecting the RF
    $allNodes = new Set($newSchema->map(fn($row) => $row['node']));
    $allNodes->sort();
    $totalShards = self::getTotalUniqueShards($schema);
    if ($totalShards > 0) {
        $balancedSchema = self::initializeSchema($allNodes);
        $nodeMap = self::initializeNodeMap($allNodes->count());
        return self::assignNodesToSchema(
            $balancedSchema, $nodeMap, $allNodes, $totalShards, $replicationFactor
        );
    }

    return $newSchema;
}
```

### `rebalanceShardingScheme()` — Failed Node Redistribution

Redistributes shards from inactive nodes to active ones. For RF>1 with new nodes, uses the same balanced assignment. For RF=1, returns schema as-is (data unrecoverable).

## Distributed Table Creation

All operations tagged with `$operationGroup`:

```php
protected function createDistributedTablesFromSchema(
    Queue $queue, Vector $newSchema, string $operationGroup = ''
): array {
    // Drop all distributed tables
    foreach ($newSchema as $row) {
        $sql = "DROP TABLE IF EXISTS {$this->name} OPTION force=1";
        $queue->add($row['node'], $sql, '', $operationGroup);
    }

    // Create new distributed tables (waiting for drops)
    foreach ($newSchema as $row) {
        $sql = $this->getCreateShardedTableSQLWithSchema($row['shards'], $newSchema);
        $queue->add($row['node'], $sql, "DROP TABLE IF EXISTS {$this->name}", $operationGroup);
    }
}
```

## Commit Flag Mechanism

The commit flag prevents partial rebalance execution when master dies mid-queuing:

1. Master sets `rebalance_group:{table}` = group ID
2. Master queues ALL items with that group ID
3. Master sets `rebalance_committed:{table}` = group ID (commit flag)
4. Nodes process items only when `isGroupCommitted()` returns true
5. On completion, `checkRebalanceStatus()` cleans up committed/group state
6. On master death, new master calls `cleanupUncommittedRebalances()` → purges orphaned items
