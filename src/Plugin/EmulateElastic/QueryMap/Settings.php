<?php declare(strict_types=1);

/*
  Copyright (c) 2024-present, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

return [
	'.kibana/_doc/space%3Adefault' => [
		'_id' => 'space:default',
		'_index' => '.kibana_2',
		'_primary_term' => 1,
		'_seq_no' => 0,
		'_source' => [
			'migrationVersion' => [
				'space' => '6.6.0',
			],
			'references' => [],
			'space' => [
				'_reserved' => true,
				'color' => '#00bfb3',
				'description' => 'This is your default space!',
				'disabledFeatures' => [],
				'name' => 'Default',
			],
			'type' => 'space',
			'updated_at' => '2024-05-27T13:55:01.278Z',
		],
		'_type' => '_doc',
		'_version' => 1,
		'found' => true,
	],
	'.kibana/_doc/index-pattern' => [
		'_index' => '.kibana_2',
		'_primary_term' => 1,
		'_seq_no' => 0,
		'_shards' => [
			'failed' => 0,
			'successful' => 1,
			'total' => 1,
		],
		'_type' => '_doc',
		'_version' => 1,
		'result' => 'created',
	],
	'.kibana/_doc/config%3A7.6.0' => [
		'_id' => 'config:7.6.0',
		'_index' => '.kibana_2',
		'_primary_term' => 1,
		'_seq_no' => 0,
		'_source' => [
			'config' => [
				'buildNum' => 29000,
				//'defaultIndex' => 'c55ae0d0-1c30-11ef-95ab-51d1bb8fca73'
			],
			'references' => [],
			'type' => 'config',
			'updated_at' => '',//,'2024-05-27T13:55:27.747Z'
		],
		'_type' => '_doc',
		'_version' => 2,
		'found' => true,
	],
	'.kibana' => [
		'.kibana_1' => [
			'aliases' => [
				'.kibana' => [],
			],
			'mappings' => [
				'_meta' => [
					'migrationMappingPropertyHashes' => [
						'action' => 'c0c235fba02ebd2a2412bcda79009b58',
						'action_task_params' => 'a9d49f184ee89641044be0ca2950fa3a',
						'alert' => 'e588043a01d3d43477e7cad7efa0f5d8',
						'apm-indices' => '9bb9b2bf1fa636ed8619cbab5ce6a1dd',
						'apm-services-telemetry' => '07ee1939fa4302c62ddc052ec03fed90',
						'canvas-element' => '7390014e1091044523666d97247392fc',
						'canvas-workpad' => 'b0a1706d356228dbdcb4a17e6b9eb231',
						'config' => '87aca8fdb053154f11383fce3dbf3edf',
						'dashboard' => 'd00f614b29a80360e1190193fd333bab',
						'file-upload-telemetry' => '0ed4d3e1983d1217a30982630897092e',
						'graph-workspace' => 'cd7ba1330e6682e9cc00b78850874be1',
						'index-pattern' => '66eccb05066c5a89924f48a9e9736499',
						'infrastructure-ui-source' => 'ddc0ecb18383f6b26101a2fadb2dab0c',
						'inventory-view' => '84b320fd67209906333ffce261128462',
						'kql-telemetry' => 'd12a98a6f19a2d273696597547e064ee',
						'lens' => '21c3ea0763beb1ecb0162529706b88c5',
						'lens-ui-telemetry' => '509bfa5978586998e05f9e303c07a327',
						'map' => '23d7aa4a720d4938ccde3983f87bd58d',
						'maps-telemetry' => '268da3a48066123fc5baf35abaa55014',
						'metrics-explorer-view' => '53c5365793677328df0ccb6138bf3cdd',
						'migrationVersion' => '4a1746014a75ade3a714e1db5763276f',
						'ml-telemetry' => '257fd1d4b4fdbb9cb4b8a3b27da201e9',
						'namespace' => '2f4316de49999235636386fe51dc06c1',
						'query' => '11aaeb7f5f7fa5bb43f25e18ce26e7d9',
						'references' => '7997cf5a56cc02bdc9c93361bde732b0',
						'sample-data-telemetry' => '7d3cfeb915303c9641c59681967ffeb4',
						'search' => '181661168bbadd1eff5902361e2a0d5c',
						'server' => 'ec97f1c5da1a19609a60874e5af1100c',
						'siem-detection-engine-rule-status' => '0367e4d775814b56a4bee29384f9aafe',
						'siem-ui-timeline' => 'ac8020190f5950dd3250b6499144e7fb',
						'siem-ui-timeline-note' => '8874706eedc49059d4cf0f5094559084',
						'siem-ui-timeline-pinned-event' => '20638091112f0e14f0e443d512301c29',
						'space' => 'c5ca8acafa0beaa4d08d014a97b6bc6b',
						'telemetry' => '358ffaa88ba34a97d55af0933a117de4',
						'timelion-sheet' => '9a2a2748877c7a7b582fef201ab1d4cf',
						'tsvb-validation-telemetry' => '3a37ef6c8700ae6fc97d5c7da00e9215',
						'type' => '2f4316de49999235636386fe51dc06c1',
						'ui-metric' => '0d409297dc5ebe1e3a1da691c6ee32e3',
						'updated_at' => '00da57df13e94e9d98437d13ace4bfe0',
						'upgrade-assistant-reindex-operation' => 'a53a20fe086b72c9a86da3cc12dad8a6',
						'upgrade-assistant-telemetry' => '56702cec857e0a9dacfb696655b4ff7b',
						'url' => 'c7f66a0df8b1b52f17c28c4adb111105',
						'visualization' => '52d7a13ad68a150c4525b292d23e12cc',
					],
				],
				'dynamic' => 'strict',
				'properties' => [
					'action' => [
						'properties' => [
							'actionTypeId' => [
								'type' => 'keyword',
							],
							'config' => [
								'enabled' => false,
								'type' => 'object',
							],
							'name' => [
								'type' => 'text',
							],
							'secrets' => [
								'type' => 'binary',
							],
						],
					],
					'action_task_params' => [
						'properties' => [
							'actionId' => [
								'type' => 'keyword',
							],
							'apiKey' => [
								'type' => 'binary',
							],
							'params' => [
								'type' => 'object',
								'enabled' => false,
							],
						],
					],
					'alert' => [
						'properties' => [
							'actions' => [
								'type' => 'nested',
								'properties' => [
									'actionRef' => [
										'type' => 'keyword',
									],
									'actionTypeId' => [
										'type' => 'keyword',
									],
									'group' => [
										'type' => 'keyword',
									],
									'params' => [
										'type' => 'object',
										'enabled' => false,
									],
								],
							],
							'alertTypeId' => [
								'type' => 'keyword',
							],
							'apiKey' => [
								'type' => 'binary',
							],
							'apiKeyOwner' => [
								'type' => 'keyword',
							],
							'consumer' => [
								'type' => 'keyword',
							],
							'createdAt' => [
								'type' => 'date',
							],
							'createdBy' => [
								'type' => 'keyword',
							],
							'enabled' => [
								'type' => 'boolean',
							],
							'muteAll' => [
								'type' => 'boolean',
							],
							'mutedInstanceIds' => [
								'type' => 'keyword',
							],
							'name' => [
								'type' => 'text',
							],
							'params' => [
								'type' => 'object',
								'enabled' => false,
							],
							'schedule' => [
								'properties' => [
									'interval' => [
										'type' => 'keyword',
									],
								],
							],
							'scheduledTaskId' => [
								'type' => 'keyword',
							],
							'tags' => [
								'type' => 'keyword',
							],
							'throttle' => [
								'type' => 'keyword',
							],
							'updatedBy' => [
								'type' => 'keyword',
							],
						],
					],
					'apm-indices' => [
						'properties'  => [
							'apm_oss' => [
								'properties' => [
									'errorIndices' => [
										'type' => 'keyword',
									],
									'metricsIndices' => [
										'type' => 'keyword',
									],
									'onboardingIndices' => [
										'type' => 'keyword',
									],
									'sourcemapIndices' => [
										'type' => 'keyword',
									],
									'spanIndices' => [
										'type' => 'keyword',
									],
									'transactionIndices' => [
										'type' => 'keyword',
									],
								],
							],
						],
					],
					'apm-services-telemetry' => [
						'properties' => [
							'has_any_services' => [
								'type' => 'boolean',
							],
							'services_per_agent' => [
								'properties' => [
									'dotnet' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'go' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'java' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'js-base' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'nodejs' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'python' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'ruby' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'rum-js' => [
										'type' => 'long',
										'null_value' => 0,
									],
								],
							],
						],
					],
					'canvas-element'  => [
						'dynamic' => 'false',
						'properties' => [
							'@created' => [
								'type' => 'date',
							],
							'@timestamp' => [
								'type' => 'date',
							],
							'content' => [
								'type' => 'text',
							],
							'help' => [
								'type' => 'text',
							],
							'image' => [
								'type' => 'text',
							],
							'name' => [
								'type' => 'text',
								'fields' => [
									'keyword' => [
										'type' => 'keyword',
									],
								],
							],
						],
					],
					'canvas-workpad' => [
						'dynamic' => 'false',
						'properties' => [
							'@created' => [
								'type' => 'date',
							],
							'@timestamp' => [
								'type' => 'date',
							],
							'name' => [
								'type' => 'text',
								'fields' => [
									'keyword' => [
										'type' => 'keyword',
									],
								],
							],
						],
					],
					'config' => [
						'dynamic' => 'true',
						'properties' => [
							'buildNum' => [
								'type' => 'keyword',
							],
						],
					],
					'dashboard' => [
						'properties' => [
							'description' => [
								'type' => 'text',
							],
							'hits' => [
								'type' => 'integer',
							],
							'kibanaSavedObjectMeta' => [
								'properties' => [
									'searchSourceJSON' => [
										'type' => 'text',
									],
								],
							],
							'optionsJSON' => [
								'type' => 'text',
							],
							'panelsJSON' => [
								'type' => 'text',
							],
							'refreshInterval' => [
								'properties' => [
									'display' => [
										'type' => 'keyword',
									],
									'pause' => [
										'type' => 'boolean',
									],
									'section' => [
										'type' => 'integer',
									],
									'value' => [
										'type' => 'integer',
									],
								],
							],
							'timeFrom' => [
								'type' => 'keyword',
							],
							'timeRestore' => [
								'type' => 'boolean',
							],
							'timeTo' => [
								'type' => 'keyword',
							],
							'title' => [
								'type' => 'text',
							],
							'version' => [
								'type' => 'integer',
							],
						],
					],
					'file-upload-telemetry' => [
						'properties' => [
							'filesUploadedTotalCount' => [
								'type' => 'long',
							],
						],
					],
					'graph-workspace' => [
						'properties' => [
							'description' => [
								'type' => 'text',
							],
							'kibanaSavedObjectMeta' => [
								'properties' => [
									'searchSourceJSON' => [
										'type' => 'text',
									],
								],
							],
							'numLinks' => [
								'type' => 'integer',
							],
							'numVertices' => [
								'type' => 'integer',
							],
							'title' => [
								'type' => 'text',
							],
							'version' => [
								'type' => 'integer',
							],
							'wsState' => [
								'type' => 'text',
							],
						],
					],
					'index-pattern' => [
						'properties' => [
							'fieldFormatMap' => [
								'type' => 'text',
							],
							'fields' => [
								'type' => 'text',
							],
							'intervalName' => [
								'type' => 'keyword',
							],
							'notExpandable' => [
								'type' => 'boolean',
							],
							'sourceFilters' => [
								'type' => 'text',
							],
							'timeFieldName' => [
								'type' => 'keyword',
							],
							'title' => [
								'type' => 'text',
							],
							'type' => [
								'type' => 'keyword',
							],
							'typeMeta' => [
								'type' => 'keyword',
							],
						],
					],
					'infrastructure-ui-source' => [
						'properties' => [
							'description' => [
								'type' => 'text',
							],
							'fields' => [
								'properties' => [
									'container' => [
										'type' => 'keyword',
									],
									'host' => [
										'type' => 'keyword',
									],
									'pod' => [
										'type' => 'keyword',
									],
									'tiebreaker' => [
										'type' => 'keyword',
									],
									'timestamp' => [
										'type' => 'keyword',
									],
								],
							],
							'logAlias' => [
								'type' => 'keyword',
							],
							'logColumns' => [
								'type' => 'nested',
								'properties' => [
									'fieldColumn' => [
										'properties' => [
											'field' => [
												'type' => 'keyword',
											],
											'id' => [
												'type' => 'keyword',
											],
										],
									],
									'messageColumn' => [
										'properties' => [
											'id' => [
												'type' => 'keyword',
											],
										],
									],
									'timestampColumn' => [
										'properties' => [
											'id' => [
												'type' => 'keyword',
											],
										],
									],
								],
							],
							'metricAlias' => [
								'type' => 'keyword',
							],
							'name' => [
								'type' => 'text',
							],
						],
					],
					'inventory-view' => [
						'properties' => [
							'autoBounds' => [
								'type' => 'boolean',
							],
							'autoReload' => [
								'type' => 'boolean',
							],
							'boundsOverride' => [
								'properties' => [
									'max' => [
										'type' => 'integer',
									],
									'min' => [
										'type' => 'integer',
									],
								],
							],
							'customOptions' => [
								'type' => 'nested',
								'properties' => [
									'field' => [
										'type' => 'keyword',
									],
									'text' => [
										'type' => 'keyword',
									],
								],
							],
							'filterQuery' => [
								'properties' => [
									'expression' => [
										'type' => 'keyword',
									],
									'kind' => [
										'type' => 'keyword',
									],
								],
							],
							'groupBy' => [
								'type' => 'nested',
								'properties' => [
									'field' => [
										'type' => 'keyword',
									],
									'label' => [
										'type' => 'keyword',
									],
								],
							],
							'metric' => [
								'properties' => [
									'type' => [
										'type' => 'keyword',
									],
								],
							],
							'name' => [
								'type' => 'keyword',
							],
							'nodeType' => [
								'type' => 'keyword',
							],
							'time' => [
								'type' => 'integer',
							],
							'view' => [
								'type' => 'keyword',
							],
						],
					],
					'kql-telemetry' => [
						'properties' => [
							'optInCount' => [
								'type' => 'long',
							],
							'optOutCount' => [
								'type' => 'long',
							],
						],
					],
					'lens' => [
						'properties' => [
							'expression' => [
								'type' => 'keyword',
								'index' => false,
							],
							'state' => [
								'type' => 'flattened',
							],
							'title' => [
								'type' => 'text',
							],
							'visualizationType' => [
								'type' => 'keyword',
							],
						],
					],
					'lens-ui-telemetry' => [
						'properties' => [
							'count' => [
								'type' => 'integer',
							],
							'date' => [
								'type' => 'date',
							],
							'name' => [
								'type' => 'keyword',
							],
							'type' => [
								'type' => 'keyword',
							],
						],
					],
					'map' => [
						'properties' => [
							'bounds' => [
								'type' => 'geo_shape',
							],
							'description' => [
								'type' => 'text',
							],
							'layerListJSON' => [
								'type' => 'text',
							],
							'mapStateJSON' => [
								'type' => 'text',
							],
							'title' => [
								'type' => 'text',
							],
							'uiStateJSON' => [
								'type' => 'text',
							],
							'version' => [
								'type' => 'integer',
							],
						],
					],
					'maps-telemetry' => [
						'properties' => [
							'attributesPerMap' => [
								'properties' => [
									'dataSourcesCount' => [
										'properties' => [
											'avg' => [
												'type' => 'long',
											],
											'max' => [
												'type' => 'long',
											],
											'min' => [
												'type' => 'long',
											],
										],
									],
									'emsVectorLayersCount' => [
										'type' => 'object',
										'dynamic' => 'true',
									],
									'layerTypesCount' => [
										'type' => 'object',
										'dynamic' => 'true',
									],
									'layersCount' => [
										'properties' => [
											'avg' => [
												'type' => 'long',
											],
											'max' => [
												'type' => 'long',
											],
											'min' => [
												'type' => 'long',
											],
										],
									],
								],
							],
							'indexPatternsWithGeoFieldCount' => [
								'type' => 'long',
							],
							'mapsTotalCount' => [
								'type' => 'long',
							],
							'settings' => [
								'properties' => [
									'showMapVisualizationTypes' => [
										'type' => 'boolean',
									],
								],
							],
							'timeCaptured' => [
								'type' => 'date',
							],
						],
					],
					'metrics-explorer-view' => [
						'properties' => [
							'chartOptions' => [
								'properties' => [
									'stack' => [
										'type' => 'boolean',
									],
									'type' => [
										'type' => 'keyword',
									],
									'yAxisMode' => [
										'type' => 'keyword',
									],
								],
							],
							'currentTimerange' => [
								'properties' => [
									'from' => [
										'type' => 'keyword',
									],
									'interval' => [
										'type' => 'keyword',
									],
									'to' => [
										'type' => 'keyword',
									],
								],
							],
							'name' => [
								'type' => 'keyword',
							],
							'options' => [
								'properties' => [
									'aggregation' => [
										'type' => 'keyword',
									],
									'filterQuery' => [
										'type' => 'keyword',
									],
									'groupBy' => [
										'type' => 'keyword',
									],
									'limit' => [
										'type' => 'integer',
									],
									'metrics' => [
										'type' => 'nested',
										'properties' => [
											'aggregation' => [
												'type' => 'keyword',
											],
											'color' => [
												'type' => 'keyword',
											],
											'field' => [
												'type' => 'keyword',
											],
											'label' => [
												'type' => 'keyword',
											],
										],
									],
								],
							],
						],
					],
					'migrationVersion' => [
						'dynamic' => 'true',
						'properties' => [
							'space' => [
								'type' => 'text',
								'fields' => [
									'keyword' => [
										'type' => 'keyword',
										'ignore_above' => 256,
									],
								],
							],
						],
					],
					'ml-telemetry' => [
						'properties' => [
							'file_data_visualizer' => [
								'properties' => [
									'index_creation_count' => [
										'type' => 'long',
									],
								],
							],
						],
					],
					'namespace' => [
						'type' => 'keyword',
					],
					'query' => [
						'properties' => [
							'description' => [
								'type' => 'text',
							],
							'filters' => [
								'type' => 'object',
								'enabled' => false,
							],
							'query' => [
								'properties' => [
									'language' => [
										'type' => 'keyword',
									],
									'query' => [
										'type' => 'keyword',
										'index' => false,
									],
								],
							],
							'timefilter' => [
								'type' => 'object',
								'enabled' => false,
							],
							'title' => [
								'type' => 'text',
							],
						],
					],
					'references' => [
						'type' => 'nested',
						'properties' => [
							'id' => [
								'type' => 'keyword',
							],
							'name' => [
								'type' => 'keyword',
							],
							'type' => [
								'type' => 'keyword',
							],
						],
					],
					'sample-data-telemetry' => [
						'properties' => [
							'installCount' => [
								'type' => 'long',
							],
							'unInstallCount' => [
								'type' => 'long',
							],
						],
					],
					'search' => [
						'properties' => [
							'columns' => [
								'type' => 'keyword',
							],
							'description' => [
								'type' => 'text',
							],
							'hits' => [
								'type' => 'integer',
							],
							'kibanaSavedObjectMeta' => [
								'properties' => [
									'searchSourceJSON' => [
										'type' => 'text',
									],
								],
							],
							'sort' => [
								'type' => 'keyword',
							],
							'title' => [
								'type' => 'text',
							],
							'version' => [
								'type' => 'integer',
							],
						],
					],
					'server' => [
						'properties' => [
							'uuid' => [
								'type' => 'keyword',
							],
						],
					],
					'siem-detection-engine-rule-status' => [
						'properties' => [
							'alertId' => [
								'type' => 'keyword',
							],
							'lastFailureAt' => [
								'type' => 'date',
							],
							'lastFailureMessage' => [
								'type' => 'text',
							],
							'lastSuccessAt' => [
								'type' => 'date',
							],
							'lastSuccessMessage' => [
								'type' => 'text',
							],
							'status' => [
								'type' => 'keyword',
							],
							'statusDate' => [
								'type' => 'date',
							],
						],
					],
					'siem-ui-timeline' => [
						'properties' => [
							'columns' => [
								'properties' => [
									'aggregatable' => [
										'type' => 'boolean',
									],
									'category' => [
										'type' => 'keyword',
									],
									'columnHeaderType' => [
										'type' => 'keyword',
									],
									'description' => [
										'type' => 'text',
									],
									'example' => [
										'type' => 'text',
									],
									'id' => [
										'type' => 'keyword',
									],
									'indexes' => [
										'type' => 'keyword',
									],
									'name' => [
										'type' => 'text',
									],
									'placeholder' => [
										'type' => 'text',
									],
									'searchable' => [
										'type' => 'boolean',
									],
									'type' => [
										'type' => 'keyword',
									],
								],
							],
							'created' => [
								'type' => 'date',
							],
							'createdBy' => [
								'type' => 'text',
							],
							'dataProviders' => [
								'properties' => [
									'and' => [
										'properties' => [
											'enabled' => [
												'type' => 'boolean',
											],
											'excluded' => [
												'type' => 'boolean',
											],
											'id' => [
												'type' => 'keyword',
											],
											'kqlQuery' => [
												'type' => 'text',
											],
											'name' => [
												'type' => 'text',
											],
											'queryMatch' => [
												'properties' => [
													'displayField' => [
														'type' => 'text',
													],
													'displayValue' => [
														'type' => 'text',
													],
													'field' => [
														'type' => 'text',
													],
													'operator' => [
														'type' => 'text',
													],
													'value' => [
														'type' => 'text',
													],
												],
											],
										],
									],
									'enabled' => [
										'type' => 'boolean',
									],
									'excluded' => [
										'type' => 'boolean',
									],
									'id' => [
										'type' => 'keyword',
									],
									'kqlQuery' => [
										'type' => 'text',
									],
									'name' => [
										'type' => 'text',
									],
									'queryMatch' => [
										'properties' => [
											'displayField' => [
												'type' => 'text',
											],
											'displayValue' => [
												'type' => 'text',
											],
											'field' => [
												'type' => 'text',
											],
											'operator' => [
												'type' => 'text',
											],
											'value' => [
												'type' => 'text',
											],
										],
									],
								],
							],
							'dateRange' => [
								'properties' => [
									'end' => [
										'type' => 'date',
									],
									'start' => [
										'type' => 'date',
									],
								],
							],
							'description' => [
								'type' => 'text',
							],
							'eventType' => [
								'type' => 'keyword',
							],
							'favorite' => [
								'properties' => [
									'favoriteDate' => [
										'type' => 'date',
									],
									'fullName' => [
										'type' => 'text',
									],
									'keySearch' => [
										'type' => 'text',
									],
									'userName' => [
										'type' => 'text',
									],
								],
							],
							'filters' => [
								'properties' => [
									'exists' => [
										'type' => 'text',
									],
									'match_all' => [
										'type' => 'text',
									],
									'meta' => [
										'properties' => [
											'alias' => [
												'type' => 'text',
											],
											'controlledBy' => [
												'type' => 'text',
											],
											'disabled' => [
												'type' => 'boolean',
											],
											'field' => [
												'type' => 'text',
											],
											'formattedValue' => [
												'type' => 'text',
											],
											'index' => [
												'type' => 'keyword',
											],
											'key' => [
												'type' => 'keyword',
											],
											'negate' => [
												'type' => 'boolean',
											],
											'params' => [
												'type' => 'text',
											],
											'type' => [
												'type' => 'keyword',
											],
											'value' => [
												'type' => 'text',
											],
										],
									],
									'missing' => [
										'type' => 'text',
									],
									'query' => [
										'type' => 'text',
									],
									'range' => [
										'type' => 'text',
									],
									'script' => [
										'type' => 'text',
									],
								],
							],
							'kqlMode' => [
								'type' => 'keyword',
							],
							'kqlQuery' => [
								'properties' => [
									'filterQuery' => [
										'properties' => [
											'kuery' => [
												'properties' => [
													'expression' => [
														'type' => 'text',
													],
													'kind' => [
														'type' => 'keyword',
													],
												],
											],
											'serializedQuery' => [
												'type' => 'text',
											],
										],
									],
								],
							],
							'savedQueryId' => [
								'type' => 'keyword',
							],
							'sort' => [
								'properties' => [
									'columnId' => [
										'type' => 'keyword',
									],
									'sortDirection' => [
										'type' => 'keyword',
									],
								],
							],
							'title' => [
								'type' => 'text',
							],
							'updated' => [
								'type' => 'date',
							],
							'updatedBy' => [
								'type' => 'text',
							],
						],
					],
					'siem-ui-timeline-note' => [
						'properties' => [
							'created' => [
								'type' => 'date',
							],
							'createdBy' => [
								'type' => 'text',
							],
							'eventId' => [
								'type' => 'keyword',
							],
							'note' => [
								'type' => 'text',
							],
							'timelineId' => [
								'type' => 'keyword',
							],
							'updated' => [
								'type' => 'date',
							],
							'updatedBy' => [
								'type' => 'text',
							],
						],
					],
					'siem-ui-timeline-pinned-event' => [
						'properties' => [
							'created' => [
								'type' => 'date',
							],
							'createdBy' => [
								'type' => 'text',
							],
							'eventId' => [
								'type' => 'keyword',
							],
							'timelineId' => [
								'type' => 'keyword',
							],
							'updated' => [
								'type' => 'date',
							],
							'updatedBy' => [
								'type' => 'text',
							],
						],
					],
					'space' => [
						'properties' => [
							'_reserved' => [
								'type' => 'boolean',
							],
							'color' => [
								'type' => 'keyword',
							],
							'description' => [
								'type' => 'text',
							],
							'disabledFeatures' => [
								'type' => 'keyword',
							],
							'imageUrl' => [
								'type' => 'text',
								'index' => false,
							],
							'initials' => [
								'type' => 'keyword',
							],
							'name' => [
								'type' => 'text',
								'fields' => [
									'keyword' => [
										'type' => 'keyword',
										'ignore_above' => 2048,
									],
								],
							],
						],
					],
					'telemetry' => [
						'properties' => [
							'enabled' => [
								'type' => 'boolean',
							],
							'lastReported' => [
								'type' => 'date',
							],
							'lastVersionChecked' => [
								'type' => 'keyword',
								'ignore_above' => 256,
							],
							'sendUsageFrom' => [
								'type' => 'keyword',
								'ignore_above' => 256,
							],
							'userHasSeenNotice' => [
								'type' => 'boolean',
							],
						],
					],
					'timelion-sheet' => [
						'properties' => [
							'description' => [
								'type' => 'text',
							],
							'hits' => [
								'type' => 'integer',
							],
							'kibanaSavedObjectMeta' => [
								'properties' => [
									'searchSourceJSON' => [
										'type' => 'text',
									],
								],
							],
							'timelion_chart_height' => [
								'type' => 'integer',
							],
							'timelion_columns' => [
								'type' => 'integer',
							],
							'timelion_interval' => [
								'type' => 'keyword',
							],
							'timelion_other_interval' => [
								'type' => 'keyword',
							],
							'timelion_rows' => [
								'type' => 'integer',
							],
							'timelion_sheet' => [
								'type' => 'text',
							],
							'title' => [
								'type' => 'text',
							],
							'version' => [
								'type' => 'integer',
							],
						],
					],
					'tsvb-validation-telemetry' => [
						'properties' => [
							'failedRequests' => [
								'type' => 'long',
							],
						],
					],
					'type' => [
						'type' => 'keyword',
					],
					'ui-metric' => [
						'properties' => [
							'count' => [
								'type' => 'integer',
							],
						],
					],
					'updated_at' => [
						'type' => 'date',
					],
					'upgrade-assistant-reindex-operation' => [
						'dynamic' => 'true',
						'properties' => [
							'indexName' => [
								'type' => 'keyword',
							],
							'status' => [
								'type' => 'integer',
							],
						],
					],
					'upgrade-assistant-telemetry' => [
						'properties' => [
							'features' => [
								'properties' => [
									'deprecation_logging' => [
										'properties' => [
											'enabled' => [
												'type' => 'boolean',
												'null_value' => true,
											],
										],
									],
								],
							],
							'ui_open' => [
								'properties' => [
									'cluster' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'indices' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'overview' => [
										'type' => 'long',
										'null_value' => 0,
									],
								],
							],
							'ui_reindex' => [
								'properties' => [
									'close' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'open' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'start' => [
										'type' => 'long',
										'null_value' => 0,
									],
									'stop' => [
										'type' => 'long',
										'null_value' => 0,
									],
								],
							],
						],
					],
					'url' => [
						'properties' => [
							'accessCount' => [
								'type' => 'long',
							],
							'accessDate' => [
								'type' => 'date',
							],
							'createDate' => [
								'type' => 'date',
							],
							'url' => [
								'type' => 'text',
								'fields' => [
									'keyword' => [
										'type' => 'keyword',
										'ignore_above' => 2048,
									],
								],
							],
						],
					],
					'visualization' => [
						'properties' => [
							'description' => [
								'type' => 'text',
							],
							'kibanaSavedObjectMeta' => [
								'properties' => [
									'searchSourceJSON' => [
										'type' => 'text',
									],
								],
							],
							'savedSearchRefName' => [
								'type' => 'keyword',
							],
							'title' => [
								'type' => 'text',
							],
							'uiStateJSON' => [
								'type' => 'text',
							],
							'version' => [
								'type' => 'integer',
							],
							'visState' => [
								'type' => 'text',
							],
						],
					],
				],
			],
			'settings' => [
				'index' => [
					'number_of_shards' => '1',
					'auto_expand_replicas' => '0-1',
					'provided_name' => '.kibana_1',
					'creation_date' => '1712030677366',
					'number_of_replicas' => '0',
					'uuid' => 'ZDATed16QB6eicmz1hq9dg',
					'version' => [
						'created' => '7060099',
					],
				],
			],
		],
	],
];
