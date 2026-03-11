# Rollback Mechanism Integration Tests

## Overview

This document describes high-level integration test scenarios for ManticoreSearch Buddy's rollback mechanism. Tests focus on:
- Automatic rollback during table creation failures
- Node failure recovery with RF>=2 replication
- New node addition with proper replication
- Shard movement and rebalancing

---

## Common Setup (All Scenarios)

### Initial Cluster State
```bash
# Nodes: node1 (9308), node2 (9318), node3 (9328)
# Replication Factor: RF=2 (or as specified per scenario)
# Buddy instances running on all nodes
```

### Verification Queries (Used Across All Scenarios)

```sql
-- Check cluster status
SHOW CLUSTERS;

-- Check tables across all nodes
SHOW TABLES;

-- Check specific table distribution
SELECT * FROM system.sharding_table;

-- Check queue status
SELECT * FROM system.sharding_queue;

-- Check queue with rollback info
SELECT id, node, query, rollback_query, status, operation_group
FROM system.sharding_queue
ORDER BY id DESC;
```

---

## Scenario 1: Rollback During Table Creation (Partial Failure)

### Configuration
- **Nodes**: 3 (node1, node2, node3)
- **Shards**: 2
- **RF**: 2

### Preconditions
- All nodes running and connected
- Buddy instances active on all nodes
- No existing test tables

### Step-by-Step Procedure

#### Step 1: Initial State Verification
```bash
# Verify all nodes are healthy
mysql -h node1 -P 9308 -e "SHOW CLUSTERS;"
mysql -h node2 -P 9318 -e "SHOW CLUSTERS;"
mysql -h node3 -P 9328 -e "SHOW CLUSTERS;"
```

```sql
-- Expected: Empty or existing clusters only
```

#### Step 2: Start Table Creation
```bash
# Trigger sharded table creation via Buddy
curl -X POST "http://node1:8080" -d '{
  "query": "CREATE SHARDED TABLE test_products ON CLUSTER mycluster
            (id bigint, name string, price double)
            SHARDS 2 REPLICAS 2"
}'
```

#### Step 3: Simulate Mid-Creation Failure
```bash
# Option A: Kill Buddy process mid-operation
pkill -f manticoresearch-buddy

# Option B: Kill target node during shard creation
ssh node2 "pkill searchd"
sleep 2
ssh node2 "searchd --config /etc/manticore/node2.conf &"
```

#### Step 4: Trigger Rollback
```bash
# Via Buddy API - stop rebalancing with immediate rollback
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT stop_rebalancing('\''test_products'\'')"
}'

# Or via direct SQL if supported
```

#### Step 5: Verification
```sql
-- Check 1: All shard tables dropped
SELECT name FROM system.tables
WHERE name LIKE '%test_products%';

-- Expected: NO tables with test_products prefix

-- Check 2: No orphaned clusters
SHOW CLUSTERS;

-- Expected: No temporary clusters from creation

-- Check 3: Queue shows rollback executed
SELECT id, node, status, rollback_query, operation_group
FROM system.sharding_queue
WHERE operation_group LIKE '%test_products%';

-- Expected: status = 'rollback_executed' or similar
-- rollback_query contains DROP TABLE commands

-- Check 4: Sharding table clean
SELECT * FROM system.sharding_table
WHERE table_name = 'test_products';

-- Expected: NO entries for test_products
```

### Cleanup
```sql
-- Manual cleanup if needed
DROP TABLE IF EXISTS test_products;
DROP TABLE IF EXISTS test_products_s0;
DROP TABLE IF EXISTS test_products_s1;
```

---

## Scenario 2: Node Failure During Creation + Recovery

### Configuration
- **Nodes**: 3 (node1, node2, node3)
- **Shards**: 2
- **RF**: 2

### Preconditions
- 3-node cluster running
- RF=2 configured

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
ssh node2 "pkill searchd"
echo "Node2 killed at $(date)"

# Wait
sleep 3

# Restart node2
ssh node2 "searchd --config /etc/manticore/node2.conf &"
echo "Node2 restarted at $(date)"
```

#### Step 3: Wait for Recovery
```bash
# Wait for Buddy to detect and handle
sleep 10

# Check rebalancing status
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT rebalancing_status('\''orders'\'')"
}'
```

#### Step 4: Verification
```sql
-- Check 1: Table exists
SHOW TABLES LIKE 'orders';

-- Expected: Table exists

-- Check 2: All shards exist on multiple nodes
SELECT * FROM system.sharding_table WHERE table = 'orders';

-- Expected: Shards distributed, RF=2 maintained

-- Check 3: Verify data on all nodes
-- On node1:
SELECT * FROM orders LIMIT 1;
-- On node2:
SELECT * FROM orders LIMIT 1;
-- On node3:
SELECT * FROM orders LIMIT 1;

-- All should return same results (data replicated)

-- Check 4: Cluster health
SHOW CLUSTERS LIKE 'mycluster';

-- Expected: Cluster status primary/active
```

### Expected Outcome
- Buddy detects node failure during replication
- Replication continues when node restarts
- RF=2 maintained after recovery
- No manual intervention required

---

## Scenario 3: New Node Addition (RF>=2)

### Configuration
- **Initial**: 2 nodes (node1, node2)
- **After**: 3 nodes (node1, node2, node3)
- **Shards**: 2
- **RF**: 2

### Preconditions
- 2-node cluster with existing sharded table
- 3rd node ready but not in cluster

### Step-by-Step Procedure

#### Step 1: Initial State (2 Nodes)
```sql
-- Verify current state
SHOW CLUSTERS;
SELECT * FROM system.sharding_table WHERE table = 'existing_table';

-- Expected: 2 nodes, RF=2, table distributed
```

#### Step 2: Add New Node to Cluster
```bash
# Add node3 to cluster
curl -X POST "http://node1:8080" -d '{
  "query": "ADD NODE node3:9318 TO CLUSTER mycluster"
}'
```

#### Step 3: Trigger Replication to New Node
```bash
# Trigger rebalancing to add replicas on new node
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT rebalance_shards('\''existing_table'\'')"
}'
```

#### Step 4: Verification
```sql
-- Check 1: Node3 is in cluster
SHOW STATUS LIKE 'cluster_mycluster_nodes_set';

-- Expected: node1,node2,node3

-- Check 2: Table on all 3 nodes
SELECT node, shards FROM system.sharding_table
WHERE table = 'existing_table'
ORDER BY node;

-- Expected:
-- node1: 0,1 (or similar)
-- node2: 0,1
-- node3: 0,1

-- Check 3: Verify data exists on new node
mysql -h node3 -P 9318 -e "SELECT COUNT(*) FROM existing_table;"

-- Expected: Same count as other nodes

-- Check 4: RF maintained
SELECT
  shards,
  COUNT(*) as replica_count
FROM system.sharding_table
WHERE table = 'existing_table'
GROUP BY shards;

-- Expected: replica_count = 3 for each shard
```

### Rollback Verification (If Addition Fails)
```bash
# If something goes wrong during node add
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT rollback_node_add('\''node3'\'')"
}'
```

```sql
-- Verify rollback
SELECT * FROM system.sharding_queue
WHERE operation_group = 'node_add_node3';

-- Expected: Rollback commands executed
-- New node should not have table data
```

---

## Scenario 4: Node Failure Mid-Replication (RF=2)

### Configuration
- **Nodes**: 3
- **Shards**: 2
- **RF**: 2

### Step-by-Step Procedure

#### Step 1: Start Replication Operation
```bash
# Trigger shard replication
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT replicate_shard('\''products'\'', 0, '\''node2'\'', '\''node3'\'')"
}' &
```

#### Step 2: Kill Target Node Mid-Replication
```bash
sleep 1
ssh node3 "pkill searchd"
echo "Node3 killed during replication"
sleep 2
ssh node3 "searchd --config /etc/manticore/node3.conf &"
echo "Node3 restarted"
```

#### Step 3: Wait for Automatic Recovery
```bash
# Wait for Buddy to handle
sleep 15

# Check replication status
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT replication_status('\''products'\'')"
}'
```

#### Step 4: Verification
```sql
-- Check 1: Replication completed
SELECT node, shards FROM system.sharding_table
WHERE table = 'products';

-- Expected: All nodes have all shards (RF=2 or more)

-- Check 2: No pending queue items
SELECT COUNT(*) as pending
FROM system.sharding_queue
WHERE status NOT IN ('processed', 'error');

-- Expected: 0 or minimal pending

-- Check 3: Verify queue processed
SELECT id, node, status, rollback_query
FROM system.sharding_queue
ORDER BY id DESC
LIMIT 10;

-- Expected: Most items have status='processed'
```

---

## Scenario 5: Shard Movement (RF=2)

### Configuration
- **Nodes**: 4
- **Shards**: 3
- **RF**: 2

### Preconditions
- 4-node cluster with balanced shards
- RF=2 maintained

### Step-by-Step Procedure

#### Step 1: Check Initial Distribution
```sql
SELECT node, shards FROM system.sharding_table
WHERE table = 'inventory'
ORDER BY node;
```

```bash
# Expected output (example):
# node1: 0
# node2: 0,1
# node3: 1,2
# node4: 2
```

#### Step 2: Trigger Shard Movement
```bash
# Move shard 0 from node1 to node4
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT move_shard('\''inventory'\'', 0, '\''node1'\'', '\''node4'\'')"
}'
```

#### Step 3: Monitor Movement
```bash
sleep 5

# Check rebalancing progress
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT get_rebalancing_progress('\''inventory'\'')"
}'
```

#### Step 4: Verification
```sql
-- Check 1: New distribution
SELECT node, shards FROM system.sharding_table
WHERE table = 'inventory'
ORDER BY node;

-- Expected:
-- node1: (shard 0 removed)
-- node2: 0,1 (or 0,1,2 - updated)
-- node3: 1,2
-- node4: 0,2 (or all shards)

-- Check 2: RF still 2 for all shards
SELECT
  shards,
  COUNT(*) as replica_count
FROM system.sharding_table
WHERE table = 'inventory'
GROUP BY shards;

-- Expected: replica_count = 2 for each shard

-- Check 3: Data integrity
SELECT COUNT(*) FROM inventory;

-- Expected: Same count before/after movement

-- Check 4: No rollback triggered (success case)
SELECT * FROM system.sharding_queue
WHERE operation_group LIKE '%move_shard%'
ORDER BY id DESC;

-- Expected: No rollback executed
```

---

## Scenario 6: Multi-Shard Creation with Rollback

### Configuration
- **Nodes**: 5
- **Shards**: 4
- **RF**: 2

### Step-by-Step Procedure

#### Step 1: Start Large Table Creation
```bash
curl -X POST "http://node1:8080" -d '{
  "query": "CREATE SHARDED TABLE big_table ON CLUSTER mycluster
            (id bigint, data string, timestamp timestamp)
            SHARDS 4 REPLICAS 2"
}' &
```

#### Step 2: Simulate Failure
```bash
# Wait for partial creation
sleep 3

# Kill Buddy to simulate failure
pkill -f manticoresearch-buddy

# Wait
sleep 2

# Restart Buddy
manticoresearch-buddy --config /path/to/buddy.conf &
```

#### Step 3: Trigger Rollback
```bash
# Via API
curl -X POST "http://node1:8080" -d '{
  "query": "SELECT rollback_table_creation('\''big_table'\'')"
}'
```

#### Step 4: Verification
```sql
-- Check 1: No shard tables exist
SHOW TABLES LIKE 'big_table%';

-- Expected: NO tables

-- Check 2: Queue shows all rollbacks executed
SELECT
  node,
  query,
  rollback_query,
  status
FROM system.sharding_queue
WHERE operation_group LIKE '%big_table%'
ORDER BY id DESC;

-- Expected:
-- All rollback_query values populated
-- All status = 'processed' or 'rollback_executed'

-- Check 3: No entries in sharding_table
SELECT COUNT(*) FROM system.sharding_table
WHERE table = 'big_table';

-- Expected: 0

-- Check 4: Cluster state clean
SHOW CLUSTERS;

-- Expected: No temporary clusters
```

---

## Rollback Command Reference

### Common Rollback Commands Used
```sql
-- Table creation rollback
DROP TABLE IF EXISTS {table_name};

-- Cluster creation rollback
DELETE CLUSTER {cluster_name};

-- ALTER CLUSTER ADD rollback
ALTER CLUSTER {cluster_name} DROP {table_name};

-- ALTER CLUSTER DROP rollback (inverse)
ALTER CLUSTER {cluster_name} ADD {table_name};

-- Destructive operations (no rollback)
DROP TABLE {table_name};  -- Can't undo
DELETE CLUSTER {name};     -- Can't undo
```

### Queue Status Values
- `created`: Command queued, not processed
- `processing`: Currently being executed
- `processed`: Successfully completed
- `error`: Failed (will be retried)
- `rollback_executed`: Rollback was triggered

---

## Cleanup Procedures

### Standard Cleanup
```sql
-- Drop test tables
DROP TABLE IF EXISTS test_products;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS existing_table;
DROP TABLE IF EXISTS big_table;
DROP TABLE IF EXISTS inventory;

-- Drop associated shards
DROP TABLE IF EXISTS test_products_s0;
DROP TABLE IF EXISTS test_products_s1;
DROP TABLE IF EXISTS orders_s0;
DROP TABLE IF EXISTS orders_s1;
-- ... etc

-- Clean queue (use with caution)
DELETE FROM system.sharding_queue
WHERE operation_group LIKE '%test_%';
```

### Emergency Cleanup
```bash
# If Buddy is unresponsive
# 1. Kill all Buddy processes
pkill -f manticoresearch-buddy

# 2. Manually clean via MySQL protocol
mysql -h node1 -P 9308 -e "
  DELETE FROM system.sharding_queue WHERE operation_group LIKE '%test_%';
  DROP TABLE IF EXISTS test_table;
  -- etc
"

# 3. Restart Buddy
manticoresearch-buddy --config /path/to/buddy.conf &
```

---

## Success Criteria Summary

| Scenario | Key Verification |
|----------|-----------------|
| Scenario 1 | All shard tables dropped, queue cleaned, no orphaned clusters |
| Scenario 2 | RF=2 maintained, data replicated, no manual intervention |
| Scenario 3 | New node has all shards, RF maintained, data present |
| Scenario 4 | Replication completed, queue processed, data consistent |
| Scenario 5 | Shards moved, RF=2, no data loss |
| Scenario 6 | Complete cleanup, queue shows rollback_executed=true |

---

## Notes

1. **Timing**: Some scenarios require waiting for automatic detection and recovery. Buddy typically checks every few seconds.

2. **Queue Processing**: Queue is processed by Buddy background worker. You may need to wait or trigger manual processing.

3. **Logging**: Check Buddy logs for detailed rollback execution info:
```bash
tail -f /var/log/manticoresearch/buddy.log
```

4. **Partial Success**: Rollback continues even if some commands fail. Check logs for details.

5. ** idempotent**: All rollback commands use IF EXISTS / IF NOT EXISTS where possible to ensure safe retry.
