<?php declare(strict_types=1);

/*
  Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

return [
	'_cluster/settings' => [
		'defaults' => [
			'action' => [
				'auto_create_index' => 'true',
				'destructive_requires_name' => 'false',
				'search' => [
					'shard_count' => [
						'limit' => '9223372036854775807',
					],
				],
			],
			'bootstrap' => [
				'ctrlhandler' => 'true',
				'memory_lock' => 'false',
				'system_call_filter' => 'true',
			],
			'cache' => [
				'recycler' => [
					'page' => [
						'limit' => [
							'heap' => '10%',
						],
						'type' => 'CONCURRENT',
						'weight' => [
							'bytes' => '1.0',
							'ints' => '1.0',
							'longs' => '1.0',
							'objects' => '0.1',
						],
					],
				],
			],
			'ccr' => [
				'auto_follow' => [
					'wait_for_metadata_timeout' => '60s',
				],
				'indices' => [
					'recovery' => [
						'chunk_size' => '1mb',
						'internal_action_timeout' => '60s',
						'max_bytes_per_sec' => '40mb',
						'max_concurrent_file_chunks' => '5',
						'recovery_activity_timeout' => '60s',
					],
				],
				'wait_for_metadata_timeout' => '60s',
			],
			'client' => [
				'transport' => [
					'ignore_cluster_name' => 'false',
					'nodes_sampler_interval' => '5s',
					'ping_timeout' => '5s',
					'sniff' => 'false',
				],
				'type' => 'node',
			],
			'cluster' => [
				'auto_shrink_voting_configuration' => 'true',
				'blocks' => [
					'read_only' => 'false',
					'read_only_allow_delete' => 'false',
				],
				'election' => [
					'back_off_time' => '100ms',
					'duration' => '500ms',
					'initial_timeout' => '100ms',
					'max_timeout' => '10s',
					'strategy' => 'supports_voting_only',
				],
				'fault_detection' => [
					'follower_check' => [
						'interval' => '1000ms',
						'retry_count' => '3',
						'timeout' => '10000ms',
					],
					'leader_check' => [
						'interval' => '1000ms',
						'retry_count' => '3',
						'timeout' => '10000ms',
					],
				],
				'follower_lag' => [
					'timeout' => '90000ms',
				],
				'indices' => [
					'close' => [
						'enable' => 'true',
					],
					'tombstones' => [
						'size' => '500',
					],
				],
				'info' => [
					'update' => [
						'interval' => '30s',
						'timeout' => '15s',
					],
				],
				'initial_master_nodes' => [],
				'join' => [
					'timeout' => '60000ms',
				],
				'max_shards_per_node' => '1000',
				'max_voting_config_exclusions' => '10',
				'name' => 'docker-cluster',
				'no_master_block' => 'write',
				'nodes' => [
					'reconnect_interval' => '10s',
				],
				'persistent_tasks' => [
					'allocation' => [
						'enable' => 'all',
						'recheck_interval' => '30s',
					],
				],
				'publish' => [
					'info_timeout' => '10000ms',
					'timeout' => '30000ms',
				],
				'remote' => [
					'connect' => 'true',
					'connections_per_cluster' => '3',
					'initial_connect_timeout' => '30s',
					'node' => [
						'attr' => '',
					],
				],
				'routing' => [
					'allocation' => [
						'allow_rebalance' => 'indices_all_active',
						'awareness' => [
							'attributes' => [],
						],
						'balance' => [
							'index' => '0.55',
							'shard' => '0.45',
							'threshold' => '1.0',
						],
						'cluster_concurrent_rebalance' => '2',
						'disk' => [
							'include_relocations' => 'true',
							'reroute_interval' => '60s',
							'threshold_enabled' => 'true',
							'watermark' => [
								'flood_stage' => '95%',
								'high' => '90%',
								'low' => '85%',
							],
						],
						'enable' => 'all',
						'node_concurrent_incoming_recoveries' => '2',
						'node_concurrent_outgoing_recoveries' => '2',
						'node_concurrent_recoveries' => '2',
						'node_initial_primaries_recoveries' => '4',
						'same_shard' => [
							'host' => 'false',
						],
						'shard_state' => [
							'reroute' => [
								'priority' => 'NORMAL',
							],
						],
						'total_shards_per_node' => '-1',
						'type' => 'balanced',
					],
					'rebalance' => [
						'enable' => 'all',
					],
					'use_adaptive_replica_selection' => 'true',
				],
				'service' => [
					'slow_master_task_logging_threshold' => '10s',
					'slow_task_logging_threshold' => '30s',
				],
			],
			'discovery' => [
				'cluster_formation_warning_timeout' => '10000ms',
				'find_peers_interval' => '1000ms',
				'initial_state_timeout' => '30s',
				'request_peers_timeout' => '3000ms',
				'seed_hosts' => [],
				'seed_providers' => [],
				'seed_resolver' => [
					'max_concurrent_resolvers' => '10',
					'timeout' => '5s',
				],
				'type' => 'single-node',
				'unconfigured_bootstrap_timeout' => '3s',
				'zen' => [
					'bwc_ping_timeout' => '3s',
					'commit_timeout' => '30s',
					'fd' => [
						'connect_on_network_disconnect' => 'false',
						'ping_interval' => '1s',
						'ping_retries' => '3',
						'ping_timeout' => '30s',
						'register_connection_listener' => 'true',
					],
					'hosts_provider' => [],
					'join_retry_attempts' => '3',
					'join_retry_delay' => '100ms',
					'join_timeout' => '60000ms',
					'master_election' => [
						'ignore_non_master_pings' => 'false',
						'wait_for_joins_timeout' => '30000ms',
					],
					'max_pings_from_another_master' => '3',
					'minimum_master_nodes' => '-1',
					'no_master_block' => 'write',
					'ping' => [
						'unicast' => [
							'concurrent_connects' => '10',
							'hosts' => [],
							'hosts.resolve_timeout' => '5s',
						],
					],
					'ping_timeout' => '3s',
					'publish' => [
						'max_pending_cluster_states' => '25',
					],
					'publish_diff' => [
						'enable' => 'true',
					],
					'publish_timeout' => '30s',
					'send_leave_request' => 'true',
					'unsafe_rolling_upgrades_enabled' => 'true',
				],
			],
			'enrich' => [
				'cleanup_period' => '15m',
				'coordinator_proxy' => [
					'max_concurrent_requests' => '8',
					'max_lookups_per_request' => '128',
					'queue_capacity' => '1024',
				],
				'fetch_size' => '10000',
				'max_concurrent_policy_executions' => '50',
				'max_force_merge_attempts' => '3',
			],
			'gateway' => [
				'auto_import_dangling_indices' => 'true',
				'expected_data_nodes' => '-1',
				'expected_master_nodes' => '-1',
				'expected_nodes' => '-1',
				'recover_after_data_nodes' => '-1',
				'recover_after_master_nodes' => '0',
				'recover_after_nodes' => '-1',
				'recover_after_time' => '0ms',
				'slow_write_logging_threshold' => '10s',
				'write_dangling_indices_info' => 'true',
			],
			'http' => [
				'bind_host' => [],
				'compression' => 'true',
				'compression_level' => '3',
				'content_type' => [
					'required' => 'true',
				],
				'cors' => [
					'allow-credentials' => 'false',
					'allow-headers' => 'X-Requested-With,Content-Type,Content-Length',
					'allow-methods' => 'OPTIONS,HEAD,GET,POST,PUT,DELETE',
					'allow-origin' => '',
					'enabled' => 'false',
					'max-age' => '1728000',
				],
				'detailed_errors' => [
					'enabled' => 'true',
				],
				'host' => [],
				'max_chunk_size' => '8kb',
				'max_content_length' => '100mb',
				'max_header_size' => '8kb',
				'max_initial_line_length' => '4kb',
				'max_warning_header_count' => '-1',
				'max_warning_header_size' => '-1b',
				'netty' => [
					'max_composite_buffer_components' => '69905',
					'receive_predictor_size' => '64kb',
					'worker_count' => '4',
				],
				'pipelining' => [
					'max_events' => '10000',
				],
				'port' => '9200-9300',
				'publish_host' => [],
				'publish_port' => '-1',
				'read_timeout' => '0ms',
				'reset_cookies' => 'false',
				'tcp' => [
					'keep_alive' => 'true',
					'keep_count' => '-1',
					'keep_idle' => '-1',
					'keep_interval' => '-1',
					'no_delay' => 'true',
					'receive_buffer_size' => '-1b',
					'reuse_address' => 'true',
					'send_buffer_size' => '-1b',
				],
				'tcp_no_delay' => 'true',
				'type' => 'security4',
				'type.default' => 'netty4',
			],
			'index' => [
				'codec' => 'default',
				'store' => [
					'fs' => [
						'fs_lock' => 'native',
					],
					'preload' => [],
					'type' => '',
				],
			],
			'indices' => [
				'analysis' => [
					'hunspell' => [
						'dictionary' => [
							'ignore_case' => 'false',
							'lazy' => 'false',
						],
					],
				],
				'breaker' => [
					'accounting' => [
						'limit' => '100%',
						'overhead' => '1.0',
					],
					'fielddata' => [
						'limit' => '40%',
						'overhead' => '1.03',
						'type' => 'memory',
					],
					'request' => [
						'limit' => '60%',
						'overhead' => '1.0',
						'type' => 'memory',
					],
					'total' => [
						'limit' => '95%',
						'use_real_memory' => 'true',
					],
					'type' => 'hierarchy',
				],
				'cache' => [
					'cleanup_interval' => '1m',
				],
				'fielddata' => [
					'cache' => [
						'size' => '-1b',
					],
				],
				'id_field_data' => [
					'enabled' => 'true',
				],
				'lifecycle' => [
					'history_index_enabled' => 'true',
					'poll_interval' => '10m',
				],
				'mapping' => [
					'dynamic_timeout' => '30s',
				],
				'memory' => [
					'index_buffer_size' => '10%',
					'interval' => '5s',
					'max_index_buffer_size' => '-1',
					'min_index_buffer_size' => '48mb',
					'shard_inactive_time' => '5m',
				],
				'queries' => [
					'cache' => [
						'all_segments' => 'false',
						'count' => '10000',
						'size' => '10%',
					],
				],
				'query' => [
					'bool' => [
						'max_clause_count' => '1024',
					],
					'query_string' => [
						'allowLeadingWildcard' => 'true',
						'analyze_wildcard' => 'false',
					],
				],
				'recovery' => [
					'internal_action_long_timeout' => '1800000ms',
					'internal_action_timeout' => '15m',
					'max_bytes_per_sec' => '40mb',
					'max_concurrent_file_chunks' => '2',
					'recovery_activity_timeout' => '1800000ms',
					'retry_delay_network' => '5s',
					'retry_delay_state_sync' => '500ms',
				],
				'requests' => [
					'cache' => [
						'expire' => '0ms',
						'size' => '1%',
					],
				],
				'store' => [
					'delete' => [
						'shard' => [
							'timeout' => '30s',
						],
					],
				],
			],
			'ingest' => [
				'geoip' => [
					'cache_size' => '1000',
				],
				'grok' => [
					'watchdog' => [
						'interval' => '1s',
						'max_execution_time' => '1s',
					],
				],
				'user_agent' => [
					'cache_size' => '1000',
				],
			],
			'logger' => [
				'level' => 'INFO',
			],
			'monitor' => [
				'fs' => [
					'refresh_interval' => '1s',
				],
				'jvm' => [
					'gc' => [
						'enabled' => 'true',
						'overhead' => [
							'debug' => '10',
							'info' => '25',
							'warn' => '50',
						],
						'refresh_interval' => '1s',
					],
					'refresh_interval' => '1s',
				],
				'os' => [
					'refresh_interval' => '1s',
				],
				'process' => [
					'refresh_interval' => '1s',
				],
			],
			'network' => [
				'bind_host' => [
					'0.0.0.0',
				],
				'breaker' => [
					'inflight_requests' => [
						'limit' => '100%',
						'overhead' => '2.0',
					],
				],
				'host' => [
					'0.0.0.0',
				],
				'publish_host' => [
					'0.0.0.0',
				],
				'server' => 'true',
				'tcp' => [
					'connect_timeout' => '30s',
					'keep_alive' => 'true',
					'keep_count' => '-1',
					'keep_idle' => '-1',
					'keep_interval' => '-1',
					'no_delay' => 'true',
					'receive_buffer_size' => '-1b',
					'reuse_address' => 'true',
					'send_buffer_size' => '-1b',
				],
			],
			'no' => [
				'model' => [
					'state' => [
						'persist' => 'false',
					],
				],
			],
			'node' => [
				'attr' => [
					'xpack' => [
						'installed' => 'true',
					],
				],
				'data' => 'true',
				'enable_lucene_segment_infos_trace' => 'false',
				'id' => [
					'seed' => '0',
				],
				'ingest' => 'true',
				'local_storage' => 'true',
				'master' => 'true',
				'max_local_storage_nodes' => '1',
				'ml' => 'false',
				'name' => 'ec0f9510557a',
				'pidfile' => '',
				'portsfile' => 'false',
				'processors' => '2',
				'store' => [
					'allow_mmap' => 'true',
				],
				'voting_only' => 'false',
			],
			'path' => [
				'data' => [],
				'home' => '/usr/share/elasticsearch',
				'logs' => '/usr/share/elasticsearch/logs',
				'repo' => [],
				'shared_data' => '',
			],
			'pidfile' => '',
			'plugin' => [
				'mandatory' => [],
			],
			'processors' => '2',
			'reindex' => [
				'remote' => [
					'whitelist' => [],
				],
			],
			'repositories' => [
				'fs' => [
					'chunk_size' => '9223372036854775807b',
					'compress' => 'false',
					'location' => '',
				],
				'url' => [
					'allowed_urls' => [],
					'supported_protocols' => [
						'http',
						'https',
						'ftp',
						'file',
						'jar',
					],
					'url' => 'http:',
				],
			],
			'resource' => [
				'reload' => [
					'enabled' => 'true',
					'interval' => [
						'high' => '5s',
						'low' => '60s',
						'medium' => '30s',
					],
				],
			],
			'rest' => [
				'action' => [
					'multi' => [
						'allow_explicit_index' => 'true',
					],
				],
			],
			'script' => [
				'allowed_contexts' => [],
				'allowed_types' => [],
				'cache' => [
					'expire' => '0ms',
					'max_size' => '100',
				],
				'max_compilations_rate' => '75/5m',
				'max_size_in_bytes' => '65535',
				'painless' => [
					'regex' => [
						'enabled' => 'false',
					],
				],
			],
			'search' => [
				'default_allow_partial_results' => 'true',
				'default_keep_alive' => '5m',
				'default_search_timeout' => '-1',
				'highlight' => [
					'term_vector_multi_value' => 'true',
				],
				'keep_alive_interval' => '1m',
				'low_level_cancellation' => 'true',
				'max_buckets' => '10000',
				'max_keep_alive' => '24h',
				'max_open_scroll_context' => '500',
				'remote' => [
					'connect' => 'true',
					'connections_per_cluster' => '3',
					'initial_connect_timeout' => '30s',
					'node' => [
						'attr' => '',
					],
				],
			],
			'security' => [
				'manager' => [
					'filter_bad_defaults' => 'true',
				],
			],
			'slm' => [
				'history_index_enabled' => 'true',
				'retention_duration' => '1h',
				'retention_schedule' => '0 30 1 * * ?',
			],
			'thread_pool' => [
				'analyze' => [
					'queue_size' => '16',
					'size' => '1',
				],
				'estimated_time_interval' => '200ms',
				'fetch_shard_started' => [
					'core' => '1',
					'keep_alive' => '5m',
					'max' => '4',
				],
				'fetch_shard_store' => [
					'core' => '1',
					'keep_alive' => '5m',
					'max' => '4',
				],
				'flush' => [
					'core' => '1',
					'keep_alive' => '5m',
					'max' => '1',
				],
				'force_merge' => [
					'queue_size' => '-1',
					'size' => '1',
				],
				'generic' => [
					'core' => '4',
					'keep_alive' => '30s',
					'max' => '128',
				],
				'get' => [
					'queue_size' => '1000',
					'size' => '2',
				],
				'listener' => [
					'queue_size' => '-1',
					'size' => '1',
				],
				'management' => [
					'core' => '1',
					'keep_alive' => '5m',
					'max' => '5',
				],
				'refresh' => [
					'core' => '1',
					'keep_alive' => '5m',
					'max' => '1',
				],
				'search' => [
					'auto_queue_frame_size' => '2000',
					'max_queue_size' => '1000',
					'min_queue_size' => '1000',
					'queue_size' => '1000',
					'size' => '4',
					'target_response_time' => '1s',
				],
				'search_throttled' => [
					'auto_queue_frame_size' => '200',
					'max_queue_size' => '100',
					'min_queue_size' => '100',
					'queue_size' => '100',
					'size' => '1',
					'target_response_time' => '1s',
				],
				'snapshot' => [
					'core' => '1',
					'keep_alive' => '5m',
					'max' => '1',
				],
				'warmer' => [
					'core' => '1',
					'keep_alive' => '5m',
					'max' => '1',
				],
				'write' => [
					'queue_size' => '200',
					'size' => '2',
				],
			],
			'transform' => [
				'task_thread_pool' => [
					'queue_size' => '4',
					'size' => '4',
				],
			],
			'transport' => [
				'bind_host' => [],
				'compress' => 'false',
				'connect_timeout' => '30s',
				'connections_per_node' => [
					'bulk' => '3',
					'ping' => '1',
					'recovery' => '2',
					'reg' => '6',
					'state' => '1',
				],
				'features' => [
					'x-pack' => 'true',
				],
				'host' => [],
				'netty' => [
					'boss_count' => '1',
					'receive_predictor_max' => '64kb',
					'receive_predictor_min' => '64kb',
					'receive_predictor_size' => '64kb',
					'worker_count' => '4',
				],
				'ping_schedule' => '-1',
				'port' => '9300-9400',
				'publish_host' => [],
				'publish_port' => '-1',
				'tcp' => [
					'compress' => 'false',
					'connect_timeout' => '30s',
					'keep_alive' => 'true',
					'keep_count' => '-1',
					'keep_idle' => '-1',
					'keep_interval' => '-1',
					'no_delay' => 'true',
					'port' => '9300-9400',
					'receive_buffer_size' => '-1b',
					'reuse_address' => 'true',
					'send_buffer_size' => '-1b',
				],
				'tcp_no_delay' => 'true',
				'tracer' => [
					'exclude' => [
						'internal:discovery/zen/fd*',
						'internal:coordination/fault_detection/*',
						'cluster:monitor/nodes/liveness',
					],
					'include' => [],
				],
				'type' => 'security4',
				'type.default' => 'netty4',
			],
			'xpack' => [
				'ccr' => [
					'ccr_thread_pool' => [
						'queue_size' => '100',
						'size' => '32',
					],
					'enabled' => 'true',
				],
				'data_frame' => [
					'enabled' => 'true',
				],
				'enrich' => [
					'enabled' => 'true',
				],
				'flattened' => [
					'enabled' => 'true',
				],
				'graph' => [
					'enabled' => 'true',
				],
				'http' => [
					'default_connection_timeout' => '10s',
					'default_read_timeout' => '10s',
					'max_response_size' => '10mb',
					'proxy' => [
						'host' => '',
						'port' => '0',
						'scheme' => '',
					],
					'whitelist' => [
						'*',
					],
				],
				'ilm' => [
					'enabled' => 'true',
				],
				'license' => [
					'self_generated' => [
						'type' => 'basic',
					],
					'upload' => [
						'types' => [
							'standard',
							'gold',
							'platinum',
							'enterprise',
							'trial',
						],
					],
				],
				'logstash' => [
					'enabled' => 'true',
				],
				'ml' => [
					'autodetect_process' => 'true',
					'enable_config_migration' => 'true',
					'enabled' => 'false',
					'inference_model' => [
						'cache_size' => '40%',
						'time_to_live' => '5m',
					],
					'max_anomaly_records' => '500',
					'max_inference_processors' => '50',
					'max_lazy_ml_nodes' => '0',
					'max_machine_memory_percent' => '30',
					'max_model_memory_limit' => '0b',
					'max_open_jobs' => '20',
					'min_disk_space_off_heap' => '5gb',
					'node_concurrent_job_allocations' => '2',
					'persist_results_max_retries' => '20',
					'process_connect_timeout' => '10s',
				],
				'monitoring' => [
					'collection' => [
						'ccr' => [
							'stats' => [
								'timeout' => '10s',
							],
						],
						'cluster' => [
							'stats' => [
								'timeout' => '10s',
							],
						],
						'enabled' => 'false',
						'enrich' => [
							'stats' => [
								'timeout' => '10s',
							],
						],
						'index' => [
							'recovery' => [
								'active_only' => 'false',
								'timeout' => '10s',
							],
							'stats' => [
								'timeout' => '10s',
							],
						],
						'indices' => [],
						'interval' => '10s',
						'ml' => [
							'job' => [
								'stats' => [
									'timeout' => '10s',
								],
							],
						],
						'node' => [
							'stats' => [
								'timeout' => '10s',
							],
						],
					],
					'elasticsearch' => [
						'collection' => [
							'enabled' => 'true',
						],
					],
					'enabled' => 'true',
					'history' => [
						'duration' => '168h',
					],
				],
				'notification' => [
					'email' => [
						'default_account' => '',
						'html' => [
							'sanitization' => [
								'allow' => [
									'body',
									'head',
									'_tables',
									'_links',
									'_blocks',
									'_formatting',
									'img:embedded',
								],
								'disallow' => [],
								'enabled' => 'true',
							],
						],
					],
					'jira' => [
						'default_account' => '',
					],
					'pagerduty' => [
						'default_account' => '',
					],
					'reporting' => [
						'interval' => '15s',
						'retries' => '40',
						'warning' => [
							'enabled' => 'true',
						],
					],
					'slack' => [
						'default_account' => '',
					],
				],
				'rollup' => [
					'enabled' => 'true',
					'task_thread_pool' => [
						'queue_size' => '4',
						'size' => '4',
					],
				],
				'security' => [
					'audit' => [
						'enabled' => 'false',
						'logfile' => [
							'emit_node_host_address' => 'false',
							'emit_node_host_name' => 'false',
							'emit_node_id' => 'true',
							'emit_node_name' => 'false',
							'events' => [
								'emit_request_body' => 'false',
								'exclude' => [],
								'include' => [
									'ACCESS_DENIED',
									'ACCESS_GRANTED',
									'ANONYMOUS_ACCESS_DENIED',
									'AUTHENTICATION_FAILED',
									'CONNECTION_DENIED',
									'TAMPERED_REQUEST',
									'RUN_AS_DENIED',
									'RUN_AS_GRANTED',
								],
							],
						],
					],
					'authc' => [
						'anonymous' => [
							'authz_exception' => 'true',
							'roles' => [],
							'username' => '_anonymous',
						],
						'api_key' => [
							'cache' => [
								'hash_algo' => 'ssha256',
								'max_keys' => '10000',
								'ttl' => '24h',
							],
							'delete' => [
								'interval' => '24h',
								'timeout' => '-1',
							],
							'enabled' => 'false',
							'hashing' => [
								'algorithm' => 'pbkdf2',
							],
						],
						'password_hashing' => [
							'algorithm' => 'bcrypt',
						],
						'reserved_realm' => [
							'enabled' => 'true',
						],
						'run_as' => [
							'enabled' => 'true',
						],
						'success_cache' => [
							'enabled' => 'true',
							'expire_after_access' => '1h',
							'size' => '10000',
						],
						'token' => [
							'delete' => [
								'interval' => '30m',
								'timeout' => '-1',
							],
							'enabled' => 'false',
							'thread_pool' => [
								'queue_size' => '1000',
								'size' => '1',
							],
							'timeout' => '20m',
						],
					],
					'authz' => [
						'store' => [
							'roles' => [
								'cache' => [
									'max_size' => '10000',
								],
								'field_permissions' => [
									'cache' => [
										'max_size_in_bytes' => '104857600',
									],
								],
								'index' => [
									'cache' => [
										'max_size' => '10000',
										'ttl' => '20m',
									],
								],
								'negative_lookup_cache' => [
									'max_size' => '10000',
								],
							],
						],
					],
					'automata' => [
						'cache' => [
							'enabled' => 'true',
							'size' => '10000',
							'ttl' => '48h',
						],
						'max_determinized_states' => '100000',
					],
					'dls' => [
						'bitset' => [
							'cache' => [
								'size' => '10%',
								'ttl' => '2h',
							],
						],
					],
					'dls_fls' => [
						'enabled' => 'true',
					],
					'enabled' => 'true',
					'encryption' => [
						'algorithm' => 'AES/CTR/NoPadding',
					],
					'encryption_key' => [
						'algorithm' => 'AES',
						'length' => '128',
					],
					'filter' => [
						'always_allow_bound_address' => 'true',
					],
					'fips_mode' => [
						'enabled' => 'false',
					],
					'http' => [
						'filter' => [
							'allow' => [],
							'deny' => [],
							'enabled' => 'true',
						],
						'ssl' => [
							'enabled' => 'false',
						],
					],
					'ssl' => [
						'diagnose' => [
							'trust' => 'true',
						],
					],
					'transport' => [
						'filter' => [
							'allow' => [],
							'deny' => [],
							'enabled' => 'true',
						],
						'ssl' => [
							'enabled' => 'false',
						],
					],
					'user' => null,
				],
				'slm' => [
					'enabled' => 'true',
				],
				'sql' => [
					'enabled' => 'true',
				],
				'transform' => [
					'enabled' => 'true',
					'num_transform_failure_retries' => '10',
				],
				'vectors' => [
					'enabled' => 'true',
				],
				'watcher' => [
					'actions' => [
						'bulk' => [
							'default_timeout' => '',
						],
						'index' => [
							'default_timeout' => '',
						],
					],
					'bulk' => [
						'actions' => '1',
						'concurrent_requests' => '0',
						'flush_interval' => '1s',
						'size' => '1mb',
					],
					'enabled' => 'true',
					'encrypt_sensitive_data' => 'false',
					'execution' => [
						'default_throttle_period' => '5s',
						'scroll' => [
							'size' => '0',
							'timeout' => '',
						],
					],
					'history' => [
						'cleaner_service' => [
							'enabled' => 'true',
						],
					],
					'index' => [
						'rest' => [
							'direct_access' => '',
						],
					],
					'input' => [
						'search' => [
							'default_timeout' => '',
						],
					],
					'internal' => [
						'ops' => [
							'bulk' => [
								'default_timeout' => '',
							],
							'index' => [
								'default_timeout' => '',
							],
							'search' => [
								'default_timeout' => '',
							],
						],
					],
					'stop' => [
						'timeout' => '30s',
					],
					'thread_pool' => [
						'queue_size' => '1000',
						'size' => '10',
					],
					'transform' => [
						'search' => [
							'default_timeout' => '',
						],
					],
					'trigger' => [
						'schedule' => [
							'ticker' => [
								'tick_interval' => '500ms',
							],
						],
					],
					'watch' => [
						'scroll' => [
							'size' => '0',
						],
					],
				],
			],
		],
		'persistent' => [],
		'transient' => [],
	],
	'_cluster/stats' => [
		'_nodes' => [
			'failed' => 0,
			'successful' => 1,
			'total' => 1,
		],
		'cluster_name' => 'docker-cluster',
		'cluster_uuid' => 'DvtYzCYjT4uNj6vO_sJ2FA',
		'indices' => [
			'completion' => [
				'size_in_bytes' => 0,
			],
			'count' => 3,
			'docs' => [
				'count' => 6,
				'deleted' => 2,
			],
			'fielddata' => [
				'evictions' => 0,
				'memory_size_in_bytes' => 0,
			],
			'query_cache' => [
				'cache_count' => 0,
				'cache_size' => 0,
				'evictions' => 0,
				'hit_count' => 0,
				'memory_size_in_bytes' => 0,
				'miss_count' => 0,
				'total_count' => 0,
			],
			'segments' => [
				'count' => 5,
				'doc_values_memory_in_bytes' => 660,
				'file_sizes' => [],
				'fixed_bit_set_memory_in_bytes' => 240,
				'index_writer_memory_in_bytes' => 0,
				'max_unsafe_auto_id_timestamp' => -1,
				'memory_in_bytes' => 9129,
				'norms_memory_in_bytes' => 384,
				'points_memory_in_bytes' => 0,
				'stored_fields_memory_in_bytes' => 1560,
				'term_vectors_memory_in_bytes' => 0,
				'terms_memory_in_bytes' => 6525,
				'version_map_memory_in_bytes' => 0,
			],
			'shards' => [
				'index' => [
					'primaries' => [
						'avg' => 1.0,
						'max' => 1,
					],
					'replication' => [
						'avg' => 0.0,
						'max' => 0.0,
						'min' => 0.0,
					],
					'shards' => [
						'avg' => 1.0,
						'max' => 1,
						'min' => 1,
					],
				],
				'primaries' => 3,
				'replication' => 0.0,
				'total' => 3,
			],
			'store' => [
				'size_in_bytes' => 40113,
			],
		],
		'nodes' => [
			'count' => [
				'coordinating_only' => 0,
				'data' => 1,
				'ingest' => 1,
				'master' => 1,
				'ml' => 0,
				'total' => 1,
				'voting_only' => 0,
			],
			'discovery_types' => [
				'single-node' => 1,
			],
			'fs' => [
				'available_in_bytes' => 93619466240,
				'free_in_bytes' => 103636197376,
				'total_in_bytes' => 195721834496,
			],
			'ingest' => [
				'number_of_pipelines' => 1,
				'processor_stats' => [
					'gsub' => [
						'count' => 0,
						'current' => 0,
						'failed' => 0,
						'time_in_millis' => 0,
					],
					'script' => [
						'count' => 0,
						'current' => 0,
						'failed' => 0,
						'time_in_millis' => 0,
					],
				],
			],
			'jvm' => [
				'max_uptime_in_millis' => 180864407,
				'mem' => [
					'heap_max_in_bytes' => 1056309248,
					'heap_used_in_bytes' => 196453072,
				],
				'threads' => 44,
				'versions' => [
					[
						'bundled_jdk' => true,
						'count' => 1,
						'using_bundled_jdk' => true,
						'version' => '13.0.2',
						'vm_name' => 'OpenJDK 64-Bit Server VM',
						'vm_vendor' => 'AdoptOpenJDK',
						'vm_version' => '13.0.2+8',
					],
				],
			],
			'network_types' => [
				'http_types' => [
					'security4' => 1,
				],
				'transport_types' => [
					'security4' => 1,
				],
			],
			'os' => [
				'allocated_processors' => 2,
				'available_processors' => 2,
				'mem' => [
					'free_in_bytes' => 804139008,
					'free_percent' => 10,
					'total_in_bytes' => 8315695104,
					'used_in_bytes' => 7511556096,
					'used_percent' => 90,
				],
				'names' => [
					[
						'count' => 1,
						'name' => 'Linux',
					],
				],
				'pretty_names' => [
					[
						'count' => 1,
						'pretty_name' => 'CentOS Linux 7 (Core)',
					],
				],
			],
			'packaging_types' => [
				[
					'count' => 1,
					'flavor' => 'default',
					'type' => 'docker',
				],
			],
			'plugins' => [],
			'process' => [
				'cpu' => [
					'percent' => 0,
				],
				'open_file_descriptors' => [
					'avg' => 232,
					'max' => 232,
					'min' => 232,
				],
			],
			'versions' => [
				'7.10.0',
			],
		],
		'status' => 'green',
		'timestamp' => time(),
	],
];
