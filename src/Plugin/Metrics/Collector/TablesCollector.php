<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class TablesCollector implements CollectorInterface {

	/**
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		if ($context->tables === []) {
			$request = $client->sendRequest('SHOW TABLES');
			if ($request->hasError()) {
				ManticoreSearchResponseError::throw((string)$request->getError());
			}

			$result = $request->getResult()->toArray();
			if (is_array($result[0]) && !empty($result[0]['data'])) {
				$context->tables = $this->extractTablesFromRows($result[0]['data']);
			}
		}

		$store->addDirect(
			'tables_count',
			'gauge',
			'Number of tables returned by SHOW TABLES',
			sizeof($context->tables)
		);

		$this->processTableMetrics($context->tables, $client, $store, $context);
	}

	/**
	 * @param array<int, array{Table?:string,Index?:string,Type?:string}> $rows
	 * @return array<string, string>
	 */
	private function extractTablesFromRows(array $rows): array {
		$tables = [];
		foreach ($rows as $row) {
			$tableName = trim($row['Index'] ?? $row['Table'] ?? '');
			if ($tableName === '') {
				continue;
			}

			$type = trim($row['Type'] ?? '');
			if ($type === '') {
				continue;
			}

			$tables[$tableName] = $type;
		}

		return $tables;
	}

	/**
	 * @param array<string, string> $tables
	 *
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function processTableMetrics(
		array $tables,
		Client $client,
		MetricStore $store,
		MetricsScrapeContext $context
	): void {
		foreach ($tables as $tableName => $type) {
			unset($type);

			$statusRows = $this->fetchTableStatusRows($client, $tableName);

			$statusVars = [];
			$diskMapped = 0;
			$diskMappedCached = 0;
			foreach ($statusRows as $statusRow) {
				/** @var array{Variable_name:string,Value:string} $statusRow */
				$var = $statusRow['Variable_name'];
				$val = $statusRow['Value'];
				$statusVars[$var] = $val;
				$this->addTableStatusRowMetric(
					$store,
					$tableName,
					$var,
					$val,
					$diskMapped,
					$diskMappedCached
				);
			}
			$context->tableStatuses[$tableName] = $statusVars;

			if ($diskMapped === 0 || $diskMappedCached === 0) {
				continue;
			}

			$this->calculateMappedRatioMetric(
				$store, $diskMapped, $diskMappedCached,
				$tableName
			);
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function fetchTableStatusRows(
		Client $client,
		string $tableName
	): array {
		$tableStatus = $client->sendRequest('SHOW TABLE ' . $tableName . ' STATUS');
		if ($tableStatus->hasError()) {
			ManticoreSearchResponseError::throw((string)$tableStatus->getError());
		}

		$result = $tableStatus->getResult()->toArray();

		if (is_array($result[0]) && !empty($result[0]['data'])) {
			return $result[0]['data'];
		}
		throw GenericError::create(
			'Unexpected response format for SHOW TABLE ' . $tableName
			. ' STATUS (missing result[0])'
		);
	}

	/**
	 * @throws GenericError
	 */
	private function addTableStatusRowMetric(
		MetricStore $store,
		string $tableName,
		string $var,
		string $val,
		int &$diskMapped,
		int &$diskMappedCached
	): void {
		if ($var === 'mem_limit_rate') {
			$val = (string)(float)$val;
		}

		if (str_contains($var, '_stats_ms_')) {
			$parts = preg_split('/\\s+/', trim($val));
			if (is_array($parts) && sizeof($parts) >= 3) {
				$store->addMapped($var . '_1m', $parts[0], ['table' => $tableName]);
				$store->addMapped($var . '_5m', $parts[1], ['table' => $tableName]);
				$store->addMapped($var . '_15m', $parts[2], ['table' => $tableName]);
			}
			return;
		}

		if ($var === 'disk_mapped') {
			$diskMapped = (int)$val;
		} elseif ($var === 'disk_mapped_cached') {
			$diskMappedCached = (int)$val;
		}

		$store->addMapped($var, $val, ['table' => $tableName]);
	}

	private function calculateMappedRatioMetric(
		MetricStore $store,
		int $diskMapped,
		int $diskMappedCached,
		string $tableName
	): void {
		$ratio = 0.0;

		if ($diskMappedCached > $diskMapped) {
			$ratio = 100;
		} elseif ($diskMappedCached !== 0 && $diskMapped !== 0) {
			$ratio = ($diskMappedCached / $diskMapped) * 100;
		}

		$store->addMapped(
			'disk_mapped_cached_ratio_percent', $ratio,
			['table' => $tableName]
		);
	}
}
