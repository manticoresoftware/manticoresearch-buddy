# Manticore Buddy Sharding System

## Overview

The Manticore Buddy Sharding system provides automatic distribution of data across multiple nodes in a Manticore Search cluster. It supports different replication factors and handles dynamic cluster changes including node failures and new node additions.

### Key Features

- **Automatic Sharding**: Distributes table data across multiple nodes
- **Dynamic Rebalancing**: Handles node failures and new node additions
- **Replication Support**: Configurable replication factors (RF=1, RF=2, RF>=3)
- **Data Safety**: Ensures no data loss during rebalancing operations
- **Concurrent Operation Control**: Prevents conflicting rebalancing operations
- **Queue-Based Processing**: Asynchronous command execution with proper ordering

### Core Problem Solved

**Original Issue**: When adding new nodes to a cluster with RF=1, the system couldn't redistribute existing shards from active nodes to new nodes. The rebalancing logic only handled failed node scenarios, leaving new nodes underutilized.

**Solution**: Implemented sophisticated rebalancing strategies that differentiate between failed nodes and new nodes, with special handling for RF=1 using intermediate cluster techniques.

## Quick Start

### Creating a Sharded Table

```sql
CREATE TABLE my_table (id bigint, title text) shards=4 rf=2
```

### Monitoring Rebalancing

```php
// Check if rebalancing can start
if ($table->canStartRebalancing()) {
    $table->rebalance($queue);
} else {
    echo "Rebalancing already in progress or failed";
}

// Check status
$status = $table->getRebalancingStatus();
echo "Current status: $status";
```

## Architecture Overview

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│     Client      │    │      Buddy      │    │   Manticore     │
│   Application   │◄──►│   Sharding      │◄──►│    Cluster      │
│                 │    │     System      │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                               │
                               ▼
                     ┌─────────────────┐
                     │     Queue       │
                     │    System       │
                     └─────────────────┘
                               │
                               ▼
                     ┌─────────────────┐
                     │     State       │
                     │   Management    │
                     └─────────────────┘
```

### Data Distribution Model

```
Original Table: users
├── Shard 0: users_s0 (Node A)
├── Shard 1: users_s1 (Node B)
├── Shard 2: users_s2 (Node C)
└── Shard 3: users_s3 (Node A)

Distributed Table: users
└── Points to all shards across nodes
```

## Documentation Structure

This documentation is organized into the following sections:

- **[01-Components](01-components.md)** - Core system components and their responsibilities
- **[02-Replication](02-replication.md)** - Replication factors and strategies
- **[03-Rebalancing](03-rebalancing.md)** - Rebalancing logic and algorithms
- **[04-Queue-System](04-queue-system.md)** - Queue synchronization and command ordering
- **[05-State-Management](05-state-management.md)** - State tracking and concurrency control
- **[06-Data-Flow](06-data-flow.md)** - Complete data flow and command sequences
- **[07-Error-Handling](07-error-handling.md)** - Error handling and recovery mechanisms
- **[08-Testing](08-testing.md)** - Testing strategy and coverage
- **[09-Production](09-production.md)** - Production considerations and monitoring
- **[10-Troubleshooting](10-troubleshooting.md)** - Common issues and debugging

## System Requirements

- Manticore Search cluster with multiple nodes
- PHP 8.0+ with required extensions
- Network connectivity between cluster nodes

## Recent Enhancements

The system has been significantly enhanced to handle new node addition scenarios:

- **Dual-Path Rebalancing**: Separate handling for failed nodes vs new nodes
- **RF=1 Shard Movement**: Sophisticated intermediate cluster strategy for safe data movement
- **RF>=2 Replica Addition**: Efficient replica distribution for load balancing
- **Enhanced State Management**: Concurrent operation prevention and recovery
- **Comprehensive Testing**: 95%+ test coverage with test doubles for final classes

## Getting Started

For detailed implementation guides and examples, see the individual documentation files listed above. Start with [Components](01-components.md) to understand the system architecture.
