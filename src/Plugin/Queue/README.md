# Queue Plugin

Comprehensive data streaming and ingestion system for real-time processing from external sources into Manticore Search tables. Supports Kafka sources and materialized views for data transformation.

## Examples

### CREATE SOURCE
```sql
CREATE SOURCE kafka (id bigint, term text, abbrev text, GlossDef json)
type='kafka' broker_list='kafka:9092' topic_list='my-data' consumer_group='manticore' num_consumers='4' batch='50'
```

### Custom Field Mapping
```sql
CREATE SOURCE kafka (id bigint, term 'source_field_name' text, abbrev text)
type='kafka' broker_list='kafka:9092' topic_list='my-data'
```

### CREATE MATERIALIZED VIEW
```sql
CREATE MATERIALIZED VIEW view_table TO destination_kafka AS
SELECT id, term as name, abbrev as short_name, UTC_TIMESTAMP() as received_at, GlossDef.size as size FROM kafka
```

### SHOW Commands
```sql
SHOW SOURCES
SHOW SOURCE kafka
SHOW MATERIALIZED VIEWS
SHOW MVS
SHOW MATERIALIZED VIEW view_table
SHOW MV view_table
```

### ALTER Commands
```sql
ALTER SOURCE kafka ADD column title int
ALTER MATERIALIZED VIEW view_table ADD column title int
ALTER MV view_table ADD column title int
ALTER MATERIALIZED VIEW view_name suspended=1
ALTER MV view_name suspended=0
```

### DROP Commands
```sql
DROP SOURCE kafka
DROP SOURCE IF EXISTS kafka
DROP MATERIALIZED VIEW view_table
DROP MV view_table
DROP MATERIALIZED VIEW IF EXISTS view_table
DROP MV IF EXISTS view_table
```

### Basic Kafka Source
```sql
CREATE SOURCE kafka_events (id bigint, event_type text, data json)
type='kafka' broker_list='localhost:9092' topic_list='events' consumer_group='processors'
```

### Advanced Source with Custom Mapping
```sql
CREATE SOURCE kafka_logs (
    id bigint,
    message 'msg' text,
    timestamp 'ts' bigint,
    level text
)
type='kafka' broker_list='kafka1:9092,kafka2:9092' topic_list='logs,errors' consumer_group='log_processors' num_consumers='4' batch='200' partition_list='0,1,2,3'
```

### Materialized View with Transformations
```sql
CREATE TABLE processed_logs (
    id bigint,
    message text,
    processed_at timestamp,
    log_level text,
    data_size int
) engine='columnar';

CREATE MATERIALIZED VIEW log_processor TO processed_logs AS
SELECT
    id,
    message,
    FROM_UNIXTIME(timestamp) as processed_at,
    UPPER(level) as log_level,
    LENGTH(data) as data_size
FROM kafka_logs
WHERE level IN ('ERROR', 'WARN')
```

### Managing Views
```sql
ALTER MATERIALIZED VIEW log_processor suspended=1;
ALTER MATERIALIZED VIEW log_processor suspended=0;
ALTER MATERIALIZED VIEW log_processor ADD column hostname text;
DROP MATERIALIZED VIEW log_processor;
```