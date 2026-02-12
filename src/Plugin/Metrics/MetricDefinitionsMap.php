<?php declare(strict_types=1);

return [
'uptime' => [
	'type' => 'counter',
	'description' => 'Time in seconds since start',
	'name' => 'uptime_seconds',
	'deprecated_use' => 'uptime_seconds_gauge',
],
'connections' => [
	'type' => 'gauge',
	'description' => 'Connections count since start',
	'name' => 'connections_count',
	'deprecated_use' => 'connections_total',
],
'maxed_out' => [
	'type' => 'counter',
	'description' => 'Count of maxed_out errors since start',
	'name' => 'maxed_out_error_count',
],
'version' => [
	'type' => 'gauge',
	'description' => 'Manticore Search version',
	'name' => 'version',
],
'mysql_version' => [
	'type' => 'gauge',
	'description' => 'Manticore Search version',
	'name' => 'mysql_version',
],
'command_search' => [
	'type' => 'counter',
	'description' => 'Count of search queries since start',
	'name' => 'command_search_count',
],
'command_excerpt' => [
	'type' => 'counter',
	'description' => 'Count of snippet queries since start',
	'name' => 'command_excerpt_count',
],
'command_update' => [
	'type' => 'counter',
	'description' => 'Count of update queries since start',
	'name' => 'command_update_count',
],
'command_keywords' => [
	'type' => 'counter',
	'description' => 'Count of CALL KEYWORDS since start',
	'name' => 'command_keywords_count',
],
'command_persist' => [
	'type' => 'counter',
	'description' => 'Count of commands initiating a persistent connection since start',
	'name' => 'command_persist_count',
],
'command_status' => [
	'type' => 'counter',
	'description' => 'Count of SHOW STATUS runs since start',
	'name' => 'command_status_count',
],
'command_flushattrs' => [
	'type' => 'counter',
	'description' => 'Count of manual attributes flushes since start',
	'name' => 'command_flushattrs_count',
],
'command_sphinxql' => [
	'type' => 'counter',
	'description' => 'Count of sphinxql API calls since start',
	'name' => 'command_sphinxql_count',
],
'command_ping' => [
	'type' => 'counter',
	'description' => 'Count of pings since start',
	'name' => 'command_ping_count',
],
'command_delete' => [
	'type' => 'counter',
	'description' => 'Count of deletes since start',
	'name' => 'command_delete_count',
],
'command_insert' => [
	'type' => 'counter',
	'description' => 'Count of inserts since start',
	'name' => 'command_insert_count',
],
'command_replace' => [
	'type' => 'counter',
	'description' => 'Count of replaces since start',
	'name' => 'command_replace_count',
],
'command_commit' => [
	'type' => 'counter',
	'description' => 'Count of transaction commits since start',
	'name' => 'command_commit_count',
],
'command_suggest' => [
	'type' => 'counter',
	'description' => 'Count of CALL SUGGEST | CALL_QSUGGEST runs',
	'name' => 'command_suggest_count',
],
'command_callpq' => [
	'type' => 'counter',
	'description' => 'Count of CALL PQ runs',
	'name' => 'command_callpq_count',
],
'command_set' => [
	'type' => 'counter',
	'description' => 'Count of SET runs',
	'name' => 'command_set_count',
],
'command_json' => [
	'type' => 'counter',
	'description' => 'Count of JSON runs',
	'name' => 'command_json_count',
],
'command_cluster' => [
	'type' => 'counter',
	'description' => 'Count of cluster commands run',
	'name' => 'command_cluster_count',
],
'command_getfield' => [
	'type' => 'counter',
	'description' => 'Count of cluster docstore requests',
	'name' => 'command_getfield_count',
],
'insert_replace_stats_ms_avg_1m' => [
	'type' => 'gauge',
	'description' => 'Average time spent on insert or replace operations in the past 1min',
	'name' => 'insert_replace_stats_ms_avg_1m_millisecond',
],
'insert_replace_stats_ms_avg_5m' => [
	'type' => 'gauge',
	'description' => 'Average time spent on insert or replace operations in the past 5min',
	'name' => 'insert_replace_stats_ms_avg_5m_millisecond',
],
'insert_replace_stats_ms_avg_15m' => [
	'type' => 'gauge',
	'description' => 'Average time spent on insert or replace operations in the past 15min',
	'name' => 'insert_replace_stats_ms_avg_15m_millisecond',
],
'insert_replace_stats_ms_min_1m' => [
	'type' => 'gauge',
	'description' => 'Minimum time spent on insert or replace operations in the past 1min',
	'name' => 'insert_replace_stats_ms_min_1m_millisecond',
],
'insert_replace_stats_ms_min_5m' => [
	'type' => 'gauge',
	'description' => 'Minimum time spent on insert or replace operations in the past 5min',
	'name' => 'insert_replace_stats_ms_min_5m_millisecond',
],
'insert_replace_stats_ms_min_15m' => [
	'type' => 'gauge',
	'description' => 'Minimum time spent on insert or replace operations in the past 15min',
	'name' => 'insert_replace_stats_ms_min_15m_millisecond',
],
'insert_replace_stats_ms_max_1m' => [
	'type' => 'gauge',
	'description' => 'Maximum time spent on insert or replace operations in the past 1min',
	'name' => 'insert_replace_stats_ms_max_1m_millisecond',
],
'insert_replace_stats_ms_max_5m' => [
	'type' => 'gauge',
	'description' => 'Maximum time spent on insert or replace operations in the past 5min',
	'name' => 'insert_replace_stats_ms_max_5m_millisecond',
],
'insert_replace_stats_ms_max_15m' => [
	'type' => 'gauge',
	'description' => 'Maximum time spent on insert or replace operations in the past 15min',
	'name' => 'insert_replace_stats_ms_max_15m_millisecond',
],
'insert_replace_stats_ms_pct95_1m' => [
	'type' => 'gauge',
	'description' => '95th percentile of time spent on insert or replace operations in the past 1min',
	'name' => 'insert_replace_stats_ms_pct95_1m_millisecond',
],
'insert_replace_stats_ms_pct95_5m' => [
	'type' => 'gauge',
	'description' => '95th percentile of time spent on insert or replace operations in the past 5min',
	'name' => 'insert_replace_stats_ms_pct95_5m_millisecond',
],
'insert_replace_stats_ms_pct95_15m' => [
	'type' => 'gauge',
	'description' => '95th percentile of time spent on insert or replace operations in the past 15min',
	'name' => 'insert_replace_stats_ms_pct95_15m_millisecond',
],
'insert_replace_stats_ms_pct99_1m' => [
	'type' => 'gauge',
	'description' => '99th percentile of time spent on insert or replace operations in the past 1min',
	'name' => 'insert_replace_stats_ms_pct99_1m_millisecond',
],
'insert_replace_stats_ms_pct99_5m' => [
	'type' => 'gauge',
	'description' => '99th percentile of time spent on insert or replace operations in the past 5min',
	'name' => 'insert_replace_stats_ms_pct99_5m_millisecond',
],
'insert_replace_stats_ms_pct99_15m' => [
	'type' => 'gauge',
	'description' => '99th percentile of time spent on insert or replace operations in the past 15min',
	'name' => 'insert_replace_stats_ms_pct99_15m_millisecond',
],
'search_stats_ms_avg_1m' => [
	'type' => 'gauge',
	'description' => 'Average time spent on search operations in the past 1min',
	'name' => 'search_stats_ms_avg_1m_millisecond',
],
'search_stats_ms_avg_5m' => [
	'type' => 'gauge',
	'description' => 'Average time spent on search operations in the past 5min',
	'name' => 'search_stats_ms_avg_5m_millisecond',
],
'search_stats_ms_avg_15m' => [
	'type' => 'gauge',
	'description' => 'Average time spent on search operations in the past 15min',
	'name' => 'search_stats_ms_avg_15m_millisecond',
],
'search_stats_ms_min_1m' => [
	'type' => 'gauge',
	'description' => 'Minimum time spent on search operations in the past 1min',
	'name' => 'search_stats_ms_min_1m_millisecond',
],
'search_stats_ms_min_5m' => [
	'type' => 'gauge',
	'description' => 'Minimum time spent on search operations in the past 5min',
	'name' => 'search_stats_ms_min_5m_millisecond',
],
'search_stats_ms_min_15m' => [
	'type' => 'gauge',
	'description' => 'Minimum time spent on search operations in the past 15min',
	'name' => 'search_stats_ms_min_15m_millisecond',
],
'search_stats_ms_max_1m' => [
	'type' => 'gauge',
	'description' => 'Maximum time spent on search operations in the past 1min',
	'name' => 'search_stats_ms_max_1m_millisecond',
],
'search_stats_ms_max_5m' => [
	'type' => 'gauge',
	'description' => 'Maximum time spent on search operations in the past 5min',
	'name' => 'search_stats_ms_max_5m_millisecond',
],
'search_stats_ms_max_15m' => [
	'type' => 'gauge',
	'description' => 'Maximum time spent on search operations in the past 15min',
	'name' => 'search_stats_ms_max_15m_millisecond',
],
'search_stats_ms_pct95_1m' => [
	'type' => 'gauge',
	'description' => '95th percentile of time spent on search operations in the past 1min',
	'name' => 'search_stats_ms_pct95_1m_millisecond',
],
'search_stats_ms_pct95_5m' => [
	'type' => 'gauge',
	'description' => '95th percentile of time spent on search operations in the past 5min',
	'name' => 'search_stats_ms_pct95_5m_millisecond',
],
'search_stats_ms_pct95_15m' => [
	'type' => 'gauge',
	'description' => '95th percentile of time spent on search operations in the past 15min',
	'name' => 'search_stats_ms_pct95_15m_millisecond',
],
'search_stats_ms_pct99_1m' => [
	'type' => 'gauge',
	'description' => '99th percentile of time spent on search operations in the past 1min',
	'name' => 'search_stats_ms_pct99_1m_millisecond',
],
'search_stats_ms_pct99_5m' => [
	'type' => 'gauge',
	'description' => '99th percentile of time spent on search operations in the past 5min',
	'name' => 'search_stats_ms_pct99_5m_millisecond',
],
'search_stats_ms_pct99_15m' => [
	'type' => 'gauge',
	'description' => '99th percentile of time spent on search operations in the past 15min',
	'name' => 'search_stats_ms_pct99_15m_millisecond',
],
'update_stats_ms_avg_1m' => [
	'type' => 'gauge',
	'description' => 'Average time spent on update operations in the past 1min',
	'name' => 'update_stats_ms_avg_1m_millisecond',
],
'update_stats_ms_avg_5m' => [
	'type' => 'gauge',
	'description' => 'Average time spent on update operations in the past 5min',
	'name' => 'update_stats_ms_avg_5m_millisecond',
],
'update_stats_ms_avg_15m' => [
	'type' => 'gauge',
	'description' => 'Average time spent on update operations in the past 15min',
	'name' => 'update_stats_ms_avg_15m_millisecond',
],
'update_stats_ms_min_1m' => [
	'type' => 'gauge',
	'description' => 'Minimum time spent on update operations in the past 1min',
	'name' => 'update_stats_ms_min_1m_millisecond',
],
'update_stats_ms_min_5m' => [
	'type' => 'gauge',
	'description' => 'Minimum time spent on update operations in the past 5min',
	'name' => 'update_stats_ms_min_5m_millisecond',
],
'update_stats_ms_min_15m' => [
	'type' => 'gauge',
	'description' => 'Minimum time spent on update operations in the past 15min',
	'name' => 'update_stats_ms_min_15m_millisecond',
],
'update_stats_ms_max_1m' => [
	'type' => 'gauge',
	'description' => 'Maximum time spent on update operations in the past 1min',
	'name' => 'update_stats_ms_max_1m_millisecond',
],
'update_stats_ms_max_5m' => [
	'type' => 'gauge',
	'description' => 'Maximum time spent on update operations in the past 5min',
	'name' => 'update_stats_ms_max_5m_millisecond',
],
'update_stats_ms_max_15m' => [
	'type' => 'gauge',
	'description' => 'Maximum time spent on update operations in the past 15min',
	'name' => 'update_stats_ms_max_15m_millisecond',
],
'update_stats_ms_pct95_1m' => [
	'type' => 'gauge',
	'description' => '95th percentile of time spent on update operations in the past 1min',
	'name' => 'update_stats_ms_pct95_1m_millisecond',
],
'update_stats_ms_pct95_5m' => [
	'type' => 'gauge',
	'description' => '95th percentile of time spent on update operations in the past 5min',
	'name' => 'update_stats_ms_pct95_5m_millisecond',
],
'update_stats_ms_pct95_15m' => [
	'type' => 'gauge',
	'description' => '95th percentile of time spent on update operations in the past 15min',
	'name' => 'update_stats_ms_pct95_15m_millisecond',
],
'update_stats_ms_pct99_1m' => [
	'type' => 'gauge',
	'description' => '99th percentile of time spent on update operations in the past 1min',
	'name' => 'update_stats_ms_pct99_1m_millisecond',
],
'update_stats_ms_pct99_5m' => [
	'type' => 'gauge',
	'description' => '99th percentile of time spent on update operations in the past 5min',
	'name' => 'update_stats_ms_pct99_5m_millisecond',
],
'update_stats_ms_pct99_15m' => [
	'type' => 'gauge',
	'description' => '99th percentile of time spent on update operations in the past 15min',
	'name' => 'update_stats_ms_pct99_15m_millisecond',
],
'agent_connect' => [
	'type' => 'counter',
	'description' => 'Count of connections to agents since start',
	'name' => 'agent_connect_count',
],
'agent_retry' => [
	'type' => 'counter',
	'description' => 'Count of agent connection retries since start',
	'name' => 'agent_retry_count',
],
'queries' => [
	'type' => 'counter',
	'description' => 'Count of queries since start',
	'name' => 'queries_count',
],
'dist_queries' => [
	'type' => 'counter',
	'description' => 'Count of queries to agent-based distributed table since start',
	'name' => 'dist_queries_count',
],
'workers_total' => [
	'type' => 'gauge',
	'description' => 'Number of worker threads',
	'name' => 'workers_total_count',
],
'workers_clients' => [
	'type' => 'gauge',
	'description' => 'Current connections count',
	'name' => 'current_connections_count',
],
'workers_clients_buddy' => [
	'type' => 'gauge',
	'description' => 'Current connections count From buddy',
	'name' => 'workers_clients_buddy_count',
],
'query_wall' => [
	'type' => 'counter',
	'description' => 'Query wall time in seconds since start',
	'name' => 'query_wall_seconds',
],
'dist_wall' => [
	'type' => 'counter',
	'description' => 'Wall time in seconds spent on distributed queries since start',
	'name' => 'dist_wall_seconds',
],
'dist_local' => [
	'type' => 'counter',
	'description' => 'Wall time in seconds spent searching local tables in distributed queries since start',
	'name' => 'dist_local_seconds',
],
'dist_wait' => [
	'type' => 'counter',
	'description' => 'Time in seconds spent waiting for remote agents in distributed queries since start',
	'name' => 'dist_wait_seconds',
],
'avg_query_wall' => [
	'type' => 'gauge',
	'description' => 'Average query time in seconds since start',
	'name' => 'avg_query_wall_seconds',
],
'avg_dist_wall' => [
	'type' => 'gauge',
	'description' => 'Average distributed query time in seconds since start',
	'name' => 'avg_dist_wall_seconds',
],
'avg_dist_local' => [
	'type' => 'gauge',
	'description' => 'Average time in seconds spent searching local tables in distributed queries since start',
	'name' => 'avg_dist_local_seconds',
],
'qcache_max_bytes' => [
	'type' => 'gauge',
	'description' => 'The maximum RAM allocated for cached result sets (current value of the variable)',
	'name' => 'qcache_max_bytes',
],
'qcache_thresh_msec' => [
	'type' => 'gauge',
	'description' => 'The minimum wall time (milliseconds) threshold for a '.
		'query result to be cached (current value of the variable)',
	'name' => 'qcache_thresh_microseconds',
],
'qcache_ttl_sec' => [
	'type' => 'gauge',
	'description' => 'The expiration period (seconds) for a cached result set (current value of the variable)',
	'name' => 'qcache_ttl_sec',
],
'qcache_cached_queries' => [
	'type' => 'gauge',
	'description' => 'Number of queries currently in query cache',
	'name' => 'qcache_cached_queries_count',
],
'qcache_used_bytes' => [
	'type' => 'gauge',
	'description' => 'How much (bytes) query cache takes',
	'name' => 'qcache_used_bytes',
],
'qcache_hits' => [
	'type' => 'counter',
	'description' => 'Query cache hits since start',
	'name' => 'qcache_hits_count',
],
'thread_count' => [
	'type' => 'gauge',
	'description' => 'Count of active threads',
	'name' => 'thread_count',
],
'slowest_thread' => [
	'type' => 'gauge',
	'description' => 'Longest current query time in seconds',
	'name' => 'slowest_thread_seconds',
],
'indexed_documents' => [
	'type' => 'gauge',
	'description' => 'Number of indexed documents',
	'name' => 'indexed_documents_count',
],
'indexed_bytes' => [
	'type' => 'gauge',
	'description' => 'Indexed documents size in bytes',
	'name' => 'indexed_bytes',
],
'ram_bytes' => [
	'type' => 'gauge',
	'description' => 'Total size (in bytes) of RAM-resident tables part',
	'name' => 'ram_bytes',
],
'disk_bytes' => [
	'type' => 'gauge',
	'description' => 'Total size (in bytes) of all tables files',
	'name' => 'disk_bytes',
],
'disk_mapped' => [
	'type' => 'gauge',
	'description' => 'Amount of mmaped disk data in bytes',
	'name' => 'disk_mapped_bytes',
],
'disk_mapped_cached' => [
	'type' => 'gauge',
	'description' => 'Amount of mmaped disk data in bytes that is cached in RAM',
	'name' => 'disk_mapped_cached_bytes',
],
'disk_mapped_doclists' => [
	'type' => 'gauge',
	'description' => 'Amount of mmaped doclists data in bytes',
	'name' => 'disk_mapped_doclists_bytes',
],
'disk_mapped_cached_doclists' => [
	'type' => 'gauge',
	'description' => 'Amount of mmaped doclists data in bytes that is cached in RAM',
	'name' => 'disk_mapped_cached_doclists_bytes',
],
'disk_mapped_hitlists' => [
	'type' => 'gauge',
	'description' => 'Amount of mmaped hitlists data in bytes',
	'name' => 'disk_mapped_hitlists_bytes',
],
'disk_mapped_cached_hitlists' => [
	'type' => 'gauge',
	'description' => 'Amount of mmaped hitlists data in bytes that is cached in RAM',
	'name' => 'disk_mapped_cached_hitlists_bytes',
],
'killed_documents' => [
	'type' => 'gauge',
	'description' => 'Number of killed (deleted) documents',
	'name' => 'killed_documents_count',
],
'ram_bytes_retired' => [
	'type' => 'gauge',
	'description' => 'Retired RAM bytes',
	'name' => 'ram_bytes_retired',
],
'ram_chunk_segments_count' => [
	'type' => 'gauge',
	'description' => 'Number of RAM chunk segments',
	'name' => 'ram_chunk_segments_count',
],
'mem_limit_rate' => [
	'type' => 'gauge',
	'description' => 'Memory limit usage rate in percent',
	'name' => 'mem_limit_rate_percent',
],
'optimizing' => [
	'type' => 'gauge',
	'description' => 'Table is optimizing (1/0)',
	'name' => 'optimizing',
],
'locked' => [
	'type' => 'gauge',
	'description' => 'Table is locked (1/0)',
	'name' => 'locked',
],
'tid' => [
	'type' => 'gauge',
	'description' => 'Current transaction ID',
	'name' => 'tid',
],
'tid_saved' => [
	'type' => 'gauge',
	'description' => 'Saved transaction ID',
	'name' => 'tid_saved',
],
'killed_rate' => [
	'type' => 'gauge',
	'description' => 'Rate of deleted/indexed documents',
	'name' => 'killed_rate',
],
'disk_mapped_cached_ratio_percent' => [
	'type' => 'gauge',
	'description' => 'Disc mapped cached ratio %',
	'name' => 'disk_mapped_cached_ratio_percent',
],
'ram_chunk' => [
	'type' => 'gauge',
	'description' => 'Ram chunk size',
	'name' => 'ram_chunk_size',
],
'disk_chunks' => [
	'type' => 'gauge',
	'description' => 'Number of RT tables disk chunks',
	'name' => 'disk_chunks_count',
],
'mem_limit' => [
	'type' => 'gauge',
	'description' => 'Actual value (bytes) of rt_mem_limit',
	'name' => 'mem_limit_bytes',
],
'query_time_1min' => [
	'type' => 'gauge',
	'description' => 'Query execution time statistics for last 1 minute; '.
		'the data is encapsulated in JSON including number of queries and '.
		'min, max, avg, 95 and 99 percentile values',
	'name' => 'query_time_1min',
],
'found_rows_1min' => [
	'type' => 'gauge',
	'description' => 'Statistics of rows found by queries for last 1 minute. '.
		'Includes number of queries and min, max, avg, 95 and 99 percentile values',
	'name' => 'found_rows_1min',
],
'found_rows_total' => [
	'type' => 'gauge',
	'description' => 'Statistics of rows found by queries for all time since server start. '.
		'Includes number of queries and min, max, avg, 95 and 99 percentile values',
	'name' => 'found_rows_total',
],
'agent_tfo' => [
	'type' => 'gauge',
	'description' => 'Number of successfully sent TFO packets',
	'name' => 'agent_tfo_total_count',
	'deprecated_use' => 'agent_tfo_total',
],
'workers_active' => [
	'type' => 'gauge',
	'description' => 'Number of active worker threads',
	'name' => 'workers_active_count',
],
'workers_clients_vip' => [
	'type' => 'gauge',
	'description' => 'Current connections count by vip protocol',
	'name' => 'workers_clients_vip_count',
],
'work_queue_length' => [
	'type' => 'gauge',
	'description' => 'Count ',
	'name' => 'work_queue_length_count',
],
'load_1m' => [
	'type' => 'gauge',
	'description' => 'Load average in all queues for 1min',
	'name' => 'load_1m_total',
],
'load_5m' => [
	'type' => 'gauge',
	'description' => 'Load average in all queues for 5min',
	'name' => 'load_5m_total',
],
'load_15m' => [
	'type' => 'gauge',
	'description' => 'Load average in all queues for 15min',
	'name' => 'load_15m_total',
],
'load_primary_1m' => [
	'type' => 'gauge',
	'description' => 'Load average in primary queue for 1min',
	'name' => 'load_primary_1m_total',
],
'load_primary_5m' => [
	'type' => 'gauge',
	'description' => 'Load average in primary queue for 5min',
	'name' => 'load_primary_5m_total',
],
'load_primary_15m' => [
	'type' => 'gauge',
	'description' => 'Load average in primary queue for 15min',
	'name' => 'load_primary_15m_total',
],
'load_secondary_1m' => [
	'type' => 'gauge',
	'description' => 'Load average in secondary queue for 1min',
	'name' => 'load_secondary_1m_total',
],
'load_secondary_5m' => [
	'type' => 'gauge',
	'description' => 'Load average in secondary queue for 5min',
	'name' => 'load_secondary_5m_total',
],
'load_secondary_15m' => [
	'type' => 'gauge',
	'description' => 'Load average in secondary queue for 15min',
	'name' => 'load_secondary_15m_total',
],
'query_cpu' => [
	'type' => 'gauge',
	'description' => 'Query CPU time since start',
	'name' => 'query_cpu_total',
],
'query_reads' => [
	'type' => 'gauge',
	'description' => 'Total read IO calls (fired by search queries)',
	'name' => 'query_reads_total',
	'deprecated_use' => 'query_reads_count_total',
],
'query_readkb' => [
	'type' => 'gauge',
	'description' => 'Total read IO traffic',
	'name' => 'query_readkb_total',
	'deprecated_use' => 'query_readkb_bytes_total',
],
'query_readtime' => [
	'type' => 'gauge',
	'description' => 'Total read IO time',
	'name' => 'query_readtime_total',
	'deprecated_use' => 'query_readtime_seconds_total',
],
'avg_query_cpu' => [
	'type' => 'gauge',
	'description' => 'Average CPU time since start',
	'name' => 'avg_query_cpu_total',
],
'avg_dist_wait' => [
	'type' => 'gauge',
	'description' => 'Time spent waiting for remote agents in distributed queries',
	'name' => 'avg_dist_wait_total',
],
'avg_query_reads' => [
	'type' => 'gauge',
	'description' => 'Average read IO calls (fired by search queries)',
	'name' => 'avg_query_reads_total',
],
'avg_query_readkb' => [
	'type' => 'gauge',
	'description' => 'Average read IO traffic',
	'name' => 'avg_query_readkb_total',
],
'avg_query_readtime' => [
	'type' => 'gauge',
	'description' => 'Average read IO time',
	'name' => 'avg_query_readtime_total',
],
'connect_ok' => [
	'type' => 'gauge',
	'description' => 'Manticore connectivity check result (1 = OK, 0 = failed)',
	'name' => 'connect_ok',
],
'connect_time' => [
	'type' => 'gauge',
	'description' => 'Manticore connectivity check time in seconds',
	'name' => 'connect_time_seconds',
],
'searchd_processes_count' => [
	'type' => 'gauge',
	'description' => 'Number of searchd processes (from /proc)',
	'name' => 'searchd_processes_count',
],
'searchd_anon_rss_bytes' => [
	'type' => 'gauge',
	'description' => 'searchd anonymous RSS in bytes (from /proc)',
	'name' => 'searchd_anon_rss_bytes',
],
'searchd_rss_bytes' => [
	'type' => 'gauge',
	'description' => 'searchd RSS in bytes (from /proc)',
	'name' => 'searchd_rss_bytes',
],
'searchd_virt_bytes' => [
	'type' => 'gauge',
	'description' => 'searchd virtual memory size in bytes (from /proc)',
	'name' => 'searchd_virt_bytes',
],
'searchd_fd_count' => [
	'type' => 'gauge',
	'description' => 'searchd file descriptors count (from /proc)',
	'name' => 'searchd_fd_count',
],
'buddy_processes_count' => [
	'type' => 'gauge',
	'description' => 'Number of buddy processes (from /proc)',
	'name' => 'buddy_processes_count',
],
'buddy_anon_rss_bytes' => [
	'type' => 'gauge',
	'description' => 'buddy anonymous RSS in bytes (from /proc)',
	'name' => 'buddy_anon_rss_bytes',
],
'buddy_rss_bytes' => [
	'type' => 'gauge',
	'description' => 'buddy RSS in bytes (from /proc)',
	'name' => 'buddy_rss_bytes',
],
'buddy_virt_bytes' => [
	'type' => 'gauge',
	'description' => 'buddy virtual memory size in bytes (from /proc)',
	'name' => 'buddy_virt_bytes',
],
'buddy_fd_count' => [
	'type' => 'gauge',
	'description' => 'buddy file descriptors count (from /proc)',
	'name' => 'buddy_fd_count',
],
'searchd_crashes_total' => [
	'type' => 'counter',
	'description' => 'Count of detected searchd crashes (from searchd.log)',
	'name' => 'searchd_crashes_total',
],
'tables_count' => [
	'type' => 'gauge',
	'description' => 'Number of tables returned by SHOW TABLES',
	'name' => 'tables_count',
],
'binlog_files_count' => [
	'type' => 'gauge',
	'description' => 'Number of binlog files (from data_dir/binlog)',
	'name' => 'binlog_files_count',
],
'binlog_files_bytes' => [
	'type' => 'gauge',
	'description' => 'Total size of binlog files in bytes (from data_dir/binlog)',
	'name' => 'binlog_files_bytes',
],
'table_files_count' => [
	'type' => 'gauge',
	'description' => 'Number of files in table data directory',
	'name' => 'table_files_count',
],
'table_files_bytes' => [
	'type' => 'gauge',
	'description' => 'Total size of files in table data directory in bytes',
	'name' => 'table_files_bytes',
],
'index_files_count' => [
	'type' => 'gauge',
	'description' => 'Number of files in all table data directories',
	'name' => 'index_files_count',
],
'index_files_bytes' => [
	'type' => 'gauge',
	'description' => 'Total size of files in all table data directories in bytes',
	'name' => 'index_files_bytes',
],
'query_time_5min' => [
	'type' => 'gauge',
	'description' => 'Query execution time statistics for last 5 minutes; '.
		'the data is encapsulated in JSON including number of queries and '.
		'min, max, avg, 95 and 99 percentile values',
	'name' => 'query_time_5min',
],
'query_time_15min' => [
	'type' => 'gauge',
	'description' => 'Query execution time statistics for last 15 minutes; '.
		'the data is encapsulated in JSON including number of queries and '.
		'min, max, avg, 95 and 99 percentile values',
	'name' => 'query_time_15min',
],
'query_time_total' => [
	'type' => 'gauge',
	'description' => 'Query execution time statistics for all time since server start; '.
		'the data is encapsulated in JSON including number of queries and '.
		'min, max, avg, 95 and 99 percentile values',
	'name' => 'query_time_total',
],
'found_rows_5min' => [
	'type' => 'gauge',
	'description' => 'Statistics of rows found by queries for last 5 minutes. '.
		'Includes number of queries and min, max, avg, 95 and 99 percentile values',
	'name' => 'found_rows_5min',
],
'found_rows_15min' => [
	'type' => 'gauge',
	'description' => 'Statistics of rows found by queries for last 15 minutes. '.
		'Includes number of queries and min, max, avg, 95 and 99 percentile values',
	'name' => 'found_rows_15min',
],
'schema_hash' => [
	'type' => 'gauge',
	'description' => 'Schema hash (sha256) derived from manticore.json indexes section',
	'name' => 'schema_hash',
],
'non_served_tables_count' => [
	'type' => 'gauge',
	'description' => 'Count of non-served tables (present in manticore.json but missing in SHOW TABLES)',
	'name' => 'non_served_tables_count',
],
'non_served_table' => [
	'type' => 'gauge',
	'description' => 'Non-served tables (present in manticore.json but missing in SHOW TABLES)',
	'name' => 'non_served_table',
],
'cluster_numeric' => [
	'type' => 'gauge',
	'description' => 'Cluster numeric status values (from SHOW STATUS)',
	'name' => 'cluster_numeric',
],
'cluster_string' => [
	'type' => 'gauge',
	'description' => 'Cluster string status values represented as 1 with label {value=""} (from SHOW STATUS)',
	'name' => 'cluster_string',
],
];
