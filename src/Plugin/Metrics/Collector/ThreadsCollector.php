<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class ThreadsCollector implements CollectorInterface {

	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		$rows = $this->getThreadsRows($client, $context);
		if (!isset($rows)) {
			return;
		}

		$stats = $this->computeThreadStats($rows);
		$threadCount = $stats['thread_count'];
		$maxTime = $stats['slowest_thread_seconds'];

		$store->addMapped('thread_count', $threadCount);
		$store->addMapped('slowest_thread', $maxTime);
	}

	/**
	 * @return array<int, array<string, mixed>>|null
	 */
	private function getThreadsRows(Client $client, MetricsScrapeContext $context): ?array {
		if ($context->threadsRows !== []) {
			return $context->threadsRows;
		}

		try {
			$request = $client->sendRequest('SHOW THREADS');
			if ($request->hasError()) {
				return null;
			}

			$result = $request->getResult();
			if (!is_array($result[0])) {
				return null;
			}

			$rows = $result[0]['data'] ?? null;
			if (!is_array($rows)) {
				return null;
			}

			$context->threadsRows = $rows;
			return $rows;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array{thread_count:int,slowest_thread_seconds:float}
	 */
	private function computeThreadStats(array $rows): array {
		$threadCount = 0;
		$maxTime = 0.0;

		foreach ($rows as $row) {
			if ($this->isShowThreadsRow($row)) {
				continue;
			}

			$threadCount++;
			$sec = $this->parseThreadJobTimeSeconds($row['This/prev job time'] ?? null);
			$maxTime = max($maxTime, $sec);
		}

		return [
			'thread_count' => $threadCount,
			'slowest_thread_seconds' => $maxTime,
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function isShowThreadsRow(array $row): bool {
		$info = $row['Info'] ?? '';
		return is_string($info) && strtolower($info) === 'show threads';
	}

	private function parseThreadJobTimeSeconds(mixed $rowTime): float {
		if (!is_string($rowTime) || $rowTime === '') {
			return 0.0;
		}

		if (str_contains($rowTime, 'us')) {
			return (int)$rowTime / 1000000;
		}

		return (int)$rowTime / 1000;
	}
}
