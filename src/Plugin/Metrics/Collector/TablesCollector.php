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
		if ($context->tableNames === []) {
			$request = $client->sendRequest('SHOW TABLES');
			if ($request->hasError()) {
				ManticoreSearchResponseError::throw((string)$request->getError());
			}

			$rows = [];
			$result = $request->getResult()->toArray();
			if (is_array($result[0]) && !empty($result[0]['data'])) {
				$rows = $this->extractTableNamesFromRows($result[0]['data']);
			}
			$context->tableNames = $rows;
		}

		$store->addDirect(
			'tables_count',
			'gauge',
			'Number of tables returned by SHOW TABLES',
			sizeof($context->tableNames)
		);

		$this->processTableMetrics($context->tableNames, $client, $store);
	}

	/**
	 * @param array<int, array{Table?:string,Index?:string}> $rows
	 *
	 * @return string[]
	 */
	private function extractTableNamesFromRows(array $rows): array {
		$tableNames = [];
		foreach ($rows as $row) {
			$tableName = trim($row['Index'] ?? $row['Table'] ?? '');
			if ($tableName === '') {
				continue;
			}

			$tableNames[] = $tableName;
		}

		return $tableNames;
	}

	/**
	 * @param string[] $tableNames
	 *
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function processTableMetrics(
		array $tableNames,
		Client $client,
		MetricStore $store
	): void {
		foreach ($tableNames as $tableName) {
			$statusRows = $this->fetchTableStatusRows($client, $tableName);

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
	 * @param array<string, mixed> $row
	 *
	 * @throws GenericError
	 */
	private function addTableStatusRowMetric(
		MetricStore $store,
		string $tableName,
		array $row,
		int &$diskMapped,
		int &$diskMappedCached
	): void {
		if (!array_key_exists('Variable_name', $row)
			|| !array_key_exists('Value', $row)
		) {
			throw GenericError::create(
				'Unexpected SHOW TABLE STATUS row format (missing Variable_name/Value)'
			);
		}

		$var = $row['Variable_name'];
		$val = $row['Value'];

		if (!is_string($var) || $var === '' || !is_string($val)) {
			throw GenericError::create(
				'Unexpected SHOW TABLE STATUS row format (invalid Variable_name/Value types)'
			);
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

		$store->addMapped(
			'disk_mapped_cached_ratio_percent', $ratio,
			['table' => $tableName]
		);
	}
}
