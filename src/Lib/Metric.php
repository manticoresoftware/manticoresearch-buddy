<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Lib;

use Exception;
use Manticoresearch\Buddy\Exception\ManticoreHTTPClientError;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use Manticoresoftware\Telemetry\Metric as TelemetryMetric;
use OpenMetrics\Exposition\Text\Exceptions\InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class Metric {
	const MAX_QUEUE_SIZE = 200;

	/** @var ContainerInterface */
	// We set this on initialization (init.php) so we are sure we have it in class
	protected static ContainerInterface $container;

	/** @var static $instance */
	public static self $instance;

	/** @var TelemetryMetric $telemetry */
	protected TelemetryMetric $telemetry;

	/** @var int $queueCount */
	protected int $queueCount = 0;

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
		debug(sprintf('telemetry: %s', $enabled ? 'yes' : 'no'));

		// 1. Get versions
		$labels = static::getVersions();

		// 2. Get config labels
		$labels = array_merge($labels, static::getConfigLabels());

		// 3. Add collector parameter, so it makes easier
		// to understand where metric came from in case we will collect it
		// in different services, we collect already it in backup in addition
		$labels['collector'] = 'buddy';

		debug(sprintf('labels: %s', json_encode($labels)));
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
		$verPattern = '(\d+\.\d+\.\d+[^\(\)]+)';
		$matchExpr = "/^{$verPattern}(\(columnar\s{$verPattern}\))?"
			. "([^\(]*\(secondary\s{$verPattern}\))?$/ius"
		;
		$version = static::getStatusMap()['version'] ?? '';
		preg_match($matchExpr, $version, $m);

		return array_filter(
			[
				'buddy_version' => $buddyVersion,
				'manticore_version' => $m[1] ?? null,
				'columnar_version' => $m[3] ?? null,
				'secondary_version' => $m[5] ?? null,
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
	 * @throws ManticoreHTTPClientError
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
		// 1. Get metrics from SHOW STATUS query
		$status = $this->getStatusMap();
		/** @var array<string,int> $metrics */
		$metrics = array_map(
			'intval', array_filter(
				$status, function ($key) {
					if (str_starts_with($key, 'command_')) {
						return true;
					}

					if ($key === 'uptime' || $key === 'workers_total') {
						return true;
					}

					if (str_contains($key, 'cluster')) {
						return true;
					}

					return false;
				}, ARRAY_FILTER_USE_KEY
			)
		);

		// 2. Get snapshot of tables metrics
		$metrics = array_merge($metrics, static::getTablesMetrics());
		debug(sprintf('metrics: %s', json_encode($metrics)));

		// 3. Finally send it
		foreach ($metrics as $name => $value) {
			$this->add($name, $value);
		}
		$this->send();
	}

	/**
	 * Get result of show status query as map key => value
	 *
	 * @return array<string,string>
	 */
	protected static function getStatusMap(): array {
		/** @var array{0:array{data:array{Counter:string,Value:string}}} $result */
		$result = static::sendManticoreRequest('SHOW STATUS');
		return array_combine(
			array_column($result, 'Counter'),
			array_column($result, 'Value')
		);
	}

	/**
	 * Retreieve table metrics to send
	 *
	 * @return array<string,int>
	 */
	protected static function getTablesMetrics(): array {
		$metrics = [];
		/** @var array{array{Index:string,Type:String}} */
		$tablesResult = static::sendManticoreRequest('SHOW TABLES');
		// TODO: change Index -> Table
		foreach ($tablesResult as ['Index' => $table, 'Type' => $tableType]) {
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

		// 2. Get variables
		$varResult = static::sendManticoreRequest('SHOW VARIABLES');
		foreach ($varResult as ['Variable_name' => $key, 'Value' => $value]) {
			if ($key === 'secondary_indexes') {
				$labels['manticore_secondary_indexes_enabled'] = static::boolToString((bool)$value);
				break;
			}
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
