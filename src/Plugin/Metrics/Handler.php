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

/**
 * @phpstan-type MetricDefinition array{name: string, type: string, description: string}
 * @phpstan-type MetricData array{value: mixed, label?: array<string, float|int|string>}
 * @phpstan-type Metric array{type: string, info: string, data: MetricData[]}
 */
final class Handler extends BaseHandlerWithClient
{
	/** @var array<string, Metric> */
	private static array $metrics = [];

	/** @var array<string, MetricDefinition> */
	private static array $metricNames = [
		'uptime' => [
			'type' => 'counter',
			'description' => 'Time in seconds since start',
			'name' => 'uptime_seconds',
		],
		'connections' => [
			'type' => 'gauge',
			'description' => 'Connections count since start',
			'name' => 'connections_count',
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
		],
		'query_readkb' => [
			'type' => 'gauge',
			'description' => 'Total read IO traffic',
			'name' => 'query_readkb_total',
		],
		'query_readtime' => [
			'type' => 'gauge',
			'description' => 'Total read IO time',
			'name' => 'query_readtime_total',
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
		$taskFn = static function (Client $client): TaskResult {
			self::getMetrics($client);
			return TaskResult::raw(implode('', self::drawMetrics()));
		};

		return Task::create(
			$taskFn, [$this->manticoreClient]
		)->run();
	}

	/**
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function getMetrics(Client $client): void {
		// Reset metrics to avoid accumulating stale data
		self::$metrics = [];

		self::getStatusMetrics($client);
		self::getThreadsMetrics($client);
		self::getTablesMetrics($client);
	}

	/**
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function getStatusMetrics(Client $client): void {
		$request = $client->sendRequest('SHOW STATUS');
		if ($request->hasError()) {
			ManticoreSearchResponseError::throw((string)$request->getError());
		}

		$result = $request->getResult();
		if (!is_array($result[0])) {
			return;
		}

		foreach ($result[0]['data'] as $row) {
			if (str_starts_with($row['Counter'], 'load')
				|| str_contains($row['Counter'], '_stats_ms_')) {
				$loadMetricName = $row['Counter'];
				$loadValue = explode(' ', $row['Value']);

				self::addMetric("{$loadMetricName}_1m", $loadValue[0]);
				self::addMetric("{$loadMetricName}_5m", $loadValue[1]);
				self::addMetric("{$loadMetricName}_15m", $loadValue[2]);
				continue;
			}

			self::addMetric($row['Counter'], $row['Value']);
		}
	}

	/**
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function getThreadsMetrics(Client $client): void {
		$request = $client->sendRequest('SHOW THREADS');
		if ($request->hasError()) {
			ManticoreSearchResponseError::throw((string)$request->getError());
		}

		$result = $request->getResult();
		if (!is_array($result[0])) {
			return;
		}

		$threadCount = 0;
		$maxTime = 0;
		foreach ($result[0]['data'] as $row) {
			if (strtolower($row['Info']) === 'show threads') {
				continue;
			}

			$threadCount++;

			$rowTime = $row['This/prev job time'];
			if (str_contains($rowTime, 'us')) {
				$rowTime = (int)$rowTime / 1000000;
			} else {
				$rowTime = (int)$rowTime / 1000;
			}

			if ($rowTime <= $maxTime) {
				continue;
			}

			$maxTime = $rowTime;
		}

		self::addMetric('thread_count', $threadCount);
		self::addMetric('slowest_thread', $maxTime);
	}

	/**
	 * @throws ManticoreSearchClientError
	 * @throws GenericError
	 * @throws ManticoreSearchResponseError
	 */
	private static function getTablesMetrics(Client $client): void {
		$request = $client->sendRequest('SHOW TABLES');
		if ($request->hasError()) {
			ManticoreSearchResponseError::throw((string)$request->getError());
		}

		$result = $request->getResult();
		if (!is_array($result[0])) {
			return;
		}

		self::processTableMetrics($result[0]['data'], $client);
	}

	/**
	 * @param array<int, array<string, string>> $data
	 * @param Client $client
	 *
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private static function processTableMetrics(array $data, Client $client): void {
		foreach ($data as $row) {
			$tableName = $row['Index'] ?? $row['Table'];

			$tableStatus = $client->sendRequest('SHOW TABLE ' . $tableName . ' STATUS');
			if ($tableStatus->hasError()) {
				ManticoreSearchResponseError::throw((string)$tableStatus->getError());
			}

			$tableStatus = $tableStatus->getResult();
			if (!is_array($tableStatus[0])) {
				return;
			}

			$diskMapped = 0;
			$diskMappedCached = 0;
			foreach ($tableStatus[0]['data'] as $tableStatusRow) {
				if ($tableStatusRow['Variable_name'] === 'disk_mapped') {
					$diskMapped = (int)$tableStatusRow['Value'];
				} elseif ($tableStatusRow['Variable_name'] === 'disk_mapped_cached') {
					$diskMappedCached = (int)$tableStatusRow['Value'];
				}

				self::addMetric(
					$tableStatusRow['Variable_name'],
					$tableStatusRow['Value'],
					['table' => $tableName]
				);
			}

			if (empty($diskMapped) || empty($diskMappedCached)) {
				continue;
			}

			self::calculateMappedRatioMetric($diskMapped, $diskMappedCached, $tableName);
		}
	}

	private static function calculateMappedRatioMetric(
		int $diskMapped,
		int $diskMappedCached,
		string $tableName
	): void {
		$ratio = 0;

		if ($diskMappedCached > $diskMapped) {
			$ratio = 100;
		} elseif ($diskMappedCached !== 0 && $diskMapped !== 0) {
			$ratio = ($diskMappedCached / $diskMapped) * 100;
		}

		self::addMetric(
			'disk_mapped_cached_ratio_percent', $ratio,
			['table' => $tableName]
		);
	}
	/**
	 * @return string[]
	 */
	private static function drawMetrics(): array {
		$result = [];
		foreach (self::$metrics as $metricName => $metrics) {
			$result[] = "# HELP manticore_$metricName " . $metrics['info'] . "\n";
			$result[] = "# TYPE manticore_$metricName " . $metrics['type'] . "\n";

			foreach ($metrics['data'] as $metric) {
				$result[] = "manticore_$metricName ".
					(self::getLabel($metric['label'] ?? null)).''.
					self::formatValue($metric['value'])."\n";
			}
		}

		return $result;
	}

	/**
	 * @param string $name
	 * @param string|float|int $value
	 * @param array<string, string>|null $label
	 * @return void
	 */
	private static function addMetric(string $name, string|float|int $value, ?array $label = null): void {
		if (!isset(self::$metricNames[$name]['name'])) {
			return;
		}

		if ($value === 'N/A') {
			$value = 0;
		}

		if ($name === 'killed_rate') {
			$value = (float)$value;
		}

		if (is_string($value)
			&& (str_contains($name, 'query_time_')
				|| str_contains($name, 'found_rows_'))) {
			/** @var array<string, string> $row */
			$row = json_decode($value, true);

			foreach ($row as $k => $v) {
				$metricData = ['value' => $v];
				$metricData['label'] = $label + ['type' => $k];
				self::$metrics[self::$metricNames[$name]['name']]['data'][] = $metricData;
			}
		} elseif (str_contains($name, 'version')) {
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

	/**
	 * @param array<string, float|int|string>|null $label
	 * @return string
	 */
	private static function getLabel(?array $label): string {
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

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private static function formatValue(mixed $value): mixed {
		if (in_array($value, ['OFF', '-'])) {
			$value = 0;
		}

		return $value;
	}
}
