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
		$tables = $context->tablesRows;
		if ($tables === []) {
			$request = $client->sendRequest('SHOW TABLES');
			if ($request->hasError()) {
				ManticoreSearchResponseError::throw((string)$request->getError());
			}

			$result = $request->getResult();
			if (!is_array($result[0])) {
				return;
			}

			$rows = $result[0]['data'] ?? null;
			if (!is_array($rows)) {
				return;
			}

			$tables = $rows;
			$context->tablesRows = $tables;
		}

		if ($context->tableNames === []) {
			$tableNames = [];
			foreach ($tables as $row) {
				$tableName = $row['Index'] ?? $row['Table'] ?? null;
				if (!is_string($tableName) || $tableName === '') {
					continue;
				}

				$tableNames[] = $tableName;
			}

			$context->tableNames = $tableNames;
		}

		$store->addDirect(
			'tables_count',
			'gauge',
			'Number of tables returned by SHOW TABLES',
			sizeof($context->tableNames)
		);

		$this->processTableMetrics($tables, $client, $store);
	}

	/**
	 * @param array<int, array<string, string>> $tables
	 *
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function processTableMetrics(array $tables, Client $client, MetricStore $store): void {
		foreach ($tables as $row) {
			$tableName = $row['Index'] ?? $row['Table'] ?? '';
			if (!is_string($tableName) || $tableName === '') {
				continue;
			}

			$statusRows = $this->fetchTableStatusRows($client, $tableName);
			if ($statusRows === null) {
				continue;
			}

			$diskMapped = 0;
			$diskMappedCached = 0;
			foreach ($statusRows as $statusRow) {
				$this->addTableStatusRowMetric(
					$store,
					$tableName,
					$statusRow,
					$diskMapped,
					$diskMappedCached
				);
			}

			if ($diskMapped === 0 || $diskMappedCached === 0) {
				continue;
			}

			$this->calculateMappedRatioMetric($store, $diskMapped, $diskMappedCached, $tableName);
		}
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 *
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function fetchTableStatusRows(Client $client, string $tableName): ?array {
		$tableStatus = $client->sendRequest('SHOW TABLE ' . $tableName . ' STATUS');
		if ($tableStatus->hasError()) {
			ManticoreSearchResponseError::throw((string)$tableStatus->getError());
		}

		$result = $tableStatus->getResult();
		if (!is_array($result[0])) {
			return null;
		}

		$rows = $result[0]['data'] ?? null;
		if (!is_array($rows)) {
			return null;
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function addTableStatusRowMetric(
		MetricStore $store,
		string $tableName,
		array $row,
		int &$diskMapped,
		int &$diskMappedCached
	): void {
		$var = $row['Variable_name'] ?? null;
		$val = $row['Value'] ?? null;
		if (!is_string($var) || $var === '' || !is_string($val)) {
			return;
		}

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

		$store->addMapped('disk_mapped_cached_ratio_percent', $ratio, ['table' => $tableName]);
	}
}
