<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class ProcessCollector implements CollectorInterface {

	private const PROC_GROUP_BUDDY = 'buddy';
	private const PROC_GROUP_SEARCHD = 'searchd';

	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		unset($client, $context);

		$stats = $this->getEmptyProcStats();

		foreach ($this->scanProcPids() as $pid) {
			$comm = $this->readFileIfExists("/proc/$pid/comm");
			$cmdline = $this->readProcCmdline($pid);

			$group = $this->resolveProcGroup($comm, $cmdline);
			if ($group === null) {
				continue;
			}

			$this->accumulateProcStats($stats[$group], $pid);
		}

		$groups = [
			self::PROC_GROUP_SEARCHD => 'searchd',
			self::PROC_GROUP_BUDDY => 'buddy',
		];
		$metricDefs = [
			[
				'name_suffix' => 'processes_count',
				'help' => 'Number of %s processes (from /proc)',
				'stats_key' => 'processes',
			],
			[
				'name_suffix' => 'anon_rss_bytes',
				'help' => '%s anonymous RSS in bytes (from /proc)',
				'stats_key' => 'anon_rss_bytes',
			],
			[
				'name_suffix' => 'rss_bytes',
				'help' => '%s RSS in bytes (from /proc)',
				'stats_key' => 'rss_bytes',
			],
			[
				'name_suffix' => 'virt_bytes',
				'help' => '%s virtual memory size in bytes (from /proc)',
				'stats_key' => 'virt_bytes',
			],
			[
				'name_suffix' => 'fd_count',
				'help' => '%s file descriptors count (from /proc)',
				'stats_key' => 'fd_count',
			],
		];

		foreach ($groups as $group => $label) {
			foreach ($metricDefs as $metricDef) {
				$store->addDirect(
					$label . '_' . $metricDef['name_suffix'],
					'gauge',
					sprintf($metricDef['help'], $label),
					$stats[$group][$metricDef['stats_key']]
				);
			}
		}
	}

	/**
	 * @return array<string, array{processes:int,anon_rss_bytes:int,rss_bytes:int,virt_bytes:int,fd_count:int}>
	 */
	private function getEmptyProcStats(): array {
		$empty = [
			'processes' => 0,
			'anon_rss_bytes' => 0,
			'rss_bytes' => 0,
			'virt_bytes' => 0,
			'fd_count' => 0,
		];

		return [
			self::PROC_GROUP_SEARCHD => $empty,
			self::PROC_GROUP_BUDDY => $empty,
		];
	}

	private function resolveProcGroup(string $comm, string $cmdline): ?string {
		if ($this->findProcGroupSearchd($comm, $cmdline)) {
			return self::PROC_GROUP_SEARCHD;
		}
		if ($this->findProcGroupBuddy($comm, $cmdline)) {
			return self::PROC_GROUP_BUDDY;
		}

		return null;
	}

	/**
	 * @param array{processes:int,anon_rss_bytes:int,rss_bytes:int,virt_bytes:int,fd_count:int} $stats
	 */
	private function accumulateProcStats(array &$stats, int $pid): void {
		$stats['processes']++;

		$status = $this->readProcStatus($pid);
		if (isset($status['RssAnon'])) {
			$stats['anon_rss_bytes'] += $this->kbToBytes($status['RssAnon']);
		}
		if (isset($status['VmRSS'])) {
			$stats['rss_bytes'] += $this->kbToBytes($status['VmRSS']);
		}
		if (isset($status['VmSize'])) {
			$stats['virt_bytes'] += $this->kbToBytes($status['VmSize']);
		}

		$stats['fd_count'] += $this->countDirEntries("/proc/$pid/fd");
	}

	/**
	 * @return int[]
	 */
	private function scanProcPids(): array {
		return $this->scanDirAndCollect(
			'/proc', static function (string $item): ?int {
				if (ctype_digit($item) !== true) {
					return null;
				}

				return (int)$item;
			}
		);
	}

	private function readProcCmdline(int $pid): string {
		$content = $this->readFileIfExists("/proc/$pid/cmdline");
		if ($content === '') {
			return '';
		}

		return str_replace("\0", ' ', $content);
	}

	private function findProcGroupSearchd(string $comm, string $cmdline): bool {
		if ($comm === 'searchd') {
			return true;
		}

		if ($cmdline === '') {
			return false;
		}

		$firstArg = strtok(trim($cmdline), ' ');
		if ($firstArg === false) {
			return false;
		}

		return $firstArg === 'searchd' || str_ends_with($firstArg, '/searchd');
	}

	private function findProcGroupBuddy(string $comm, string $cmdline): bool {
		if ($comm !== '' && str_starts_with($comm, 'manticore-execu')) {
			return true;
		}

		if ($cmdline === '') {
			return false;
		}

		if (str_contains($cmdline, 'manticore-executor') && str_contains($cmdline, 'src/main.php')) {
			return true;
		}

		if (str_contains($cmdline, 'manticore-buddy') && str_contains($cmdline, 'src/main.php')) {
			return true;
		}

		return str_contains($cmdline, 'manticore-executor') && str_contains($cmdline, 'manticore-buddy');
	}

	/**
	 * @return array<string, int>
	 */
	private function readProcStatus(int $pid): array {
		$content = $this->readFileIfExists("/proc/$pid/status");
		if ($content === '') {
			return [];
		}

		$result = [];
		foreach (explode("\n", $content) as $line) {
			$line = trim($line);
			if ($line === '' || str_contains($line, ':') !== true) {
				continue;
			}

			[$key, $val] = explode(':', $line, 2);
			$key = trim($key);
			$val = trim($val);

			$matchesCount = preg_match('/^(\\d+)/', $val, $m);
			if ($matchesCount !== 1) {
				continue;
			}

			$result[$key] = (int)$m[1];
		}

		return $result;
	}

	private function kbToBytes(int $kb): int {
		return $kb * 1024;
	}

	private function countDirEntries(string $dir): int {
		if (!is_dir($dir)) {
			return 0;
		}

		$items = scandir($dir);
		if (!is_array($items)) {
			return 0;
		}

		$count = 0;
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$count++;
		}

		return $count;
	}

	/**
	 * @template T
	 * @param callable(string):?T $collector
	 * @return array<T>
	 */
	private function scanDirAndCollect(string $dir, callable $collector): array {
		if (!is_dir($dir)) {
			return [];
		}

		$items = scandir($dir);
		if (!is_array($items)) {
			return [];
		}

		$result = [];
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$value = $collector($item);
			if ($value === null) {
				continue;
			}

			$result[] = $value;
		}

		return $result;
	}

	private function readFileIfExists(string $path): string {
		if (!is_file($path)) {
			return '';
		}

		$content = file_get_contents($path);
		if (!is_string($content)) {
			return '';
		}

		return trim($content);
	}
}
