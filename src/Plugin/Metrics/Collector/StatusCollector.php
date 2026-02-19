<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\BuildInfoParser;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class StatusCollector implements CollectorInterface {

	private const CLUSTER_NUMERIC_METRIC = 'cluster_numeric';
	private const CLUSTER_STRING_METRIC = 'cluster_string';

	private const UPTIME_SECONDS_GAUGE = 'uptime_seconds_gauge';
	private const CONNECTIONS_TOTAL = 'connections_total';
	private const AGENT_TFO_TOTAL = 'agent_tfo_total';

	private const OFF_VALUES = ['OFF', '-'];

	/**
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		$rows = $this->getStatusRows($client, $context);
		if ($rows === []) {
			return;
		}

		$this->processRows($store, $rows);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws GenericError
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function getStatusRows(Client $client, MetricsScrapeContext $context): array {
		if ($context->statusRows === []) {
			$request = $client->sendRequest('SHOW STATUS');
			if ($request->hasError()) {
				ManticoreSearchResponseError::throw((string)$request->getError());
			}

			$result = $request->getResult();
			if (!is_array($result[0])) {
				return [];
			}

			$data = $result[0]['data'] ?? null;
			if (!is_array($data)) {
				return [];
			}

			$context->statusRows = $data;
		}

		$this->ensureStatusMap($context);

		return $context->statusRows;
	}

	private function ensureStatusMap(MetricsScrapeContext $context): void {
		if ($context->status !== [] || $context->statusRows === []) {
			return;
		}

		$map = [];
		foreach ($context->statusRows as $row) {
			$counter = $row['Counter'] ?? null;
			$value = $row['Value'] ?? null;
			if (!is_string($counter) || $counter === '') {
				continue;
			}
			if (!is_int($value) && !is_float($value) && !is_string($value)) {
				continue;
			}

			$map[$counter] = $value;
		}

		$context->status = $map;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function processRows(MetricStore $store, array $rows): void {
		$clusterName = $this->getClusterName($rows);

		foreach ($rows as $row) {
			$this->processRow($store, $row, $clusterName);
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param null|string $clusterName
	 */
	private function processRow(MetricStore $store, array $row, ?string $clusterName): void {
		$counter = $row['Counter'] ?? null;
		if (!is_string($counter) || $counter === '') {
			return;
		}

		$value = $row['Value'] ?? null;

		if (str_starts_with($counter, 'cluster_')) {
			$this->addClusterMetric($store, $counter, $value, $clusterName);
			return;
		}

		if ($counter === 'version') {
			$this->addBuildInfoMetric($store, $value);
		}

		if (str_starts_with($counter, 'load') || str_contains($counter, '_stats_ms_')) {
			$this->addLoadMetrics($store, $counter, $value);
			return;
		}

		$this->addCompatAndEnabledMetrics($store, $counter, $value);

		if (!is_int($value) && !is_float($value) && !is_string($value)) {
			return;
		}

		$store->addMapped($counter, $value);
	}

	private function addCompatAndEnabledMetrics(MetricStore $store, string $counter, mixed $value): void {
		if ($this->addUptimeMetric($store, $counter, $value)) {
			return;
		}
		if ($this->addConnectionsMetric($store, $counter, $value)) {
			return;
		}
		if ($this->addAgentTfoMetric($store, $counter, $value)) {
			return;
		}
		if ($this->addQueryCpuEnabledMetric($store, $counter, $value)) {
			return;
		}
		if ($this->addQueryReadsMetrics($store, $counter, $value)) {
			return;
		}
		if ($this->addQueryReadKbMetrics($store, $counter, $value)) {
			return;
		}
		$this->addQueryReadTimeMetrics($store, $counter, $value);
	}

	private function addUptimeMetric(MetricStore $store, string $counter, mixed $value): bool {
		if ($counter !== 'uptime') {
			return false;
		}

		if (!is_int($value) && !is_float($value) && !is_string($value)) {
			$value = 0;
		}

		$store->addDirect(
			self::UPTIME_SECONDS_GAUGE,
			'gauge',
			'Time in seconds since start',
			$this->toNumberOrZero($value)
		);

		return true;
	}

	private function addConnectionsMetric(MetricStore $store, string $counter, mixed $value): bool {
		if ($counter !== 'connections') {
			return false;
		}

		if (!is_int($value) && !is_float($value) && !is_string($value)) {
			$value = 0;
		}

		$store->addDirect(
			self::CONNECTIONS_TOTAL,
			'counter',
			'Connections count since start',
			$this->toNumberOrZero($value)
		);

		return true;
	}

	private function addAgentTfoMetric(MetricStore $store, string $counter, mixed $value): bool {
		if ($counter !== 'agent_tfo') {
			return false;
		}

		if (!is_int($value) && !is_float($value) && !is_string($value)) {
			$value = 0;
		}

		$store->addDirect(
			self::AGENT_TFO_TOTAL,
			'counter',
			'Number of successfully sent TFO packets',
			$this->toNumberOrZero($value)
		);

		return true;
	}

	private function addQueryCpuEnabledMetric(MetricStore $store, string $counter, mixed $value): bool {
		if ($counter !== 'query_cpu') {
			return false;
		}

		$enabled = is_string($value) && $this->isOff($value) ? 0 : 1;
		$store->addDirect(
			'query_cpu_enabled',
			'gauge',
			'1 when query CPU stats are enabled, 0 when disabled',
			$enabled
		);

		return true;
	}

	private function addQueryReadsMetrics(MetricStore $store, string $counter, mixed $value): bool {
		if ($counter !== 'query_reads') {
			return false;
		}

		$enabled = is_string($value) && $this->isOff($value) ? 0 : 1;
		$scalarValue = $value;
		if (!is_int($scalarValue) && !is_float($scalarValue) && !is_string($scalarValue)) {
			$scalarValue = 0;
		}

		$store->addDirect(
			'query_reads_enabled',
			'gauge',
			'1 when query read IO calls stats are enabled, 0 when disabled',
			$enabled
		);
		$store->addDirect(
			'query_reads_count_total',
			'counter',
			'Total read IO calls (fired by search queries)',
			$enabled === 1 ? $this->toNumberOrZero($scalarValue) : 0
		);

		return true;
	}

	private function addQueryReadKbMetrics(MetricStore $store, string $counter, mixed $value): bool {
		if ($counter !== 'query_readkb') {
			return false;
		}

		$enabled = is_string($value) && $this->isOff($value) ? 0 : 1;
		$scalarValue = $value;
		if (!is_int($scalarValue) && !is_float($scalarValue) && !is_string($scalarValue)) {
			$scalarValue = 0;
		}

		$store->addDirect(
			'query_readkb_enabled',
			'gauge',
			'1 when query read IO traffic stats are enabled, 0 when disabled',
			$enabled
		);

		$kb = $enabled === 1 ? $this->toNumberOrZero($scalarValue) : 0;
		$store->addDirect(
			'query_readkb_bytes_total',
			'counter',
			'Total read IO traffic in bytes',
			$kb * 1024
		);

		return true;
	}

	private function addQueryReadTimeMetrics(MetricStore $store, string $counter, mixed $value): bool {
		if ($counter !== 'query_readtime') {
			return false;
		}

		$enabled = is_string($value) && $this->isOff($value) ? 0 : 1;
		$scalarValue = $value;
		if (!is_int($scalarValue) && !is_float($scalarValue) && !is_string($scalarValue)) {
			$scalarValue = 0;
		}

		$store->addDirect(
			'query_readtime_enabled',
			'gauge',
			'1 when query read IO time stats are enabled, 0 when disabled',
			$enabled
		);
		$store->addDirect(
			'query_readtime_seconds_total',
			'counter',
			'Total read IO time in seconds',
			$enabled === 1 ? $this->toNumberOrZero($scalarValue) : 0
		);

		return true;
	}

	private function isOff(string $value): bool {
		return in_array($value, self::OFF_VALUES, true);
	}

	private function toNumberOrZero(int|float|string $value): int|float {
		if (is_int($value) || is_float($value)) {
			return $value;
		}

		$value = trim($value);
		if ($value === '' || in_array($value, self::OFF_VALUES, true)) {
			return 0;
		}

		if (preg_match('/^-?\\d+$/', $value) === 1) {
			return (int)$value;
		}

		if (is_numeric($value)) {
			return (float)$value;
		}

		return 0;
	}

	private function addBuildInfoMetric(MetricStore $store, mixed $value): void {
		if (!is_string($value) || trim($value) === '') {
			return;
		}

		$labels = BuildInfoParser::parseLabels($value);
		$store->addMapped('build_info', 1, $labels);
	}

	private function addLoadMetrics(MetricStore $store, string $counter, mixed $value): void {
		if (!is_string($value)) {
			return;
		}

		$parts = preg_split('/\\s+/', trim($value));
		if (!is_array($parts) || sizeof($parts) < 3) {
			return;
		}

		$store->addMapped($counter . '_1m', $parts[0]);
		$store->addMapped($counter . '_5m', $parts[1]);
		$store->addMapped($counter . '_15m', $parts[2]);
	}

	private function addClusterMetric(MetricStore $store, string $counter, mixed $value, ?string $clusterName): void {
		$cluster = null;
		$key = null;

		if ($clusterName !== null) {
			$prefix = 'cluster_' . $clusterName . '_';
			if (str_starts_with($counter, $prefix)) {
				$cluster = $clusterName;
				$key = substr($counter, strlen($prefix));
			}
		}

		if ($cluster === null || $key === null) {
			if (!preg_match('/^cluster_([^_]+)_(.+)$/', $counter, $matches)) {
				return;
			}

			$cluster = $matches[1];
			$key = $matches[2];
		}

		if (is_int($value) || is_float($value)) {
			$value = (string)$value;
		}
		if (!is_string($value)) {
			return;
		}

		$value = trim($value);
		if ($value === '') {
			return;
		}

		if (preg_match('/^-?\\d+$/', $value) === 1) {
			$store->addDirect(
				self::CLUSTER_NUMERIC_METRIC,
				'gauge',
				'Cluster numeric status values',
				(int)$value,
				['cluster' => $cluster, 'key' => $key]
			);
			return;
		}

		if (is_numeric($value)) {
			$store->addDirect(
				self::CLUSTER_NUMERIC_METRIC,
				'gauge',
				'Cluster numeric status values',
				(float)$value,
				['cluster' => $cluster, 'key' => $key]
			);
			return;
		}

		$store->addDirect(
			self::CLUSTER_STRING_METRIC,
			'gauge',
			'Cluster string status values',
			1,
			['cluster' => $cluster, 'key' => $key, 'value' => $value]
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return null|string
	 */
	private function getClusterName(array $rows): ?string {
		foreach ($rows as $row) {
			$counter = $row['Counter'] ?? null;
			if (!is_string($counter) || $counter === '') {
				continue;
			}

			if ($counter !== 'cluster_name') {
				continue;
			}

			$value = $row['Value'] ?? null;
			if (is_string($value)) {
				$value = trim($value);
				if ($value !== '') {
					return $value;
				}
			}
			return null;
		}

		return null;
	}
}
