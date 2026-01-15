<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

return [
	'.opensearch_dashboards/_doc/space%3Adefault' => [
		'_id' => 'space:default',
		'_index' => '.opensearch_dashboards_2',
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
	'.opensearch_dashboards/_doc/index-pattern' => [
		'_index' => '.opensearch_dashboards_2',
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
	'.opensearch_dashboards/_doc/config%3A1.0.0' => [
		'_id' => 'config:1.0.0',
		'_index' => '.opensearch_dashboards_2',
		'_primary_term' => 1,
		'_seq_no' => 0,
		'_source' => [
			'config' => [
				'buildNum' => 1000,
				'defaultIndex' => 'manticore-index',
			],
			'references' => [],
			'type' => 'config',
			'updated_at' => '2024-05-27T13:55:27.747Z',
		],
		'_type' => '_doc',
		'_version' => 2,
		'found' => true,
	],
	'.opensearch_dashboards' => [
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
					'space' => '4a1746014a75ade3a714e1db5763276f',
					'telemetry' => '257fd1d4b4fdbb9cb4b8a3b27da201e9',
					'url' => '11aaeb7f5f7fa5bb43f25e18ce26e7d9',
					'visualization' => 'd00f614b29a80360e1190193fd333bab',
				],
			],
			'properties' => [
				'action' => [
					'properties' => [
						'actionTypeId' => [
							'type' => 'keyword',
						],
						'config' => [
							'type' => 'object',
						],
						'name' => [
							'type' => 'keyword',
						],
						'secrets' => [
							'type' => 'object',
						],
					],
				],
				'action_task_params' => [
					'properties' => [
						'actionId' => [
							'type' => 'keyword',
						],
						'apiKey' => [
							'type' => 'keyword',
						],
						'consumer' => [
							'type' => 'keyword',
						],
						'executionId' => [
							'type' => 'keyword',
						],
						'params' => [
							'type' => 'object',
						],
						'relatedSavedObjects' => [
							'properties' => [
								'id' => [
									'type' => 'keyword',
								],
								'namespace' => [
									'type' => 'keyword',
								],
								'type' => [
									'type' => 'keyword',
								],
								'typeId' => [
									'type' => 'keyword',
								],
							],
							'type' => 'object',
						],
						'source' => [
							'type' => 'keyword',
						],
						'taskId' => [
							'type' => 'keyword',
						],
					],
				],
				'alert' => [
					'properties' => [
						'actions' => [
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
								],
							],
							'type' => 'object',
						],
						'alertTypeId' => [
							'type' => 'keyword',
						],
						'apiKey' => [
							'type' => 'keyword',
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
						'executionStatus' => [
							'properties' => [
								'error' => [
									'properties' => [
										'message' => [
											'type' => 'keyword',
										],
										'reason' => [
											'type' => 'keyword',
										],
									],
									'type' => 'object',
								],
								'lastExecutionDate' => [
									'type' => 'date',
								],
								'status' => [
									'type' => 'keyword',
								],
							],
							'type' => 'object',
						],
						'meta' => [
							'properties' => [
								'versionApiKeyLastmodified' => [
									'type' => 'keyword',
								],
							],
							'type' => 'object',
						],
						'muteAll' => [
							'type' => 'boolean',
						],
						'mutedInstanceIds' => [
							'type' => 'keyword',
						],
						'name' => [
							'type' => 'keyword',
						],
						'params' => [
							'type' => 'object',
						],
						'schedule' => [
							'properties' => [
								'interval' => [
									'type' => 'keyword',
								],
							],
							'type' => 'object',
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
						'updatedAt' => [
							'type' => 'date',
						],
						'updatedBy' => [
							'type' => 'keyword',
						],
					],
				],
				'config' => [
					'properties' => [
						'buildNum' => [
							'type' => 'long',
						],
						'defaultIndex' => [
							'type' => 'keyword',
						],
					],
				],
				'dashboard' => [
					'properties' => [
						'hits' => [
							'type' => 'long',
						],
						'kibanaSavedObjectMeta' => [
							'properties' => [
								'searchSourceJSON' => [
									'type' => 'keyword',
								],
							],
							'type' => 'object',
						],
						'optionsJSON' => [
							'type' => 'keyword',
						],
						'panelsJSON' => [
							'type' => 'keyword',
						],
						'refs' => [
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
							'type' => 'object',
						],
						'title' => [
							'type' => 'keyword',
						],
						'version' => [
							'type' => 'long',
						],
					],
				],
				'index-pattern' => [
					'properties' => [
						'fieldFormatMap' => [
							'type' => 'keyword',
						],
						'fields' => [
							'type' => 'keyword',
						],
						'intervalName' => [
							'type' => 'keyword',
						],
						'notExpandable' => [
							'type' => 'boolean',
						],
						'references' => [
							'type' => 'object',
						],
						'sourceFilters' => [
							'type' => 'keyword',
						],
						'timeFieldName' => [
							'type' => 'keyword',
						],
						'title' => [
							'type' => 'keyword',
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
							'type' => 'keyword',
						],
						'disabledFeatures' => [
							'type' => 'keyword',
						],
						'imageUrl' => [
							'type' => 'keyword',
						],
						'name' => [
							'type' => 'keyword',
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
							'type' => 'keyword',
						],
					],
				],
				'visualization' => [
					'properties' => [
						'hits' => [
							'type' => 'long',
						],
						'kibanaSavedObjectMeta' => [
							'properties' => [
								'searchSourceJSON' => [
									'type' => 'keyword',
								],
							],
							'type' => 'object',
						],
						'optionsJSON' => [
							'type' => 'keyword',
						],
						'refs' => [
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
							'type' => 'object',
						],
						'savedSearchRefName' => [
							'type' => 'keyword',
						],
						'title' => [
							'type' => 'keyword',
						],
						'uiStateJSON' => [
							'type' => 'keyword',
						],
						'version' => [
							'type' => 'long',
						],
						'visState' => [
							'type' => 'keyword',
						],
					],
				],
			],
		],
		'settings' => [
			'index' => [
				'number_of_shards' => 1,
				'number_of_replicas' => 0,
			],
		],
	],
];
