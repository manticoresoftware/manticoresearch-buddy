<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class CrashCollector implements CollectorInterface {

	private const STATE_FILENAME = 'manticore-crashes-count.json';
	private const CRASH_MARKER = 'FATAL: CRASH DUMP';

	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		$settings = $client->getSettings();
		$logPath = $settings->searchdLog ?? '';

		if ($logPath === '' || !file_exists($logPath)) {
			$context->searchdCrashesTotal = 0;
			$this->emitCrashesMetric($store, 0);
			return;
		}

		$currentCount = $this->countCrashesInLog($logPath);
		$context->searchdCrashesTotal = $currentCount;
		$this->emitCrashesMetric($store, $currentCount);

		// No crashes in the log -> do not create or touch any state file.
		if ($currentCount === 0) {
			return;
		}

		$statePath = $this->resolveStatePath();
		$this->persistLastSeenCount($statePath, $currentCount);
	}

	private function emitCrashesMetric(MetricStore $store, int $crashesTotal): void {
		$store->addDirect(
			'searchd_crashes_total',
			'counter',
			'Count of detected searchd crashes (from searchd.log)',
			$crashesTotal
		);
	}

	private function countCrashesInLog(string $logPath): int {
		$fh = fopen($logPath, 'rb');
		if ($fh === false) {
			return 0;
		}

		$count = 0;
		try {
			while (!feof($fh)) {
				$line = fgets($fh);
				if (!is_string($line)) {
					break;
				}

				if (!str_contains($line, self::CRASH_MARKER)) {
					continue;
				}

				$count++;
			}
		} finally {
			fclose($fh);
		}

		return $count;
	}

	private function resolveStatePath(): string {
		$cwd = getcwd();
		if (!is_string($cwd)) {
			return sys_get_temp_dir() . DIRECTORY_SEPARATOR. self::STATE_FILENAME;
		}

		return rtrim($cwd, '/') . '/' . self::STATE_FILENAME;
	}

	private function persistLastSeenCount(string $statePath, int $currentCount): void {
		if (is_file($statePath)) {
			$prevJson = file_get_contents($statePath);
			if ($prevJson === false) {
				throw new \RuntimeException("Failed to read crash state file: {$statePath}");
			}

			$decoded = simdjson_decode($prevJson, true);
			if (!is_array($decoded) || !array_key_exists('count', $decoded) || !is_int($decoded['count'])) {
				throw new \RuntimeException("Invalid crash state file: {$statePath}");
			}

			if ($decoded['count'] === $currentCount) {
				return;
			}
		}

		$payload = json_encode(['count' => $currentCount], JSON_THROW_ON_ERROR);
		file_put_contents($statePath, $payload . "\n", LOCK_EX);
	}
}
