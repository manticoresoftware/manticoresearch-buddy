# Rollback Mechanism Integration Tests

## Overview

This document describes executable integration test scenarios for validating the ManticoreSearch Buddy rollback mechanism. Tests are **shell scripts with SQL commands** that run against real Manticore nodes to verify the system behaves correctly under various failure and recovery conditions.

## Test Environment Setup

```bash
# Environment variables for test cluster
export NODE1=0
export NODE2=0
export NODE3=0
export CLUSTER_NAME=replication
export BASE_PORT=1306  # node1=1306, node2=2306, node3=3306
```

## Common Test Patterns

### Start Searchd on Nodes
```bash
export INSTANCE=1
# -- block: ../../base/replication/start-searchd-precach ---

export INSTANCE=2
# -- block: ../../base/replication/start-searchd-precach ---

export INSTANCE=3
# -- block: ../../base/replication/start-searchd-precach ---
```

### Create and Join Cluster
```bash
export CLUSTER_NAME=replication
# -- block: ../../base/replication/create-cluster ---
# -- block: ../../base/replication/join-cluster-on-all-nodes ---
```

### Verify Cluster Health
```bash
for port in ${BASE_PORT} $(($BASE_PORT+1000)) $(($BASE_PORT+2000)); do
  timeout 60 mysql -h0 -P$port -e "SHOW STATUS LIKE 'cluster_${CLUSTER_NAME}_status'\G" \
    > /tmp/status_$port.log 2>/dev/null
  grep -q "Value: primary" /tmp/status_$port.log && echo "Port $port: Node synced"
done
```

### Check Table Distribution
```bash
for i in 1 2 3; do
  mysql -h0 -P$(($BASE_PORT + $(($i-1))*1000)) -e "show tables from system\G"
done | grep 'system.{table}_s' | sort -V
```

---

## Scenario 1: Automatic Rollback on Table Creation Failure

### Configuration
- **Nodes**: node1 (9308), node2 (9318), node3 (9328)
- **Shards**: 2
- **RF**: 2

### Preconditions
- All 3 nodes running and healthy
- No existing test tables
- Buddy instances active on all nodes

### Step-by-Step Procedure

#### Step 1: Verify Initial State
```bash
# On node1
mysql -h node1 -P 9308 -e "SHOW CLUSTERS;"

# Expected: Empty or existing clusters only
```

#### Step 2: Start Table Creation
```bash
# Trigger sharded table creation via Buddy API or direct SQL
curl -X POST "http://node1:8080" -d '{
  "query": "CREATE SHARDED TABLE test_products ON CLUSTER mycluster
            (id bigint, name string, price double)
            SHARDS 2 REPLICAS 2"
}'
```

#### Step 3: Kill Node Mid-Creation (Simulate Failure)
```bash
# Wait briefly for initial shard creation
sleep 2

# Kill node2 to simulate failure during shard replication
ssh node2 "pkill -f searchd" 2>/dev/null || true

# Verify node is down
mysql -h node2 -P 9318 -e "SHOW TABLES;" 2>/dev/null
# Expected: Connection refused or timeout
```

#### Step 4: Observe Automatic Rollback
```bash
# Wait for Buddy to detect failure and trigger rollback
sleep 10
```

#### Step 5: Verify Rollback Executed
```sql
-- On any surviving node
mysql> SELECT id, node, query, rollback_query, status, operation_group
     FROM system.sharding_queue
     WHERE operation_group LIKE '%test_products%'
     ORDER BY id DESC;

-- Expected observations:
-- 1. Commands with status = 'rollback_executed' or 'error'
-- 2. rollback_query field contains DROP TABLE commands
```

### Verification Checkpoints

| Check | Query | Expected Result |
|-------|-------|-----------------|
| No shard tables exist | `SHOW TABLES LIKE 'test_products%'` | Empty result |
| No orphaned clusters | `SHOW CLUSTERS` | No temp_move clusters |
| Queue shows rollback | `SELECT status FROM system.sharding_queue WHERE operation_group LIKE '%test_products%'` | rollback_executed or similar |
| Sharding table clean | `SELECT * FROM system.sharding_table WHERE table = 'test_products'` | Empty |

### Expected Outcomes
- All shard tables (`test_products_s0`, `test_products_s1`) are dropped
- No temporary clusters remain
- Queue entries show rollback was attempted/executed
- Cluster state is consistent

### Cleanup
```sql
-- Manual cleanup if any artifacts remain
DROP TABLE IF EXISTS test_products;
DROP TABLE IF EXISTS test_products_s0;
DROP TABLE IF EXISTS test_products_s1;

-- Clean queue (use with caution)
DELETE FROM system.sharding_queue WHERE operation_group LIKE '%test_products%';
```

---

## Scenario 2: Manual Rollback via stop_rebalancing Command

### Configuration
- **Nodes**: node1 (9308), node2 (9318), node3 (9328), node4 (9338)
- **Shards**: 3
- **RF**: 2

### Preconditions
- 4-node cluster running
- Sharded table already distributed
- Rebalancing operation in progress or pending

### Step-by-Step Procedure

#### Step 1: Start Rebalancing Operation
```bash
# Trigger rebalancing (e.g., after adding new node)
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT rebalance_shards('\\''existing_table'\\'')"
}'
```

#### Step 2: Verify Rebalancing Started
```sql
-- Check if rebalancing is in progress
SELECT * FROM system.sharding_queue WHERE status <> 'processed';
-- Expected: Queue items with pending status
```

#### Step 3: Issue Stop/Rollback Command
```bash
# Graceful stop (completes current operation then stops)
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT stop_rebalancing('\\''existing_table'\\'', graceful=true)"
}'

# Or immediate stop with rollback
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT stop_rebalancing('\\''existing_table'\\'', graceful=false)"
}'
```

#### Step 4: Verify Rollback Executed
```sql
-- Check queue for rollback commands
SELECT id, node, query, rollback_query, status, operation_group
FROM system.sharding_queue
WHERE operation_group LIKE '%rebalance%'
ORDER BY id DESC;

-- Expected:
-- 1. Commands marked with rollback status
-- 2. rollback_query populated for affected operations
```

### Verification Checkpoints

| Check | Query | Expected Result |
|-------|-------|-----------------|
| Rebalancing stopped | `SELECT * FROM system.sharding_queue WHERE status = 'processing'` | No processing items for this table |
| Rollback commands executed | `SELECT status FROM system.sharding_queue WHERE operation_group LIKE '%rebalance%'` | rollback_executed |
| Data consistent | `SELECT COUNT(*) FROM existing_table` | Same as before rebalancing |

### Expected Outcomes
- Rebalancing stops (gracefully or immediately)
- Rollback commands execute for completed operations
- System returns to consistent state before rebalancing started

---

## Scenario 3: ALTER CLUSTER ADD TABLE Timeout with Background Sync Verification

### Configuration
- **Nodes**: node1 (9308), node2 (9318)
- **Shards**: 1
- **RF**: 2

### Preconditions
- 2-node cluster running
- Large table being created or synced

### Background: The Issue

`ALTER CLUSTER ADD TABLE` can block for extended periods during table synchronization:
- Query may timeout/fail on client side
- But background synchronization continues
- Without verification, system might think it failed when it actually succeeded

### Step-by-Step Procedure

#### Step 1: Create Large Table on Node1
```sql
-- On node1
CREATE TABLE large_table (
    id bigint,
    data string,
    payload string
) type='rt' ENGINE='manticore';

-- Insert large amount of data to ensure sync takes time
INSERT INTO large_table (id, data, payload)
SELECT id, repeat('x', 1000), repeat('y', 1000)
FROM system.numbers LIMIT 100000;
```

#### Step 2: Create Cluster
```sql
-- On node1
CREATE CLUSTER sync_test 'sync_test' as path;
```

#### Step 3: Add Table to Cluster with Timeout Simulation

**Option A: Natural Timeout (if table is large enough)**
```bash
# Run with short timeout to trigger the condition
timeout 2 mysql -h node1 -P 9308 -e "
ALTER CLUSTER sync_test ADD large_table
" 2>/dev/null

# This may fail with timeout but sync might continue
```

**Option B: Network-Level Delay (if infrastructure allows)**
```bash
# Simulate network latency on node2 (requires network admin access)
# Add 10 second delay to packets to node2
tc qdisc add dev eth0 root netem delay 10000ms

# Now run the ALTER CLUSTER ADD
mysql -h node1 -P 9308 -e "ALTER CLUSTER sync_test ADD large_table;"

# Remove delay
tc qdisc del dev eth0 root
```

#### Step 4: Verify Despite Timeout
```bash
# Wait a bit for background sync to complete
sleep 15

# Check if query is still running
mysql -h node1 -P 9308 -e "SHOW QUERIES;"
```

```sql
-- Check cluster status - table should be in cluster despite timeout
SHOW CLUSTERS LIKE 'sync_test';
-- Expected: large_table should appear in tables list

-- Verify table is accessible on node2
mysql -h node2 -P 9318 -e "SELECT COUNT(*) FROM sync_test:large_table;"
-- Expected: Count matches source table (100000)
```

#### Step 5: Check Queue Verification Logic
```sql
-- The queue should have marked this as 'processed' (verified)
SELECT * FROM system.sharding_queue
WHERE query LIKE '%ALTER CLUSTER sync_test ADD%'
ORDER BY id DESC;

-- Expected: status = 'processed' even if the query returned error
```

### Verification Checkpoints

| Check | Query | Expected Result |
|-------|-------|-----------------|
| Table in cluster | `SHOW CLUSTERS LIKE 'sync_test'` | large_table in tables |
| Data synced | `SELECT COUNT(*) FROM sync_test:large_table` | Count = 100000 |
| Queue marked processed | `SELECT status FROM system.sharding_queue WHERE query LIKE '%ALTER CLUSTER%ADD%'` | processed |
| No false retries | Check if Buddy re-queued the command | Should NOT re-queue |

### Expected Outcomes
- Despite timeout/error on ALTER CLUSTER ADD TABLE:
  - Table is actually synced to all cluster nodes
  - Queue verification marks it as 'processed'
  - Buddy does NOT incorrectly retry the operation

### Cleanup
```sql
DROP TABLE IF EXISTS large_table;
DELETE CLUSTER IF EXISTS sync_test;
```

---

## Scenario 4: Node Failure Mid-Creation with Automatic Recovery

### Configuration
- **Nodes**: node1 (9308), node2 (9318), node3 (9328)
- **Shards**: 2
- **RF**: 2

### Preconditions
- 3-node cluster running
- RF=2 configured for tables

### Step-by-Step Procedure

#### Step 1: Start Table Creation
```bash
# Start table creation in background
curl -X POST "http://node1:8080" -d '{
  "query": "CREATE SHARDED TABLE orders ON CLUSTER mycluster
            (order_id bigint, customer_id bigint, total decimal)
            SHARDS 2 REPLICAS 2"
}' &
CREATION_PID=$!
```

#### Step 2: Kill Node Mid-Operation
```bash
# Wait briefly for initial shard creation
sleep 2

# Kill node2 (simulating failure during replication)
ssh node2 "pkill -f searchd" 2>/dev/null || true
echo "Node2 killed at $(date)"

# Wait for Buddy to detect
sleep 5

# Restart node2
ssh node2 "searchd --config /etc/manticore/node2.conf &" 2>/dev/null || true
echo "Node2 restarted at $(date)"
```

#### Step 3: Wait for Automatic Recovery
```bash
# Wait for Buddy to detect and handle recovery
sleep 15

# Check rebalancing status
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT rebalancing_status('\\''orders'\\'')"
}'
```

### Verification Checkpoints

| Check | Query | Expected Result |
|-------|-------|-----------------|
| Table exists | `SHOW TABLES LIKE 'orders'` | Table exists |
| All shards distributed | `SELECT * FROM system.sharding_table WHERE table = 'orders'` | Shards on multiple nodes |
| RF maintained | Check replica count per shard | RF=2 for all shards |
| Data accessible | `SELECT COUNT(*) FROM orders` | Count > 0 |
| Cluster healthy | `SHOW STATUS LIKE 'cluster_mycluster_status'` | primary |

```sql
-- Verify replication factor on each shard
SELECT shards, COUNT(*) as replica_count
FROM system.sharding_table
WHERE table = 'orders'
GROUP BY shards;
-- Expected: replica_count = 2 for each shard
```

### Expected Outcomes
- Buddy detects node failure during replication
- Replication continues when node restarts
- RF=2 is maintained after recovery
- No manual intervention required

### Cleanup
```sql
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS orders_s0;
DROP TABLE IF EXISTS orders_s1;
```

---

## Scenario 5: New Node Addition with RF>=2 Replication

### Configuration
- **Initial**: node1 (9308), node2 (9318) - 2 nodes
- **After**: node1, node2, node3 (9328) - 3 nodes
- **Shards**: 2
- **RF**: 2 (before), becomes RF=2 (after but distributed across 3 nodes)

### Preconditions
- 2-node cluster with existing sharded table
- 3rd node ready but not in cluster
- Table has RF=2 (each shard on both nodes)

### Step-by-Step Procedure

#### Step 1: Verify Initial State (2 Nodes)
```sql
-- On node1
SHOW CLUSTERS;
-- Expected: mycluster exists

SELECT node, shards FROM system.sharding_table WHERE table = 'existing_table' ORDER BY node;
-- Expected:
-- node1: 0,1 (or similar)
-- node2: 0,1
```

#### Step 2: Add New Node to Cluster
```bash
curl -X POST "http://node1:8080" -d '{
  "query": "ADD NODE node3:9318 TO CLUSTER mycluster"
}'
```

#### Step 3: Trigger Replication to New Node
```bash
# Trigger rebalancing to add replicas on new node
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT rebalance_shards('\\''existing_table'\\'')"
}'
```

#### Step 4: Monitor Replication Progress
```bash
sleep 10

# Check queue for replication commands
mysql -h node1 -P 9308 -e "SELECT * FROM system.sharding_queue WHERE status <> 'processed';"
```

### Verification Checkpoints

| Check | Query | Expected Result |
|-------|-------|-----------------|
| Node3 in cluster | `SHOW STATUS LIKE 'cluster_mycluster_nodes_set'` | node1,node2,node3 |
| Table on all 3 nodes | `SELECT node, shards FROM system.sharding_table WHERE table = 'existing_table'` | All 3 nodes listed |
| Data on new node | `mysql -h node3 -P 9318 -e "SELECT COUNT(*) FROM existing_table;"` | Same as other nodes |
| RF still 2 | `SELECT shards, COUNT(*) FROM system.sharding_table GROUP BY shards` | 2 replicas per shard |

```sql
-- Detailed shard distribution
SELECT node, shards FROM system.sharding_table
WHERE table = 'existing_table'
ORDER BY node;

-- Expected after rebalancing:
-- node1: shard(s)
-- node2: shard(s)
-- node3: shard(s) (all shards distributed across 3 nodes)
```

### Rollback Verification (If Addition Fails)
```bash
# If something goes wrong during node add
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT rollback_node_add('\\''node3'\\'')"
}'
```

```sql
-- Verify rollback
SELECT * FROM system.sharding_queue WHERE operation_group LIKE '%node_add%';
-- Expected: Rollback commands executed
-- Node3 should not have table data
```

### Expected Outcomes
- New node joins cluster
- Rebalancing creates replicas on new node
- RF=2 maintained (each shard on 2 of 3 nodes)
- Data accessible from all nodes

### Cleanup
```sql
-- Remove node from cluster (if needed for cleanup)
ALTER CLUSTER mycluster UPDATE nodes;  -- If node3 should be removed
```

---

## Scenario 6: RF=1 Shard Movement with Intermediate Cluster

### Configuration
- **Nodes**: node1 (9308), node2 (9318), node3 (9328)
- **Shards**: 2
- **RF**: 1 (single replica per shard)

### Preconditions
- 3-node cluster with RF=1 tables
- Each shard exists on only one node
- Data MUST be preserved during movement

### Background: Why Intermediate Cluster?

With RF=1, you cannot simply copy a shard - you need to:
1. Create temporary cluster on source node (where data IS)
2. Add shard to temp cluster
3. Target node joins temp cluster (data syncs)
4. Remove shard from temp cluster
5. Drop original shard (now data is on target)

### Step-by-Step Procedure

#### Step 1: Check Initial Distribution
```sql
SELECT node, shards FROM system.sharding_table WHERE table = 'inventory' ORDER BY node;

-- Expected (example):
-- node1: 0
-- node2: 1
-- node3: (empty)
```

#### Step 2: Trigger Shard Movement
```bash
# Move shard 0 from node1 to node3
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT move_shard('\\''inventory'\\'', 0, '\\''node1'\\'', '\\''node3'\\'')"
}'
```

#### Step 3: Monitor Movement
```bash
sleep 5

# Check queue for movement commands
mysql -h node1 -P 9308 -e "SELECT * FROM system.sharding_queue
WHERE operation_group LIKE '%move_shard%'
ORDER BY id DESC;"
```

### Verification Checkpoints

| Check | Query | Expected Result |
|-------|-------|-----------------|
| New distribution | `SELECT node, shards FROM system.sharding_table WHERE table = 'inventory'` | Shard 0 on node3 |
| Data preserved | `SELECT COUNT(*) FROM inventory` | Same count as before |
| Temp cluster cleaned | `SHOW CLUSTERS` | No temp_move clusters |
| No rollback | `SELECT * FROM system.sharding_queue WHERE operation_group LIKE '%move_shard%' AND status = 'rollback_executed'` | Empty |

```sql
-- Verify data integrity after move
SELECT COUNT(*) FROM inventory;
-- Should return same count as before movement

-- Check each shard individually
SELECT * FROM system.sharding_table ORDER BY node;
```

### Expected Outcomes
- Shard 0 moves from node1 to node3
- Intermediate temp cluster is created and cleaned up
- Data is preserved during movement (no loss)
- RF=1 maintained (each shard on exactly one node)

### Cleanup
```sql
-- Manual cleanup if temp cluster wasn't cleaned
DELETE CLUSTER IF EXISTS temp_move_0_*;
```

---

## Scenario 7: CleanupManager - Orphaned Resources Cleanup

### Configuration
- **Nodes**: Any cluster configuration
- **Purpose**: Test cleanup of stale/failed operations

### Preconditions
- Previous test left orphaned resources OR
- Simulate failed operation by killing Buddy mid-operation

### Step-by-Step Procedure

#### Step 1: Identify Orphaned Resources
```sql
-- Check for temporary clusters older than 1 hour
-- (In real scenario, clusters with names like 'temp_move_*' or 'temp_*')

SHOW CLUSTERS;
-- Look for clusters that shouldn't exist

-- Check queue for failed/stale operations
SELECT * FROM system.sharding_queue
WHERE status IN ('error', 'created')
  AND created_at < UNIX_TIMESTAMP() - 86400;  -- Older than 24 hours
```

#### Step 2: Trigger Cleanup
```bash
# Via Buddy API
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT cleanup_orphaned_clusters()"
}'

curl -X POST "http://node1:8080" -d '{
  "query": "SELECT cleanup_failed_operations()"
}'
```

#### Step 3: Verify Cleanup Results
```sql
-- Check queue after cleanup
SELECT * FROM system.sharding_queue
WHERE operation_group LIKE '%temp_%'
LIMIT 10;

-- Verify clusters are gone
SHOW CLUSTERS;
```

### Verification Checkpoints

| Check | Query | Expected Result |
|-------|-------|-----------------|
| Temp clusters removed | `SHOW CLUSTERS LIKE 'temp_%'` | Empty |
| Failed queue items cleaned | `SELECT COUNT(*) FROM system.sharding_queue WHERE status = 'error' AND created_at < ...` | Count decreased |
| Stale state entries | `SELECT * FROM system.sharding_state` | No stale entries |

### Cleanup Types Supported

```sql
-- Cleanup orphaned temporary clusters (>1 hour old)
SELECT cleanup_orphaned_clusters();

-- Cleanup failed operation groups (>24 hours old)
SELECT cleanup_failed_operations();

-- Cleanup expired queue items (>7 days old)
SELECT cleanup_expired_queue_items();

-- Cleanup stale state entries (>30 days old)
SELECT cleanup_stale_state_entries();
```

---

## Summary: Verification Checklist for All Scenarios

### Common Verification Queries

```sql
-- 1. Cluster Status
SHOW CLUSTERS;
SHOW STATUS LIKE 'cluster_%_status';
SHOW STATUS LIKE 'cluster_%_nodes_set';

-- 2. Table Distribution
SHOW TABLES;
SELECT * FROM system.sharding_table;
SELECT * FROM system.sharding_table WHERE table = '{table_name}';

-- 3. Queue State
SELECT * FROM system.sharding_queue ORDER BY id DESC;
SELECT * FROM system.sharding_queue WHERE status <> 'processed';
SELECT * FROM system.sharding_queue WHERE operation_group = '{group}';

-- 4. Data Integrity
SELECT COUNT(*) FROM {distributed_table};
SELECT COUNT(*) FROM {shard_table};
```

### Expected Status Values

| Status | Meaning |
|--------|---------|
| `created` | Command queued, not processed yet |
| `processing` | Currently being executed |
| `processed` | Successfully completed |
| `error` | Failed (will be retried) |
| `rollback_executed` | Rollback was triggered and completed |

### Rollback Command Patterns

| Forward Command | Rollback Command |
|----------------|------------------|
| `CREATE TABLE {name}` | `DROP TABLE IF EXISTS {name}` |
| `CREATE CLUSTER {name}` | `DELETE CLUSTER {name}` |
| `ALTER CLUSTER {c} ADD {t}` | `ALTER CLUSTER {c} DROP {t}` |
| `ALTER CLUSTER {c} DROP {t}` | `ALTER CLUSTER {c} ADD {t}` |
| `JOIN CLUSTER {c}` | `DELETE CLUSTER {c}` |
| `DROP TABLE {name}` | (none - destructive) |
| `DELETE CLUSTER {name}` | (none - destructive) |

---

## Test Execution Order Recommendation

```
┌─────────────────────────────────────────────────────────────────┐
│                    RECOMMENDED EXECUTION ORDER                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  1. Scenario 3: ALTER CLUSTER ADD verification                  │
│     (Foundation - verifies sync detection)                       │
│                    ▼                                            │
│  2. Scenario 1: Automatic rollback on creation failure         │
│     (Core rollback mechanism)                                   │
│                    ▼                                            │
│  3. Scenario 4: Node failure with recovery                      │
│     (Failure handling)                                          │
│                    ▼                                            │
│  4. Scenario 5: New node addition                               │
│     (Cluster scaling)                                           │
│                    ▼                                            │
│  5. Scenario 6: RF=1 shard movement                            │
│     (Data preservation)                                          │
│                    ▼                                            │
│  6. Scenario 2: Manual rollback/stop rebalancing               │
│     (User-initiated rollback)                                   │
│                    ▼                                            │
│  7. Scenario 7: CleanupManager                                 │
│     (Resource cleanup)                                           │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Notes

1. **Timing**: Some scenarios require waiting for automatic detection/recovery. Buddy typically checks every few seconds.

2. **Queue Processing**: Queue is processed by Buddy background worker. You may need to wait or trigger manual processing.

3. **Logging**: Check Buddy logs for detailed execution info:
   ```bash
   tail -f /var/log/manticoresearch/buddy.log
   ```

4. **Partial Success**: Rollback continues even if some commands fail. Check logs for details.

5. **Idempotency**: All rollback commands use IF EXISTS / IF NOT EXISTS where possible to ensure safe retry.
