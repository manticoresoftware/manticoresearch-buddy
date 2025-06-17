# Data Flow and Command Sequences

This section provides detailed examples of complete data flows and command sequences for different rebalancing scenarios, showing how all components work together to achieve safe and efficient shard distribution.

## Complete RF=1 New Node Addition Flow

### Scenario Setup

```
Initial State:
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│ S0, S1  │  │ S2, S3  │  │ (empty) │
└─────────┘  └─────────┘  └─────────┘

Target State (after rebalancing):
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│ S0      │  │ S2      │  │ S1, S3  │
└─────────┘  └─────────┘  └─────────┘
```

### Step-by-Step Data Flow

#### Step 1: Detection and Planning

```php
// 1. Operator detects topology change
$allNodes = $cluster->getNodes(); // [Node1, Node2, Node3]
$inactiveNodes = $cluster->getInactiveNodes(); // []
$activeNodes = $allNodes->diff($inactiveNodes); // [Node1, Node2, Node3]

// 2. Table detects new nodes
$schema = $table->getShardSchema(); // Current schema with Node1, Node2
$schemaNodes = new Set($schema->map(fn($row) => $row['node'])); // [Node1, Node2]
$newNodes = $activeNodes->diff($schemaNodes); // [Node3]

// 3. Determine replication factor and strategy
$replicationFactor = $table->getReplicationFactor($schema); // 1
$newSchema = Util::rebalanceWithNewNodes($schema, $newNodes, $replicationFactor);
```

#### Step 2: Shard Movement Calculation

```php
// Calculate which shards need to move
$shardsToMove = $table->calculateShardsToMove($schema, $newSchema, $newNodes);
// Result: [1 => ['from' => 'Node1', 'to' => 'Node3'], 3 => ['from' => 'Node2', 'to' => 'Node3']]
```

#### Step 3: Queue Command Generation for Shard S1 Movement

```php
// Move S1 from Node1 to Node3
$lastMoveQueueId = $table->moveShardWithIntermediateCluster($queue, 1, 'Node1', 'Node3');
```

**Detailed Command Sequence for S1 Movement:**

```
Command 1: CREATE TABLE IF NOT EXISTS users_s1 (id bigint, title text) type='rt' ON Node3
   ├─ Queue ID: 1001
   ├─ Node: Node3
   └─ Wait For: none

Command 2: CREATE CLUSTER temp_move_1_abc123 'temp_move_1_abc123' as path ON Node1
   ├─ Queue ID: 1002
   ├─ Node: Node1
   └─ Wait For: none

Command 3: ALTER CLUSTER temp_move_1_abc123 ADD users_s1 ON Node1
   ├─ Queue ID: 1003
   ├─ Node: Node1
   └─ Wait For: 1002

Command 4: JOIN CLUSTER temp_move_1_abc123 AT 'Node1' 'temp_move_1_abc123' as path ON Node3
   ├─ Queue ID: 1004
   ├─ Node: Node3
   └─ Wait For: 1001

Command 5: ALTER CLUSTER temp_move_1_abc123 DROP users_s1 ON Node1
   ├─ Queue ID: 1005
   ├─ Node: Node1
   └─ Wait For: 1004 (CRITICAL: Data sync complete)

Command 6: DROP TABLE users_s1 ON Node1
   ├─ Queue ID: 1006
   ├─ Node: Node1
   └─ Wait For: 1005

Command 7: DELETE CLUSTER temp_move_1_abc123 ON Node1
   ├─ Queue ID: 1007
   ├─ Node: Node1
   └─ Wait For: 1006
```

#### Step 4: Queue Command Generation for Shard S3 Movement

```php
// Move S3 from Node2 to Node3
$lastMoveQueueId = $table->moveShardWithIntermediateCluster($queue, 3, 'Node2', 'Node3');
```

**Detailed Command Sequence for S3 Movement:**

```
Command 8: CREATE TABLE IF NOT EXISTS users_s3 (id bigint, title text) type='rt' ON Node3
   ├─ Queue ID: 1008
   ├─ Node: Node3
   └─ Wait For: none

Command 9: CREATE CLUSTER temp_move_3_def456 'temp_move_3_def456' as path ON Node2
   ├─ Queue ID: 1009
   ├─ Node: Node2
   └─ Wait For: none

Command 10: ALTER CLUSTER temp_move_3_def456 ADD users_s3 ON Node2
   ├─ Queue ID: 1010
   ├─ Node: Node2
   └─ Wait For: 1009

Command 11: JOIN CLUSTER temp_move_3_def456 AT 'Node2' 'temp_move_3_def456' as path ON Node3
   ├─ Queue ID: 1011
   ├─ Node: Node3
   └─ Wait For: 1008

Command 12: ALTER CLUSTER temp_move_3_def456 DROP users_s3 ON Node2
   ├─ Queue ID: 1012
   ├─ Node: Node2
   └─ Wait For: 1011 (CRITICAL: Data sync complete)

Command 13: DROP TABLE users_s3 ON Node2
   ├─ Queue ID: 1013
   ├─ Node: Node2
   └─ Wait For: 1012

Command 14: DELETE CLUSTER temp_move_3_def456 ON Node2
   ├─ Queue ID: 1014
   ├─ Node: Node2
   └─ Wait For: 1013
```

#### Step 5: Schema Update and Distributed Table Creation

```php
// Wait for all shard movements to complete
$queue->setWaitForId($lastMoveQueueId); // 1014

// Update schema in database
$table->updateScheme($newSchema);

// Create distributed tables on all nodes
$table->createDistributedTablesFromSchema($queue, $newSchema);
```

**Distributed Table Creation Commands:**

```
Command 15: DROP TABLE IF EXISTS users OPTION force=1 ON Node1
   ├─ Queue ID: 1015
   ├─ Node: Node1
   └─ Wait For: 1014

Command 16: DROP TABLE IF EXISTS users OPTION force=1 ON Node2
   ├─ Queue ID: 1016
   ├─ Node: Node2
   └─ Wait For: 1014

Command 17: DROP TABLE IF EXISTS users OPTION force=1 ON Node3
   ├─ Queue ID: 1017
   ├─ Node: Node3
   └─ Wait For: 1014

Command 18: CREATE TABLE users type='distributed' local='users_s0' agent='Node3:users_s1' ON Node1
   ├─ Queue ID: 1018
   ├─ Node: Node1
   └─ Wait For: 1017

Command 19: CREATE TABLE users type='distributed' local='users_s2' agent='Node3:users_s1,users_s3' ON Node2
   ├─ Queue ID: 1019
   ├─ Node: Node2
   └─ Wait For: 1017

Command 20: CREATE TABLE users type='distributed' local='users_s1,users_s3' agent='Node1:users_s0|Node2:users_s2' ON Node3
   ├─ Queue ID: 1020
   ├─ Node: Node3
   └─ Wait For: 1017
```

### Timeline Visualization

```
Time: 0ms    100ms   200ms   300ms   400ms   500ms   600ms   700ms   800ms
      │       │       │       │       │       │       │       │       │
Node1 │ CREATE CLUSTER temp_move_1_abc123 ──────────────────────────────────►
      │       │ ADD users_s1 ────► DROP users_s1 ──► DROP TABLE ──► DELETE CLUSTER
      │       │                    │                  │             │
Node2 │       │ CREATE CLUSTER temp_move_3_def456 ────────────────────────────►
      │       │       │ ADD users_s3 ────► DROP users_s3 ──► DROP TABLE ──► DELETE
      │       │       │                    │                  │             │
Node3 │ CREATE TABLE users_s1 ──────────────────────────────────────────────►
      │ CREATE TABLE users_s3 ──────────────────────────────────────────────►
      │       │ JOIN temp_move_1 ──► [DATA SYNC] ──────────────────────────►
      │       │       │ JOIN temp_move_3 ──► [DATA SYNC] ──────────────────►
      │       │       │                                                     │
      └───────┴───────┴─────────────────────────────────────────────────────┴──►
                                                                              │
                                                                         SCHEMA UPDATE
                                                                         DISTRIBUTED TABLES
```

## Complete RF=2 New Node Addition Flow

### Scenario Setup

```
Initial State:
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│ S0, S1  │  │ S0, S1  │  │ (empty) │
└─────────┘  └─────────┘  └─────────┘
     ▲____________▲
     Cluster connections (RF=2)

Target State (after rebalancing):
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│ S0, S1  │  │ S0, S1  │  │ S0, S1  │
└─────────┘  └─────────┘  └─────────┘
     ▲____________▲____________▲
          Cluster connections (RF=3)
```

### Step-by-Step Data Flow

#### Step 1: Detection and Planning

```php
// Same detection logic as RF=1
$newNodes = $activeNodes->diff($schemaNodes); // [Node3]
$replicationFactor = $table->getReplicationFactor($schema); // 2
$newSchema = Util::rebalanceWithNewNodes($schema, $newNodes, $replicationFactor);
```

#### Step 2: Replica Addition (No Movement Required)

```php
// For RF>=2, we add replicas instead of moving shards
$table->handleRFNNewNodes($queue, $schema, $newSchema, $newNodes);
```

**Command Sequence for RF=2 New Node:**

```
Command 1: CREATE TABLE IF NOT EXISTS users_s0 (id bigint, title text) type='rt' ON Node3
   ├─ Queue ID: 2001
   ├─ Node: Node3
   └─ Wait For: none

Command 2: CREATE TABLE IF NOT EXISTS users_s1 (id bigint, title text) type='rt' ON Node3
   ├─ Queue ID: 2002
   ├─ Node: Node3
   └─ Wait For: none

Command 3: ALTER CLUSTER users_cluster_s0 ADD users_s0 ON Node1 (primary node)
   ├─ Queue ID: 2003
   ├─ Node: Node1
   └─ Wait For: 2001

Command 4: ALTER CLUSTER users_cluster_s1 ADD users_s1 ON Node1 (primary node)
   ├─ Queue ID: 2004
   ├─ Node: Node1
   └─ Wait For: 2002
```

#### Step 3: Distributed Table Recreation

```
Command 5: DROP TABLE IF EXISTS users OPTION force=1 ON Node1
   ├─ Queue ID: 2005
   ├─ Node: Node1
   └─ Wait For: 2004

Command 6: DROP TABLE IF EXISTS users OPTION force=1 ON Node2
   ├─ Queue ID: 2006
   ├─ Node: Node2
   └─ Wait For: 2004

Command 7: DROP TABLE IF EXISTS users OPTION force=1 ON Node3
   ├─ Queue ID: 2007
   ├─ Node: Node3
   └─ Wait For: 2004

Command 8: CREATE TABLE users type='distributed' local='users_s0,users_s1' agent='Node2:users_s0,users_s1|Node3:users_s0,users_s1' ON Node1
   ├─ Queue ID: 2008
   ├─ Node: Node1
   └─ Wait For: 2007

Command 9: CREATE TABLE users type='distributed' local='users_s0,users_s1' agent='Node1:users_s0,users_s1|Node3:users_s0,users_s1' ON Node2
   ├─ Queue ID: 2009
   ├─ Node: Node2
   └─ Wait For: 2007

Command 10: CREATE TABLE users type='distributed' local='users_s0,users_s1' agent='Node1:users_s0,users_s1|Node2:users_s0,users_s1' ON Node3
   ├─ Queue ID: 2010
   ├─ Node: Node3
   └─ Wait For: 2007
```

## Node Failure Recovery Flow

### Scenario Setup

```
Initial State (RF=2):
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│ S0, S1  │  │ S0, S1  │  │ S2, S3  │
└─────────┘  └─────────┘  └─────────┘
     ▲____________▲
     Cluster for S0,S1

Node2 Fails:
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│ S0, S1  │  │ (FAILED)│  │ S2, S3  │
└─────────┘  └─────────┘  └─────────┘

Target State (after recovery):
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│ S0, S1  │  │ (FAILED)│  │ S0,S1,  │
│         │  │         │  │ S2, S3  │
└─────────┘  └─────────┘  └─────────┘
```

### Step-by-Step Recovery Flow

#### Step 1: Failure Detection and Schema Calculation

```php
// Detect failed nodes
$inactiveNodes = $cluster->getInactiveNodes(); // [Node2]
$activeNodes = $allNodes->diff($inactiveNodes); // [Node1, Node3]

// Calculate new schema without failed nodes
$newSchema = Util::rebalanceShardingScheme($schema, $activeNodes);
```

#### Step 2: Orphaned Shard Recovery

```php
// Node2 failure means S0,S1 lose one replica
// Need to create new replicas on Node3
$table->handleFailedNodesRebalance($queue, $schema, $newSchema, $inactiveNodes);
```

**Command Sequence for Node Failure Recovery:**

```
Command 1: CREATE TABLE IF NOT EXISTS users_s0 (id bigint, title text) type='rt' ON Node3
   ├─ Queue ID: 3001
   ├─ Node: Node3
   └─ Wait For: none

Command 2: CREATE TABLE IF NOT EXISTS users_s1 (id bigint, title text) type='rt' ON Node3
   ├─ Queue ID: 3002
   ├─ Node: Node3
   └─ Wait For: none

Command 3: ALTER CLUSTER users_cluster_s0 ADD users_s0 ON Node1 (surviving primary)
   ├─ Queue ID: 3003
   ├─ Node: Node1
   └─ Wait For: 3001

Command 4: ALTER CLUSTER users_cluster_s1 ADD users_s1 ON Node1 (surviving primary)
   ├─ Queue ID: 3004
   ├─ Node: Node1
   └─ Wait For: 3002
```

#### Step 3: Cleanup Failed Node References

```
Command 5: DROP TABLE IF EXISTS users OPTION force=1 ON Node1
   ├─ Queue ID: 3005
   ├─ Node: Node1
   └─ Wait For: 3004

Command 6: DROP TABLE IF EXISTS users OPTION force=1 ON Node3
   ├─ Queue ID: 3006
   ├─ Node: Node3
   └─ Wait For: 3004

Command 7: CREATE TABLE users type='distributed' local='users_s0,users_s1' agent='Node3:users_s0,users_s1,users_s2,users_s3' ON Node1
   ├─ Queue ID: 3007
   ├─ Node: Node1
   └─ Wait For: 3006

Command 8: CREATE TABLE users type='distributed' local='users_s0,users_s1,users_s2,users_s3' agent='Node1:users_s0,users_s1' ON Node3
   ├─ Queue ID: 3008
   ├─ Node: Node3
   └─ Wait For: 3006
```

## Catastrophic Failure Recovery Flow

### Scenario Setup

```
Initial State (RF=1):
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│ S0, S1  │  │ S2, S3  │  │ S4, S5  │
└─────────┘  └─────────┘  └─────────┘

Catastrophic Failure (Only Node1 Survives):
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│ S0, S1  │  │ (FAILED)│  │ (FAILED)│
└─────────┘  └─────────┘  └─────────┘

Target State (Degraded Mode):
┌─────────┐  ┌─────────┐  ┌─────────┐
│ Node1   │  │ Node2   │  │ Node3   │
│S0,S1,   │  │ (FAILED)│  │ (FAILED)│
│S2,S3,   │  │         │  │         │
│S4,S5    │  │         │  │         │
└─────────┘  └─────────┘  └─────────┘
```

### Step-by-Step Catastrophic Recovery

#### Step 1: Detect Catastrophic Failure

```php
$inactiveNodes = $cluster->getInactiveNodes(); // [Node2, Node3]
$activeNodes = $allNodes->diff($inactiveNodes); // [Node1]

// Only one node surviving - enter degraded mode
$newSchema = Util::rebalanceShardingScheme($schema, $activeNodes);
```

#### Step 2: Create All Orphaned Shards Locally

**Command Sequence for Catastrophic Recovery:**

```
Command 1: CREATE TABLE IF NOT EXISTS users_s2 (id bigint, title text) type='rt' ON Node1
   ├─ Queue ID: 4001
   ├─ Node: Node1
   └─ Wait For: none

Command 2: CREATE TABLE IF NOT EXISTS users_s3 (id bigint, title text) type='rt' ON Node1
   ├─ Queue ID: 4002
   ├─ Node: Node1
   └─ Wait For: none

Command 3: CREATE TABLE IF NOT EXISTS users_s4 (id bigint, title text) type='rt' ON Node1
   ├─ Queue ID: 4003
   ├─ Node: Node1
   └─ Wait For: none

Command 4: CREATE TABLE IF NOT EXISTS users_s5 (id bigint, title text) type='rt' ON Node1
   ├─ Queue ID: 4004
   ├─ Node: Node1
   └─ Wait For: none

Command 5: DROP TABLE IF EXISTS users OPTION force=1 ON Node1
   ├─ Queue ID: 4005
   ├─ Node: Node1
   └─ Wait For: 4004

Command 6: CREATE TABLE users type='distributed' local='users_s0,users_s1,users_s2,users_s3,users_s4,users_s5' ON Node1
   ├─ Queue ID: 4006
   ├─ Node: Node1
   └─ Wait For: 4005
```

## Data Safety Mechanisms

### Critical Synchronization Points

1. **Table Creation Completion**: Ensures target tables exist before data operations
2. **Cluster Setup Completion**: Ensures clusters are ready before adding shards
3. **Data Sync Completion**: `ALTER CLUSTER ADD` is synchronous - data fully copied
4. **Source Cleanup**: Only removes source data after confirming target has data
5. **Schema Update**: Only updates schema after all data operations complete

### Error Recovery Patterns

```php
// If any critical operation fails, the entire sequence can be retried
try {
    $this->performShardMovement($queue, $shardId, $sourceNode, $targetNode);
} catch (\Throwable $e) {
    // Clean up any partial state
    $this->cleanupFailedMovement($shardId, $sourceNode, $targetNode);

    // Mark operation as failed for later retry
    $state->set("rebalance:{$this->name}", 'failed');

    throw $e;
}
```

### Idempotent Operations

All operations are designed to be idempotent:

- `CREATE TABLE IF NOT EXISTS` - Safe to retry
- `DROP TABLE IF EXISTS` - Safe to retry
- `ALTER CLUSTER ADD` - Handles existing shards gracefully
- `DELETE CLUSTER` - Handles non-existent clusters gracefully

This comprehensive data flow documentation shows how the sharding system orchestrates complex distributed operations while maintaining data safety and system consistency.
