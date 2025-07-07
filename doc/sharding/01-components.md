# Core Components

The Manticore Buddy Sharding system consists of several core components that work together to provide distributed table sharding with automatic replication and rebalancing.

## System Components

### 1. Table Class (`src/Plugin/Sharding/Table.php`)

The main orchestrator for sharding operations.

**Key Methods:**
- `shard()`: Creates initial sharding configuration
- `rebalance()`: Handles node failures and new node additions
- `getShardSchema()`: Returns current shard distribution
- `canStartRebalancing()`: Checks if rebalancing can start
- `resetRebalancingState()`: Recovery method for failed operations

**Core Responsibilities:**
- Shard creation and distribution
- Rebalancing orchestration
- State management integration
- Queue command generation

**Enhanced Features (Recent Updates):**
- Dual-path rebalancing logic (failed nodes vs new nodes)
- RF-specific handling strategies
- State management for concurrent operation prevention
- Enhanced error handling and recovery

### 2. Util Class (`src/Plugin/Sharding/Util.php`)

Contains algorithms for shard distribution and rebalancing calculations.

**Key Methods:**
- `rebalanceShardingScheme()`: Handles failed node rebalancing
- `rebalanceWithNewNodes()`: Handles new node integration
- `redistributeShardsForRF1()`: RF=1 specific redistribution
- `addReplicasToNewNodes()`: RF>=2 replica distribution
- `assignShardsToNodes()`: Core distribution algorithm

**Core Responsibilities:**
- Distribution algorithm implementation
- Load balancing calculations
- Schema transformation logic
- Node selection algorithms

**Algorithm Patterns:**
```php
public static function rebalanceWithNewNodes(Vector $schema, Set $newNodes, int $replicationFactor): Vector {
    if ($replicationFactor === 1) {
        // RF=1: Move shards using redistribution
        return self::redistributeShardsForRF1($schema, $newNodes);
    } else {
        // RF>=2: Add replicas to new nodes
        return self::addReplicasToNewNodes($schema, $newNodes);
    }
}
```

### 3. Queue Class (`src/Plugin/Sharding/Queue.php`)

Manages asynchronous command execution across cluster nodes.

**Key Features:**
- Command queuing with dependencies
- `wait_for_id` synchronization mechanism
- Node-specific command targeting
- Parallel execution support

**Core Responsibilities:**
- Command ordering and dependencies
- Asynchronous execution management
- Inter-node communication
- Operation synchronization

**Synchronization Pattern:**
```php
// Command A
$idA = $queue->add($node, "COMMAND A");

// Command B waits for A to complete
$queue->setWaitForId($idA);
$idB = $queue->add($node, "COMMAND B");

// Command C waits for B to complete
$queue->setWaitForId($idB);
$idC = $queue->add($node, "COMMAND C");
```

### 4. State Class (`src/Plugin/Sharding/State.php`)

Provides persistent state management for sharding operations.

**Key Features:**
- Key-value state storage
- Concurrent operation prevention
- Cluster-aware state management
- Recovery state tracking

**Core Responsibilities:**
- State persistence
- Concurrency control
- Operation tracking
- Recovery support

**State Management Pattern:**
```php
// State key format
$rebalanceKey = "rebalance:{$tableName}";

// State values
'idle'      // No operation in progress
'running'   // Rebalancing operation in progress
'completed' // Last operation completed successfully
'failed'    // Last operation failed
```

### 5. Cluster Class (`src/Plugin/Sharding/Cluster.php`)

Manages cluster topology and node communication.

**Key Features:**
- Node discovery and health monitoring
- Cluster configuration management
- Inter-node communication setup
- Active/inactive node tracking

**Core Responsibilities:**
- Cluster topology management
- Node health monitoring
- Communication channel setup
- Cluster state synchronization

### 6. Operator Class (`src/Plugin/Sharding/Operator.php`)

Main sharding coordinator with cluster management.

**Key Responsibilities:**
- Cluster topology change detection
- Automatic rebalancing trigger
- Master node coordination
- System health monitoring

**Enhanced Logic (Recent Updates):**
```php
public function checkBalance(): static {
    $cluster = $this->getCluster();
    $allNodes = $cluster->getNodes();
    $inactiveNodes = $cluster->getInactiveNodes();
    $activeNodes = $allNodes->diff($inactiveNodes);

    // Check if cluster topology changed (failed nodes OR new nodes)
    $clusterHash = Cluster::getNodesHash($activeNodes);
    $currentHash = $this->state->get('cluster_hash');

    // If no topology change, nothing to do
    if ($clusterHash === $currentHash) {
        return $this;
    }

    // Topology changed - determine what kind of change
    if ($inactiveNodes->count() > 0) {
        Buddy::info("Rebalancing due to inactive nodes: {$inactiveNodes->join(', ')}");
    } else {
        Buddy::info('Rebalancing due to cluster topology change (likely new nodes)');
    }

    // Trigger rebalancing for all tables
    // ...
}
```

## Component Interactions

### Data Flow Between Components

1. **Operator** detects cluster topology changes
2. **Cluster** provides node status information
3. **Table** orchestrates rebalancing using **Util** algorithms
4. **Queue** manages command execution with proper ordering
5. **State** tracks operation progress and prevents conflicts

### Component Dependencies

```
Operator
├── Cluster (topology monitoring)
├── State (hash tracking)
└── Table (rebalancing trigger)
    ├── Util (algorithms)
    ├── Queue (command execution)
    ├── State (concurrency control)
    └── Cluster (node information)
```

## System Tables

The sharding system uses several system tables for coordination:

- **`system.sharding_state`**: State tracking and coordination
- **`system.sharding_queue`**: Distributed operation queue
- **`system.sharding_table`**: Table sharding configurations

## Recent Enhancements

### Enhanced Table.php Features

1. **Dual-Path Rebalancing**: Separate logic for failed nodes vs new nodes
2. **RF-Specific Handling**: Different strategies for RF=1 and RF>=2
3. **State Management**: Concurrent operation prevention
4. **Error Recovery**: Proper exception handling with state cleanup

### Enhanced Util.php Features

1. **New Node Integration**: `rebalanceWithNewNodes()` method
2. **RF=1 Redistribution**: Safe shard movement algorithms
3. **RF>=2 Replica Addition**: Efficient load balancing
4. **Smart Node Selection**: Optimal shard placement algorithms

### Enhanced Operator.php Features

1. **Topology Change Detection**: Distinguishes between failed and new nodes
2. **Improved Logging**: Better visibility into rebalancing triggers
3. **Hash-Based Change Detection**: Efficient topology monitoring

## Testing Infrastructure

### Test Doubles Pattern

To work around final class mocking limitations, the system uses test doubles:

```php
class TestableQueue {
    public function __construct(private ?Queue $queue = null) {
        // Allow null for pure mocking scenarios
    }

    public function add(string $nodeId, string $query): int {
        return $this->queue?->add($nodeId, $query) ?? 0;
    }

    // Other methods delegate similarly...
}
```

### Component Testing Strategy

- **Component Testing**: Test individual algorithms and logic
- **Pattern Verification**: Verify command patterns and sequences
- **Logic Testing**: Test decision-making logic separately from execution
- **Integration Testing**: Test with real dependencies where possible

This component architecture provides a robust, scalable foundation for distributed table sharding with comprehensive testing coverage and production-ready reliability.
