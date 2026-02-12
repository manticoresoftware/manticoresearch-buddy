<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class ConnectivityCollector implements CollectorInterface {

	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		$t = microtime(true);
		try {
			$request = $client->sendRequest('SHOW THREADS');
			$elapsed = microtime(true) - $t;

			if ($request->hasError()) {
				$this->addFailureMetrics($store);
				return;
			}

			$result = $request->getResult();
			if (is_array($result[0])) {
				$rows = $result[0]['data'] ?? null;
				if (is_array($rows)) {
					$context->threadsRows = $rows;
				}
			}

			$this->addSuccessMetrics($store, $elapsed);
		} catch (\Throwable) {
			$this->addFailureMetrics($store);
		}
	}

	private function addSuccessMetrics(MetricStore $store, float $elapsedSeconds): void {
		$store->addDirect('connect_ok', 'gauge', 'Manticore connectivity check result (1 = OK, 0 = failed)', 1);
		$store->addDirect('connect_time', 'gauge', 'Manticore connectivity check time in seconds', $elapsedSeconds);
		$store->addDirect(
			'connect_time_seconds',
			'gauge',
			'Manticore connectivity check time in seconds',
			$elapsedSeconds
		);
	}

	private function addFailureMetrics(MetricStore $store): void {
		$store->addDirect('connect_ok', 'gauge', 'Manticore connectivity check result (1 = OK, 0 = failed)', 0);
		$store->addDirect('connect_time', 'gauge', 'Manticore connectivity check time in seconds', 0);
		$store->addDirect('connect_time_seconds', 'gauge', 'Manticore connectivity check time in seconds', 0);
	}
}
