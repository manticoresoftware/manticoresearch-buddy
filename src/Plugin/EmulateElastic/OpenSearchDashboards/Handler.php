<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\EmulateElastic\OpenSearchDashboards;

use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use RuntimeException;

/**
 * Processes OpenSearch Dashboards requests and returns appropriate responses
 */
final class Handler extends BaseHandlerWithClient {

	/**
	 * Initialize the handler
	 * @param Payload $payload
	 */
	public function __construct(public Payload $payload) {
	}

	/**
	 * Process the request and return self for chaining
	 * @return Task
	 * @throws RuntimeException
	 */
	public function run(): Task {
		$taskFn = static function (Payload $payload, Client $manticoreClient): TaskResult {
			switch ($payload::$requestTarget) {
				case '_search':
					return self::handleSearch($payload, $manticoreClient);
				case '_cat':
					return self::handleCat($manticoreClient);
				case '_count':
					return self::handleCount($payload, $manticoreClient);
				case '_license':
					return self::handleLicense();
				case '_nodes':
					return self::handleNodes();
				case '_xpack':
					return self::handleXpack();
				case '.opensearch_dashboards':
				case '.opensearch_dashboards_task_manager':
					return self::handleOpenSearchDashboards($payload);
				default:
					return self::handleDefault();
			}
		};

		return Task::create(
			$taskFn,
			[$this->payload, $this->manticoreClient]
		)->run();
	}

	/**
	 * Handle search requests
	 * @param Payload $payload
	 * @param Client $manticoreClient
	 * @return TaskResult
	 */
	private static function handleSearch(Payload $payload, Client $manticoreClient): TaskResult {
		$request = json_decode($payload->body, true);
		if (!is_array($request)) {
			$request = [];
		}

		$query = 'SELECT * FROM ' . $payload->table;

		if (isset($request['query'])) {
			$query .= ' WHERE MATCH(\'@* ' . addslashes((string)json_encode($request['query'])) . '\')';
		}

		$size = $request['size'] ?? 10;
		$query .= ' LIMIT ' . (int)$size;
		/** @var array{0:array{data?:array<array{name:string,patterns:string,content:string,id?:int}>}} $result */
		$result = $manticoreClient->sendRequest($query)->getResult();
		$data = $result[0]['data'] ?? [];

		// Build OpenSearch response format
		$response = [
			'took' => 0,
			'timed_out' => false,
			'_shards' => [
				'total' => 1,
				'successful' => 1,
				'skipped' => 0,
				'failed' => 0,
			],
			'hits' => [
				'total' => [
					'value' => sizeof($data),
					'relation' => 'eq',
				],
				'max_score' => 1.0,
				'hits' => [],
			],
		];

		foreach ($data as $row) {
			$response['hits']['hits'][] = [
				'_index' => $payload->table,
				'_type' => '_doc',
				'_id' => $row['id'] ?? uniqid(),
				'_score' => 1.0,
				'_source' => $row,
			];
		}

		return TaskResult::raw($response);
	}

	/**
	 * Handle cat requests
	 * @param Client $manticoreClient
	 * @return TaskResult
	 */
	private static function handleCat(Client $manticoreClient): TaskResult {
		$query = 'SHOW TABLES';
		/** @var array{0:array{data?:array<array{Index?:string}>}} $result */
		$result = $manticoreClient->sendRequest($query)->getResult();
		$data = $result[0]['data'] ?? [];

		$response = [];
		foreach ($data as $row) {
			$tableName = $row['Index'] ?? '';
			$response[] = $tableName . "\t" . "open\t" . "1\t" . "1\t" . "0\t" . "0\t" . '0b';
		}

		return TaskResult::raw(implode("\n", $response));
	}

	/**
	 * Handle count requests
	 * @param Payload $payload
	 * @param Client $manticoreClient
	 * @return TaskResult
	 */
	private static function handleCount(Payload $payload, Client $manticoreClient): TaskResult {
		$query = 'SELECT COUNT(*) as count FROM ' . $payload->table;
		/** @var array{0:array{data?:array<array{count?:int}>}} $result */
		$result = $manticoreClient->sendRequest($query)->getResult();
		$data = $result[0]['data'] ?? [];
		$count = $data[0]['count'] ?? 0;

		$response = [
			'count' => (int)$count,
			'_shards' => [
				'total' => 1,
				'successful' => 1,
				'skipped' => 0,
				'failed' => 0,
			],
		];

		return TaskResult::raw($response);
	}

	/**
	 * Handle license requests
	 * @return TaskResult
	 */
	private static function handleLicense(): TaskResult {
		$response = [
			'license' => [
				'status' => 'active',
				'uid' => '12345678-1234-1234-1234-123456789012',
				'type' => 'basic',
				'issue_date' => '2024-01-01T00:00:00.000Z',
				'issue_date_in_millis' => 1704067200000,
				'expiry_date' => '2025-01-01T00:00:00.000Z',
				'expiry_date_in_millis' => 1735689600000,
				'max_nodes' => 1000,
				'issued_to' => 'Manticore Search',
				'issuer' => 'Manticore Software',
				'start_date_in_millis' => 1704067200000,
			],
		];

		return TaskResult::raw($response);
	}

	/**
	 * Handle nodes requests
	 * @return TaskResult
	 */
	private static function handleNodes(): TaskResult {
		$response = [
			'_nodes' => [
				'total' => 1,
				'successful' => 1,
				'failed' => 0,
			],
			'cluster_name' => 'manticore-cluster',
			'nodes' => [
				'node-1' => [
					'name' => 'manticore-node',
					'transport_address' => '127.0.0.1:9300',
					'host' => '127.0.0.1',
					'ip' => '127.0.0.1',
					'version' => '8.0.0',
					'build_hash' => 'abcdef123456',
					'roles' => ['data', 'ingest', 'master'],
					'attributes' => [],
					'os' => [
						'name' => 'Linux',
						'arch' => 'x86_64',
						'version' => '5.4.0',
					],
					'process' => [
						'refresh_interval_in_millis' => 1000,
						'id' => 12345,
					],
					'jvm' => [
						'pid' => 12345,
						'version' => '11.0.0',
						'vm_name' => 'OpenJDK 64-Bit Server VM',
						'vm_version' => '11.0.0',
						'vm_vendor' => 'Oracle Corporation',
					],
				],
			],
		];

		return TaskResult::raw($response);
	}

	/**
	 * Handle xpack requests
	 * @return TaskResult
	 */
	private static function handleXpack(): TaskResult {
		$response = [
			'build' => [
				'hash' => 'abcdef123456',
				'date' => '2024-01-01T00:00:00.000Z',
			],
			'license' => [
				'type' => 'basic',
				'mode' => 'basic',
				'status' => 'active',
			],
			'features' => [
				'aggregations' => [
					'available' => true,
					'enabled' => true,
				],
				'analytics' => [
					'available' => true,
					'enabled' => true,
				],
				'ccr' => [
					'available' => false,
					'enabled' => false,
				],
				'data_frame' => [
					'available' => false,
					'enabled' => false,
				],
				'data_science' => [
					'available' => false,
					'enabled' => false,
				],
				'graph' => [
					'available' => false,
					'enabled' => false,
				],
				'ilm' => [
					'available' => false,
					'enabled' => false,
				],
				'logstash' => [
					'available' => false,
					'enabled' => false,
				],
				'ml' => [
					'available' => false,
					'enabled' => false,
				],
				'monitoring' => [
					'available' => false,
					'enabled' => false,
				],
				'rollup' => [
					'available' => false,
					'enabled' => false,
				],
				'security' => [
					'available' => false,
					'enabled' => false,
				],
				'sql' => [
					'available' => true,
					'enabled' => true,
				],
				'watcher' => [
					'available' => false,
					'enabled' => false,
				],
			],
			'tagline' => 'You know, for search',
		];

		return TaskResult::raw($response);
	}

	/**
	 * Handle OpenSearch Dashboards requests
	 * @param Payload $payload
	 * @return TaskResult
	 */
	private static function handleOpenSearchDashboards(Payload $payload): TaskResult {
		$response = [
			'_index' => $payload->table,
			'_type' => '_doc',
			'_id' => 'config:1.0.0',
			'_version' => 1,
			'found' => true,
			'_source' => [
				'type' => 'config',
				'updated_at' => '2024-01-01T00:00:00.000Z',
				'config' => [
					'buildNum' => 1000,
					'defaultIndex' => 'manticore-index',
				],
			],
		];

		return TaskResult::raw($response);
	}

	/**
	 * Handle default requests
	 * @return TaskResult
	 */
	private static function handleDefault(): TaskResult {
		$response = [
			'acknowledged' => true,
			'status' => 'ok',
		];

		return TaskResult::raw($response);
	}
}
