<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Lib;

use Exception;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresoftware\Telemetry\Metric as TelemetryMetric;
use OpenMetrics\Exposition\Text\Exceptions\InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class Metric {
	const MAX_QUEUE_SIZE = 200;
	const RATE_MULTIPLIER = 1000;

	/** @var ContainerInterface */
	// We set this on initialization (init.php) so we are sure we have it in class
	protected static ContainerInterface $container;

	/** @var static $instance */
	public static self $instance;

	/** @var TelemetryMetric $telemetry */
	protected TelemetryMetric $telemetry;

	/** @var int $queueCount */
	protected int $queueCount = 0;

	/** @var int $snapshotTime timestamp of latest snapshot */
	protected int $snapshotTime;

	/** @var array<string,int> $snapshot last snashpot we made to calcaulte diffs */
	protected array $snapshot = [];

	/**
	 * Setter for container property
	 *
	 * @param ContainerInterface $container
	 *  The container object to resolve the executor's dependencies in case such exist
	 * @return void
	 *  The CommandExecutorInterface to execute to process the final query
	 */
	public static function setContainer(ContainerInterface $container): void {
		self::$container = $container;
	}

	/**
	 * @param array<string,string> $labels
	 * @param bool $enabled
	 * @return void
	 */
	public function __construct(array $labels, public bool $enabled) {
		$this->telemetry = new TelemetryMetric($labels);
		$this->snapshotTime = time();
	}

	/**
	 * Intialize the singleton of the Metric instance and use it
	 *
	 * @return static
	 */
	public static function instance(): static {
		if (isset(static::$instance)) {
			return static::$instance;
		}

		$enabled = is_telemetry_enabled();
		Buddy::debugv(sprintf('telemetry: %s', $enabled ? 'yes' : 'no'));

		// 1. Get versions
		$labels = static::getVersions();

		// 2. Get config labels
		$labels = array_merge($labels, static::getConfigLabels());

		// 3. Add collector parameter, so it makes easier
		// to understand where metric came from in case we will collect it
		// in different services, we collect already it in backup in addition
		$labels['collector'] = 'buddy';

		Buddy::debugv(sprintf('labels: %s', json_encode($labels)));
		static::$instance = new static($labels, $enabled);

		return static::$instance;
	}

	/**
	 * Get current state of the metric if it's enabled or not
	 *
	 * @return bool
	 */
	public function isEnabled(): bool {
		return $this->enabled;
	}

	/**
	 * We add metric in case it's enabled otherwise
	 * this method does nothing at all
	 *
	 * @see TelemetryMetric::add
	 */
	public function add(string $name, int|float $value): static {
		if ($this->isEnabled()) {
			$this->telemetry->add($name, $value);
			++$this->queueCount;
		}
		return $this;
	}

	/**
	 * @see TelemetryMetric::send
	 */
	public function send(): bool {
		$this->queueCount = 0;
		return $this->telemetry->send();
	}

	/**
	 * Get versions to add as labels to telemetry
	 *
	 * @return array<string,string>
	 */
	public static function getVersions(): array {
		$buddyVersion = trim(
			(string)file_get_contents(
				__DIR__ . DIRECTORY_SEPARATOR . '..'
				. DIRECTORY_SEPARATOR. '..'
				. DIRECTORY_SEPARATOR . 'APP_VERSION'
			)
		);
		$verPattern = '(\d+\.\d+\.\d+[^\(\)]*)';
		$matchExpr = "/^{$verPattern}(\(columnar\s{$verPattern}\))?"
			. "([^\(]*\(secondary\s{$verPattern}\))?"
			. "([^\(]*\(knn\s{$verPattern}\))?"
			. "([^\(]*\(buddy\s{$verPattern}\))?$/ius"
		;
		/** @var string $version */
		$version = static::getStatusMap()['version'] ?? '';
		preg_match($matchExpr, $version, $m);

		return array_filter(
			[
				'buddy_version' => $buddyVersion,
				'manticore_version' => $m[1] ?? null,
				'columnar_version' => $m[3] ?? null,
				'secondary_version' => $m[5] ?? null,
				'knn_version' => $m[7] ?? null,
			]
		);
	}

	/**
	 *
	 * @param int $period
	 * @return void
	 * @throws NotFoundExceptionInterface
	 * @throws ContainerExceptionInterface
	 * @throws RuntimeException
	 * @throws ManticoreSearchClientError
	 * @throws InvalidArgumentException
	 * @throws Exception
	 */
	public function checkAndSnapshot(int $period): void {
		static $ts = 0;

		// Find if we should send it due to queue size or period
		$shouldSendByQueue = $this->queueCount >= static::MAX_QUEUE_SIZE;
		$shouldSendByPeriod = (time() - $ts) >= $period;

		// If this is not time yet, we just do early ereturn
		if (!$shouldSendByQueue && !$shouldSendByPeriod) {
			return;
		}

		$ts = time();
		$this->snapshot();
	}

	/**
	 * Run snashotting of metrics from the manticore daemon
	 *
	 * @return void
	 */
	public function snapshot(): void {
		// 1. Update labels with those that can change
		$this->telemetry->updateLabels(static::getVariableLabels());

		// 2. Get metrics from SHOW STATUS query
		$status = $this->getStatusMap();
		/** @var array<string,int> $metrics */
		$metrics = array_map(
			'intval', array_filter(
				$status, function ($key) {
					if ($key === 'cluster_count' || $key === 'cluster_size') {
						return true;
					}

					if (str_starts_with($key, 'command_')) {
						return true;
					}

					if ($key === 'uptime' || $key === 'workers_total' || $key === 'command_cluster') {
						return true;
					}

					return false;
				}, ARRAY_FILTER_USE_KEY
			)
		);

		// Display labels we will send
		Buddy::debugv(sprintf('labels: %s', json_encode($this->telemetry->getLabels())));

		// 3. Get snapshot of tables metrics
		$metrics = array_merge($metrics, static::getTablesMetrics());
		Buddy::debugv(sprintf('metrics: %s', json_encode($metrics)));

		$now = time();
		$duration = 0;
		$saveSnapshot = ($now - $this->snapshotTime) > 0;
		if ($saveSnapshot) {
			$duration = $now - $this->snapshotTime;
		}

		// 4. Finally send it
		$rateMetrics = [];
		foreach ($metrics as $name => $value) {
			$this->add($name, $value);

			// Caculate rate metrics when we need
			if (!str_starts_with($name, 'command_') || $duration <= 0) {
				continue;
			}

			$rateName = "{$name}_rate";
			$diff = $value - ($this->snapshot[$name] ?? 0);
			$rateValue = (int)(static::RATE_MULTIPLIER * ($diff / $duration));
			// Store it for debug
			$rateMetrics[$rateName] = $rateValue;
			// Add to metric
			$this->add($rateName, $rateValue);
		}
		Buddy::debugv(sprintf('rates: %s', json_encode($rateMetrics)));

		$this->send();

		// Save last info about snapshot
		if (!$saveSnapshot) {
			return;
		}

		$this->snapshot = $metrics;
		$this->snapshotTime = $now;
	}

	/**
	 * Get result of show status query as map key => value
	 *
	 * @return array<string,string|int>
	 */
	protected static function getStatusMap(): array {
		$map = [];
		/** @var array{0:array{Counter:string,Value:string}} $result */
		$result = static::sendManticoreRequest('SHOW STATUS');
		foreach ($result as ['Counter' => $key, 'Value' => $value]) {
			if ($key === 'cluster_name') {
				$map['cluster_count'] = ($map['cluster_count'] ?? 0) + 1;
				continue;
			}
			if (isset($map[$key]) && is_numeric($map[$key]) && is_numeric($value)) {
				$map[$key] += $value;
			} else {
				$map[$key] = $value;
			}
		}
		return $map;
	}

	/**
	 * Retreieve table metrics to send
	 *
	 * @return array<string,int>
	 */
	protected static function getTablesMetrics(): array {
		$metrics = [];
		/** @var HTTPClient */
		$client = static::$container->get('manticoreClient');
		$tables = $client->getAllTables();
		foreach ($tables as [$table, $tableType]) {
			$tableTypeKey = "table_{$tableType}_count";
			$metrics[$tableTypeKey] ??= 0;
			$metrics[$tableTypeKey] += 1;

			if ($tableType !== 'rt' && $tableType !== 'percolate') {
				continue;
			}

			$suffix = $tableType === 'percolate' ? ' TABLE' : '';
			/** @var array{array{Type:string,Properties:string}} */
			$descResult = static::sendManticoreRequest("DESC {$table}{$suffix}");
			foreach ($descResult as ['Type' => $fieldType, 'Properties' => $properties]) {
				$fieldTypeKey = "{$tableType}_field_{$fieldType}_count";
				$metrics[$fieldTypeKey] ??= 0;
				$metrics[$fieldTypeKey] += 1;

				if (!str_contains($properties, 'columnar')) {
					continue;
				}

				$metrics['columnar_field_count'] ??= 0;
				$metrics['columnar_field_count'] += 1;
			}
		}
		return $metrics;
	}

	/**
	 * Get configuration labels
	 *
	 * @return array<string,string>
	 */
	protected static function getConfigLabels(): array {
		$labels = [];

		// 1. Get settings first
		$dataDir = '';
		$configResult = static::sendManticoreRequest('SHOW SETTINGS');
		foreach ($configResult as ['Setting_name' => $key, 'Value' => $value]) {
			if ($key === 'searchd.data_dir') {
				$dataDir = $value;
				$labels['manticore_mode'] = $value ? 'rt' : 'plain';
				continue;
			}

			// We have bug now and display data dir for binlog when binlog set to empty string
			if ($key === 'searchd.binlog_path') {
				$labels['manticore_binlog_enabled'] = static::boolToString($value && $value !== $dataDir);
				continue;
			}
		}

		return $labels;
	}

	/**
	 * Get labels that change on each request we batch
	 * @return array<string,string>s
	 */
	protected static function getVariableLabels(): array {
		$labels = [];
		$varResult = static::sendManticoreRequest('SHOW VARIABLES');
		$boolVars = ['auto_optimize', 'secondary_indexes', 'pseudo_sharding', 'accurate_aggregation'];
		$strVars = ['query_log_format', 'distinct_precision_threshold', 'max_allowed_packet'];
		foreach ($varResult as ['Variable_name' => $key, 'Value' => $value]) {
			/** @var string $key */
			if (in_array($key, $boolVars)) {
				$labels["manticore_{$key}_enabled"] = static::boolToString((bool)$value);
				continue;
			}

			if (!in_array($key, $strVars)) {
				continue;
			}

			/** @var string|int $value */
			$labels["manticore_{$key}"] = (string)$value;
		}
		return $labels;
	}

	/**
	 * @param string $query
	 * @return array<mixed>
	 */
	protected static function sendManticoreRequest(string $query): array {
		/** @var HTTPClient */
		$client = static::$container->get('manticoreClient');
		$response = $client->sendRequest($query);
		/** @var array{0:array{data?:array<mixed>}} $result */
		$result = $response->getResult();
		return $result[0]['data'] ?? [];
	}

	/**
	 * Convert bool to string representation for labeling
	 *
	 * @param bool $flag
	 * @return string
	 */
	protected static function boolToString(bool $flag): string {
		return $flag ? 'yes' : 'no';
	}
}
