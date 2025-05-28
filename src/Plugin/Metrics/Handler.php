<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Metrics;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class Handler extends BaseHandlerWithClient
{

	private static $metrics = [];
	private static $metricNames = [
		'uptime' => [
			'type' => 'counter',
			'description' => 'Time in seconds since start',
			'name' => 'uptime_seconds'
		],
		'connections' => [
			'type' => 'gauge',
			'description' => 'Connections count since start',
			'name' => 'connections_count'
		],
		'maxed_out' => [
			'type' => 'counter',
			'description' => 'Count of maxed_out errors since start',
			'name' => 'maxed_out_error_count'
		],
		'version' => [
			'type' => 'gauge',
			'description' => 'Manticore Search version',
			'name' => 'version'
		],
		'mysql_version' => [
			'type' => 'gauge',
			'description' => 'Manticore Search version',
			'name' => 'mysql_version'
		],
		'command_search' => [
			'type' => 'counter',
			'description' => 'Count of search queries since start',
			'name' => 'command_search_count'
		],
		'command_excerpt' => [
			'type' => 'counter',
			'description' => 'Count of snippet queries since start',
			'name' => 'command_excerpt_count'
		],
		'command_update' => [
			'type' => 'counter',
			'description' => 'Count of update queries since ',
			'name' => 'command_update_count'
		],
		'command_keywords' => [
			'type' => 'counter',
			'description' => 'Count of CALL KEYWORDS since start',
			'name' => 'command_keywords_count'
		],
		'command_persist' => [
			'type' => 'counter',
			'description' => 'Count of commands initiating a persistent connection since start',
			'name' => 'command_persist_count'
		],
		'command_status' => [
			'type' => 'counter',
			'description' => 'Count of SHOW STATUS runs since start',
			'name' => 'command_status_count'
		],
		'command_flushattrs' => [
			'type' => 'counter',
			'description' => 'Count of manual attributes flushes since start',
			'name' => 'command_flushattrs_count'
		],
		'command_sphinxql' => [
			'type' => 'counter',
			'description' => 'Count of sphinxql API calls since start',
			'name' => 'command_sphinxql_count'
		],
		'command_ping' => [
			'type' => 'counter',
			'description' => 'Count of pings since start',
			'name' => 'command_ping_count'
		],
		'command_delete' => [
			'type' => 'counter',
			'description' => 'Count of deletes since start',
			'name' => 'command_delete_count'
		],
		'command_insert' => [
			'type' => 'counter',
			'description' => 'Count of inserts since start',
			'name' => 'command_insert_count'
		],
		'command_replace' => [
			'type' => 'counter',
			'description' => 'Count of replaces since start',
			'name' => 'command_replace_count'
		],
		'command_commit' => [
			'type' => 'counter',
			'description' => 'Count of transaction commits since start',
			'name' => 'command_commit_count'
		],
		'command_suggest' => [
			'type' => 'counter',
			'description' => 'Count of CALL SUGGEST | CALL_QSUGGEST runs',
			'name' => 'command_suggest_count'
		],
		'command_callpq' => [
			'type' => 'counter',
			'description' => 'Count of CALL PQ runs',
			'name' => 'command_callpq_count'
		],
		'command_set' => [
			'type' => 'counter',
			'description' => 'Count of SET runs',
			'name' => 'command_set_count'
		],
		'command_json' => [
			'type' => 'counter',
			'description' => 'Count of JSON runs',
			'name' => 'command_json_count'
		],
		'command_cluster' => [
			'type' => 'counter',
			'description' => 'Count of cluster commands run',
			'name' => 'command_cluster_count'
		],
		'command_getfield' => [
			'type' => 'counter',
			'description' => 'Count of cluster docstore requests',
			'name' => 'command_getfield_count'
		],
		'insert_replace_stats_ms_avg_1m' => [
			'type' => 'gauge',
			'description' => 'Average time spent on insert or replace operations in the past 1min',
			'name' => 'insert_replace_stats_ms_avg_1m_millisecond'
		],
		'insert_replace_stats_ms_avg_5m' => [
			'type' => 'gauge',
			'description' => 'Average time spent on insert or replace operations in the past 5min',
			'name' => 'insert_replace_stats_ms_avg_5m_millisecond'
		],
		'insert_replace_stats_ms_avg_15m' => [
			'type' => 'gauge',
			'description' => 'Average time spent on insert or replace operations in the past 15min',
			'name' => 'insert_replace_stats_ms_avg_15m_millisecond'
		],
		'insert_replace_stats_ms_min_1m' => [
			'type' => 'gauge',
			'description' => 'Minimum time spent on insert or replace operations in the past 1min',
			'name' => 'insert_replace_stats_ms_min_1m_millisecond'
		],
		'insert_replace_stats_ms_min_5m' => [
			'type' => 'gauge',
			'description' => 'Minimum time spent on insert or replace operations in the past 5min',
			'name' => 'insert_replace_stats_ms_min_5m_millisecond'
		],
		'insert_replace_stats_ms_min_15m' => [
			'type' => 'gauge',
			'description' => 'Minimum time spent on insert or replace operations in the past 15min',
			'name' => 'insert_replace_stats_ms_min_15m_millisecond'
		],
		'insert_replace_stats_ms_max_1m' => [
			'type' => 'gauge',
			'description' => 'Maximum time spent on insert or replace operations in the past 1min',
			'name' => 'insert_replace_stats_ms_max_1m_millisecond'
		],
		'insert_replace_stats_ms_max_5m' => [
			'type' => 'gauge',
			'description' => 'Maximum time spent on insert or replace operations in the past 5min',
			'name' => 'insert_replace_stats_ms_max_5m_millisecond'
		],
		'insert_replace_stats_ms_max_15m' => [
			'type' => 'gauge',
			'description' => 'Maximum time spent on insert or replace operations in the past 15min',
			'name' => 'insert_replace_stats_ms_max_15m_millisecond'
		],
		'insert_replace_stats_ms_pct95_1m' => [
			'type' => 'gauge',
			'description' => '95th percentile of time spent on insert or replace operations in the past 1min',
			'name' => 'insert_replace_stats_ms_pct95_1m_millisecond'
		],
		'insert_replace_stats_ms_pct95_5m' => [
			'type' => 'gauge',
			'description' => '95th percentile of time spent on insert or replace operations in the past 5min',
			'name' => 'insert_replace_stats_ms_pct95_5m_millisecond'
		],
		'insert_replace_stats_ms_pct95_15m' => [
			'type' => 'gauge',
			'description' => '95th percentile of time spent on insert or replace operations in the past 15min',
			'name' => 'insert_replace_stats_ms_pct95_15m_millisecond'
		],
		'insert_replace_stats_ms_pct99_1m' => [
			'type' => 'gauge',
			'description' => '99th percentile of time spent on insert or replace operations in the past 1min',
			'name' => 'insert_replace_stats_ms_pct99_1m_millisecond'
		],
		'insert_replace_stats_ms_pct99_5m' => [
			'type' => 'gauge',
			'description' => '99th percentile of time spent on insert or replace operations in the past 5min',
			'name' => 'insert_replace_stats_ms_pct99_5m_millisecond'
		],
		'insert_replace_stats_ms_pct99_15m' => [
			'type' => 'gauge',
			'description' => '99th percentile of time spent on insert or replace operations in the past 15min',
			'name' => 'insert_replace_stats_ms_pct99_15m_millisecond'
		],
		'search_stats_ms_avg_1m' => [
			'type' => 'gauge',
			'description' => 'Average time spent on search operations in the past 1min',
			'name' => 'search_stats_ms_avg_1m_millisecond'
		],
		'search_stats_ms_avg_5m' => [
			'type' => 'gauge',
			'description' => 'Average time spent on search operations in the past 5min',
			'name' => 'search_stats_ms_avg_5m_millisecond'
		],
		'search_stats_ms_avg_15m' => [
			'type' => 'gauge',
			'description' => 'Average time spent on search operations in the past 15min',
			'name' => 'search_stats_ms_avg_15m_millisecond'
		],
		'search_stats_ms_min_1m' => [
			'type' => 'gauge',
			'description' => 'Minimum time spent on search operations in the past 1min',
			'name' => 'search_stats_ms_min_1m_millisecond'
		],
		'search_stats_ms_min_5m' => [
			'type' => 'gauge',
			'description' => 'Minimum time spent on search operations in the past 5min',
			'name' => 'search_stats_ms_min_5m_millisecond'
		],
		'search_stats_ms_min_15m' => [
			'type' => 'gauge',
			'description' => 'Minimum time spent on search operations in the past 15min',
			'name' => 'search_stats_ms_min_15m_millisecond'
		],
		'search_stats_ms_max_1m' => [
			'type' => 'gauge',
			'description' => 'Maximum time spent on search operations in the past 1min',
			'name' => 'search_stats_ms_max_1m_millisecond'
		],
		'search_stats_ms_max_5m' => [
			'type' => 'gauge',
			'description' => 'Maximum time spent on search operations in the past 5min',
			'name' => 'search_stats_ms_max_5m_millisecond'
		],
		'search_stats_ms_max_15m' => [
			'type' => 'gauge',
			'description' => 'Maximum time spent on search operations in the past 15min',
			'name' => 'search_stats_ms_max_15m_millisecond'
		],
		'search_stats_ms_pct95_1m' => [
			'type' => 'gauge',
			'description' => '95th percentile of time spent on search operations in the past 1min',
			'name' => 'search_stats_ms_pct95_1m_millisecond'
		],
		'search_stats_ms_pct95_5m' => [
			'type' => 'gauge',
			'description' => '95th percentile of time spent on search operations in the past 5min',
			'name' => 'search_stats_ms_pct95_5m_millisecond'
		],
		'search_stats_ms_pct95_15m' => [
			'type' => 'gauge',
			'description' => '95th percentile of time spent on search operations in the past 15min',
			'name' => 'search_stats_ms_pct95_15m_millisecond'
		],
		'search_stats_ms_pct99_1m' => [
			'type' => 'gauge',
			'description' => '99th percentile of time spent on search operations in the past 1min',
			'name' => 'search_stats_ms_pct99_1m_millisecond'
		],
		'search_stats_ms_pct99_5m' => [
			'type' => 'gauge',
			'description' => '99th percentile of time spent on search operations in the past 5min',
			'name' => 'search_stats_ms_pct99_5m_millisecond'
		],
		'search_stats_ms_pct99_15m' => [
			'type' => 'gauge',
			'description' => '99th percentile of time spent on search operations in the past 15min',
			'name' => 'search_stats_ms_pct99_15m_millisecond'
		],
		'update_stats_ms_avg_1m' => [
			'type' => 'gauge',
			'description' => 'Average time spent on update operations in the past 1min',
			'name' => 'update_stats_ms_avg_1m_millisecond'
		],
		'update_stats_ms_avg_5m' => [
			'type' => 'gauge',
			'description' => 'Average time spent on update operations in the past 5min',
			'name' => 'update_stats_ms_avg_5m_millisecond'
		],
		'update_stats_ms_avg_15m' => [
			'type' => 'gauge',
			'description' => 'Average time spent on update operations in the past 15min',
			'name' => 'update_stats_ms_avg_15m_millisecond'
		],
		'update_stats_ms_min_1m' => [
			'type' => 'gauge',
			'description' => 'Minimum time spent on update operations in the past 1min',
			'name' => 'update_stats_ms_min_1m_millisecond'
		],
		'update_stats_ms_min_5m' => [
			'type' => 'gauge',
			'description' => 'Minimum time spent on update operations in the past 5min',
			'name' => 'update_stats_ms_min_5m_millisecond'
		],
		'update_stats_ms_min_15m' => [
			'type' => 'gauge',
			'description' => 'Minimum time spent on update operations in the past 15min',
			'name' => 'update_stats_ms_min_15m_millisecond'
		],
		'update_stats_ms_max_1m' => [
			'type' => 'gauge',
			'description' => 'Maximum time spent on update operations in the past 1min',
			'name' => 'update_stats_ms_max_1m_millisecond'
		],
		'update_stats_ms_max_5m' => [
			'type' => 'gauge',
			'description' => 'Maximum time spent on update operations in the past 5min',
			'name' => 'update_stats_ms_max_5m_millisecond'
		],
		'update_stats_ms_max_15m' => [
			'type' => 'gauge',
			'description' => 'Maximum time spent on update operations in the past 15min',
			'name' => 'update_stats_ms_max_15m_millisecond'
		],
		'update_stats_ms_pct95_1m' => [
			'type' => 'gauge',
			'description' => '95th percentile of time spent on update operations in the past 1min',
			'name' => 'update_stats_ms_pct95_1m_millisecond'
		],
		'update_stats_ms_pct95_5m' => [
			'type' => 'gauge',
			'description' => '95th percentile of time spent on update operations in the past 5min',
			'name' => 'update_stats_ms_pct95_5m_millisecond'
		],
		'update_stats_ms_pct95_15m' => [
			'type' => 'gauge',
			'description' => '95th percentile of time spent on update operations in the past 15min',
			'name' => 'update_stats_ms_pct95_15m_millisecond'
		],
		'update_stats_ms_pct99_1m' => [
			'type' => 'gauge',
			'description' => '99th percentile of time spent on update operations in the past 1min',
			'name' => 'update_stats_ms_pct99_1m_millisecond'
		],
		'update_stats_ms_pct99_5m' => [
			'type' => 'gauge',
			'description' => '99th percentile of time spent on update operations in the past 5min',
			'name' => 'update_stats_ms_pct99_5m_millisecond'
		],
		'update_stats_ms_pct99_15m' => [
			'type' => 'gauge',
			'description' => '99th percentile of time spent on update operations in the past 15min',
			'name' => 'update_stats_ms_pct99_15m_millisecond'
		],
		'agent_connect' => [
			'type' => 'counter',
			'description' => 'Count of connections to agents since start',
			'name' => 'agent_connect_count'
		],
		'agent_retry' => [
			'type' => 'counter',
			'description' => 'Count of agent connection retries since start',
			'name' => 'agent_retry_count'
		],
		'queries' => [
			'type' => 'counter',
			'description' => 'Count of queries since start',
			'name' => 'queries_count'
		],
		'dist_queries' => [
			'type' => 'counter',
			'description' => 'Count of queries to agent-based distributed table since start',
			'name' => 'dist_queries_count'
		],
		'workers_total' => [
			'type' => 'gauge',
			'description' => 'Number of worker threads',
			'name' => 'workers_total_count'
		],
		'workers_clients' => [
			'type' => 'gauge',
			'description' => 'Current connections count',
			'name' => 'current_connections_count'
		],
		'workers_clients_buddy' => [
			'type' => 'gauge',
			'description' => 'Current connections count From buddy',
			'name' => 'workers_clients_buddy_count'
		],
		'query_wall' => [
			'type' => 'counter',
			'description' => 'Query wall time in seconds since start',
			'name' => 'query_wall_seconds'
		],
		'dist_wall' => [
			'type' => 'counter',
			'description' => 'Wall time in seconds spent on distributed queries since start',
			'name' => 'dist_wall_seconds'
		],
		'dist_local' => [
			'type' => 'counter',
			'description' => 'Wall time in seconds spent searching local tables in distributed queries since start',
			'name' => 'dist_local_seconds',
		],
		'dist_wait' => [
			'type' => 'counter',
			'description' => 'Time in seconds spent waiting for remote agents in distributed queries since start',
			'name' => 'dist_wait_seconds'
		],
		'avg_query_wall' => [
			'type' => 'gauge',
			'description' => 'Average query time in seconds since start',
			'name' => 'avg_query_wall_seconds'
		],
		'avg_dist_wall' => [
			'type' => 'gauge',
			'description' => 'Average distributed query time in seconds since start',
			'name' => 'avg_dist_wall_seconds'
		],
		'avg_dist_local' => [
			'type' => 'gauge',
			'description' => 'Average time in seconds spent searching local tables in distributed queries since start',
			'name' => 'avg_dist_local_seconds'
		],
		'qcache_max_bytes' => [
			'type' => 'gauge',
			'description' => 'The maximum RAM allocated for cached result sets (current value of the variable)',
			'name' => 'qcache_max_bytes'
		],
		'qcache_thresh_msec' => [
			'type' => 'gauge',
			'description' => 'The minimum wall time (milliseconds) threshold for a query result to be cached (current value of the variable)',
			'name' => 'qcache_thresh_microseconds'
		],
		'qcache_ttl_sec' => [
			'type' => 'gauge',
			'description' => 'The expiration period (seconds) for a cached result set (current value of the variable)',
			'name' => 'qcache_ttl_sec'
		],
		'qcache_cached_queries' => [
			'type' => 'gauge',
			'description' => 'Number of queries currently in query cache',
			'name' => 'qcache_cached_queries_count'
		],
		'qcache_used_bytes' => [
			'type' => 'gauge',
			'description' => 'How much (bytes) query cache takes',
			'name' => 'qcache_used_bytes'
		],
		'qcache_hits' => [
			'type' => 'counter',
			'description' => 'Query cache hits since start',
			'name' => 'qcache_hits_count'
		],
		'thread_count' => [
			'type' => 'gauge',
			'description' => 'Count of active threads',
			'name' => 'thread_count'
		],
		'slowest_thread' => [
			'type' => 'gauge',
			'description' => 'Longest current query time in seconds',
			'name' => 'slowest_thread_seconds'
		],
		'indexed_documents' => [
			'type' => 'gauge',
			'description' => 'Number of indexed documents',
			'name' => 'indexed_documents_count'
		],
		'indexed_bytes' => [
			'type' => 'gauge',
			'description' => 'Indexed documents size in bytes',
			'name' => 'indexed_bytes'
		],
		'ram_bytes' => [
			'type' => 'gauge',
			'description' => 'Total size (in bytes) of RAM-resident tables part',
			'name' => 'ram_bytes'
		],
		'disk_bytes' => [
			'type' => 'gauge',
			'description' => 'Total size (in bytes) of all tables files',
			'name' => 'disk_bytes'
		],
		'killed_rate' => [
			'type' => 'gauge',
			'description' => 'Rate of deleted/indexed documents',
			'name' => 'killed_rate'
		],
		'disk_mapped_cached_ratio_percent' => [
			'type' => 'gauge',
			'description' => 'Disc mapped cached ratio %',
			'name' => 'disk_mapped_cached_ratio_percent'
		],
		'ram_chunk' => [
			'type' => 'gauge',
			'description' => 'Ram chunk size',
			'name' => 'ram_chunk_size'
		],
		'disk_chunks' => [
			'type' => 'gauge',
			'description' => 'Number of RT tables disk chunks',
			'name' => 'disk_chunks_count'
		],
		'mem_limit' => [
			'type' => 'gauge',
			'description' => 'Actual value (bytes) of rt_mem_limit',
			'name' => 'mem_limit_bytes'
		],
		'query_time_1min' => [
			'type' => 'gauge',
			'description' => 'Query execution time statistics for last 1 minute; the data is encapsulated in JSON including number of queries and min, max, avg, 95 and 99 percentile values',
			'name' => 'query_time_1min'
		],
		'found_rows_1min' => [
			'type' => 'gauge',
			'description' => 'Statistics of rows found by queries for last 1 minute. Includes number of queries and min, max, avg, 95 and 99 percentile values',
			'name' => 'found_rows_1min'
		],
		'found_rows_total' => [
			'type' => 'gauge',
			'description' => 'Statistics of rows found by queries for all time since server start. Includes number of queries and min, max, avg, 95 and 99 percentile values',
			'name' => 'found_rows_total'
		],
		'agent_tfo' => [
			'type' => 'gauge',
			'description' => 'Number of successfully sent TFO packets',
			'name' => 'agent_tfo_total_count'
		],
		'workers_active' => [
			'type' => 'gauge',
			'description' => 'Number of active worker threads',
			'name' => 'workers_active_count'
		],
		'workers_clients_vip' => [
			'type' => 'gauge',
			'description' => 'Current connections count by vip protocol',
			'name' => 'workers_clients_vip_count'
		],
		'work_queue_length' => [
			'type' => 'gauge',
			'description' => 'Count ',
			'name' => 'work_queue_length_count'
		],
		'load_1m' => [
			'type' => 'gauge',
			'description' => 'Load average in all queues for 1min',
			'name' => 'load_1m_total'
		],
		'load_5m' => [
			'type' => 'gauge',
			'description' => 'Load average in all queues for 5min',
			'name' => 'load_5m_total'
		],
		'load_15m' => [
			'type' => 'gauge',
			'description' => 'Load average in all queues for 15min',
			'name' => 'load_15m_total'
		],
		'load_primary_1m' => [
			'type' => 'gauge',
			'description' => 'Load average in primary queue for 1min',
			'name' => 'load_primary_1m_total'
		],
		'load_primary_5m' => [
			'type' => 'gauge',
			'description' => 'Load average in primary queue for 5min',
			'name' => 'load_primary_5m_total'
		],
		'load_primary_15m' => [
			'type' => 'gauge',
			'description' => 'Load average in primary queue for 15min',
			'name' => 'load_primary_15m_total'
		],
		'load_secondary_1m' => [
			'type' => 'gauge',
			'description' => 'Load average in secondary queue for 1min',
			'name' => 'load_secondary_1m_total'
		],
		'load_secondary_5m' => [
			'type' => 'gauge',
			'description' => 'Load average in secondary queue for 5min',
			'name' => 'load_secondary_5m_total'
		],
		'load_secondary_15m' => [
			'type' => 'gauge',
			'description' => 'Load average in secondary queue for 15min',
			'name' => 'load_secondary_15m_total'
		],
		'query_cpu' => [
			'type' => 'gauge',
			'description' => 'Query CPU time since start',
			'name' => 'query_cpu_total'
		],
		'query_reads' => [
			'type' => 'gauge',
			'description' => 'Total read IO calls (fired by search queries)',
			'name' => 'query_reads_total'
		],
		'query_readkb' => [
			'type' => 'gauge',
			'description' => 'Total read IO traffic',
			'name' => 'query_readkb_total'
		],
		'query_readtime' => [
			'type' => 'gauge',
			'description' => 'Total read IO time',
			'name' => 'query_readtime_total'
		],
		'avg_query_cpu' => [
			'type' => 'gauge',
			'description' => 'Average CPU time since start',
			'name' => 'avg_query_cpu_total'
		],
		'avg_dist_wait' => [
			'type' => 'gauge',
			'description' => 'Time spent waiting for remote agents in distributed queries',
			'name' => 'avg_dist_wait_total'
		],
		'avg_query_reads' => [
			'type' => 'gauge',
			'description' => 'Average read IO calls (fired by search queries)',
			'name' => 'avg_query_reads_total'
		],
		'avg_query_readkb' => [
			'type' => 'gauge',
			'description' => 'Average read IO traffic',
			'name' => 'avg_query_readkb_total'
		],
		'avg_query_readtime' => [
			'type' => 'gauge',
			'description' => 'Average read IO time',
			'name' => 'avg_query_readtime_total'
		],
	];

	/**
	 * Initialize the executor
	 *
	 * @param Payload $payload
	 * @return void
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request
	 *
	 * @return Task
	 * @throws GenericError
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, Client $client): TaskResult {
			self::getMetrics($client);
			return TaskResult::raw(self::drawMetrics());
		};

		return Task::create(
			$taskFn, [$this->payload, $this->manticoreClient]
		)->run();
	}


	/**
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function getMetrics(Client $client)
	{
		$request = $client->sendRequest("SHOW STATUS");

		if ($request->hasError()){
			ManticoreSearchResponseError::throw((string)$request->getError());
		}


			$data = $request->getResult();
			foreach ($data as $row) {
				if (strpos($row['Counter'], 'load') === 0 || strpos($row['Counter'], '_stats_ms_') !== false) {
					$loadMetricName = $row['Counter'];
					$loadValue = explode(' ', $row['Value']);

					self::addMetric("{$loadMetricName}_1m", $loadValue[0]);
					self::addMetric("{$loadMetricName}_5m", $loadValue[1]);
					self::addMetric("{$loadMetricName}_15m", $loadValue[2]);
					continue;
				}
				self::addMetric($row['Counter'], $row['Value']);
			}



		$threads = $this->connection->query("SHOW THREADS");

		$threadCount = 0;
		$maxTime = 0;

		if ($threads) {
			$threads = $threads->fetch_all(MYSQLI_ASSOC);
			foreach ($threads as $row) {
				if (strtolower($row['Info']) === 'show threads') {
					continue;
				}

				$threadCount++;

				$rowTime = $row['This/prev job time'];
				if (strpos($rowTime, 'us') !== false) {
					$rowTime = (int) $rowTime / 1000000;
				} else {
					$rowTime = (int) $rowTime / 1000;
				}

				if ($rowTime > $maxTime) {
					$maxTime = $rowTime;
				}
			}
		}


		self::addMetric('thread_count', $threadCount);
		self::addMetric('slowest_thread', $maxTime);


		$data = $this->connection->query("SHOW TABLES");
		if ($data) {
			$data = $data->fetch_all(MYSQLI_ASSOC);
			foreach ($data as $row) {

				$tableName = $row['Index'] ?? $row['Table'];
				$tableStatus = $this->connection->query('SHOW TABLE ' . $tableName . ' STATUS');
				if ($tableStatus) {
					$tableStatus = $tableStatus->fetch_all(MYSQLI_ASSOC);
					foreach ($tableStatus as $tableStatusRow) {

						if (in_array($tableStatusRow['Variable_name'], ['disk_mapped','disk_mapped_cached'])){
							$name = $tableStatusRow['Variable_name'];
							$$name = (int) $tableStatusRow['Value'];
						}

						self::addMetric($tableStatusRow['Variable_name'], $tableStatusRow['Value'],
							['table' => $tableName]);
					}

					if (isset($disk_mapped) && isset($disk_mapped_cached)){

						$ratio = 0;

						if ($disk_mapped_cached > $disk_mapped){
							$ratio = 100;
						} elseif ($disk_mapped_cached !== 0 && $disk_mapped !== 0){
							$ratio = ($disk_mapped_cached / $disk_mapped) * 100;
						}

						self::addMetric('disk_mapped_cached_ratio_percent', $ratio,
							['table' => $tableName]);
					}
				}
			}
		}
	}


	private static function drawMetrics(): array
	{
		$result = [];
		foreach (self::$metrics as $metricName => $metrics) {
			echo "# HELP manticore_$metricName " . $metrics['info'] . "\n";
			echo "# TYPE manticore_$metricName " . $metrics['type'] . "\n";

			foreach ($metrics['data'] as $metric) {
				$result[] = "manticore_$metricName ".
					($this->getLabel($metric['label'] ?? null))."".
					$this->formatValue($metric['value'])."\n";
			}
		}

		return $result;
	}

	private static function addMetric($name, $value, $label = null)
	{
		if (isset(self::$metricNames[$name]['name'])) {

			if ($value === 'N/A'){
				$value = 0;
			}
			if ($name === 'killed_rate') {
				$value = (float) $value;
			}

			if (strpos($name, 'query_time_') !== false || strpos($name, 'found_rows_') !== false) {
				$row = json_decode($value, true);

				foreach ($row as $k => $v) {
					$metricData = ['value' => $v];
					$metricData['label'] = $label + ['type' => $k];
					self::$metrics[self::$metricNames[$name]['name']]['data'][] = $metricData;
				}
			} elseif (strpos($name, 'version') !== false) {
				$metricData = ['value' => 1];
				$metricData['label'] = ['version' => $value];
				self::$metrics[self::$metricNames[$name]['name']]['data'][] = $metricData;
			} else {
				$metricData = ['value' => $value];
				if (isset($label)) {
					$metricData['label'] = $label;
				}
				self::$metrics[self::$metricNames[$name]['name']]['data'][] = $metricData;
			}

			self::$metrics[self::$metricNames[$name]['name']]['type'] = self::$metricNames[$name]['type'];
			self::$metrics[self::$metricNames[$name]['name']]['info'] = self::$metricNames[$name]['description'];
		}
	}

	private function getLabel($label)
	{
		$result = '';
		if (!empty($label)) {
			$labelCond = [];
			foreach ($label as $name => $value) {
				$labelCond[] = $name . '="' . $value . '"';
			}

			if ($labelCond !== []) {
				$result = '{' . implode(', ', $labelCond) . '} ';
			}
		}

		return $result;
	}

	private function formatValue($value)
	{
		if (in_array($value, ['OFF', '-'])) {
			$value = 0;
		}

		return $value;
	}
}
