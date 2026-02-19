<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Throwable;

final class ProcessCollector implements CollectorInterface {

	private const PROC_GROUP_BUDDY = 'buddy';
	private const PROC_GROUP_SEARCHD = 'searchd';

	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		unset($client, $context);

		$stats = $this->getEmptyProcStats();

		foreach ($this->scanProcPids() as $pid) {
			$comm = $this->readProcComm($pid);
			$cmdline = $this->readProcCmdline($pid);

			$group = $this->resolveProcGroup($comm, $cmdline);
			if ($group === null) {
				continue;
			}

			$this->accumulateProcStats($stats[$group], $pid);
		}

		$store->addDirect(
			'searchd_processes_count',
			'gauge',
			'Number of searchd processes (from /proc)',
			$stats[self::PROC_GROUP_SEARCHD]['processes']
		);
		$store->addDirect(
			'searchd_anon_rss_bytes',
			'gauge',
			'searchd anonymous RSS in bytes (from /proc)',
			$stats[self::PROC_GROUP_SEARCHD]['anon_rss_bytes']
		);
		$store->addDirect(
			'searchd_rss_bytes',
			'gauge',
			'searchd RSS in bytes (from /proc)',
			$stats[self::PROC_GROUP_SEARCHD]['rss_bytes']
		);
		$store->addDirect(
			'searchd_virt_bytes',
			'gauge',
			'searchd virtual memory size in bytes (from /proc)',
			$stats[self::PROC_GROUP_SEARCHD]['virt_bytes']
		);
		$store->addDirect(
			'searchd_fd_count',
			'gauge',
			'searchd file descriptors count (from /proc)',
			$stats[self::PROC_GROUP_SEARCHD]['fd_count']
		);

		$store->addDirect(
			'buddy_processes_count',
			'gauge',
			'Number of buddy processes (from /proc)',
			$stats[self::PROC_GROUP_BUDDY]['processes']
		);
		$store->addDirect(
			'buddy_anon_rss_bytes',
			'gauge',
			'buddy anonymous RSS in bytes (from /proc)',
			$stats[self::PROC_GROUP_BUDDY]['anon_rss_bytes']
		);
		$store->addDirect(
			'buddy_rss_bytes',
			'gauge',
			'buddy RSS in bytes (from /proc)',
			$stats[self::PROC_GROUP_BUDDY]['rss_bytes']
		);
		$store->addDirect(
			'buddy_virt_bytes',
			'gauge',
			'buddy virtual memory size in bytes (from /proc)',
			$stats[self::PROC_GROUP_BUDDY]['virt_bytes']
		);
		$store->addDirect(
			'buddy_fd_count',
			'gauge',
			'buddy file descriptors count (from /proc)',
			$stats[self::PROC_GROUP_BUDDY]['fd_count']
		);
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

		$fdCount = $this->countProcFd($pid);
		if (!isset($fdCount)) {
			return;
		}

		$stats['fd_count'] += $fdCount;
	}

	/**
	 * @return int[]
	 */
	private function scanProcPids(): array {
		$dir = '/proc';
		if (!is_dir($dir)) {
			return [];
		}

		$items = scandir($dir);
		if (!is_array($items)) {
			return [];
		}

		$pids = [];
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			if (ctype_digit($item) !== true) {
				continue;
			}

			$pids[] = (int)$item;
		}

		return $pids;
	}

	private function readProcComm(int $pid): string {
		$path = "/proc/$pid/comm";
		if (!is_file($path)) {
			return '';
		}

		$content = file_get_contents($path);
		if (!is_string($content) || $content === '') {
			return '';
		}

		return trim($content);
	}

	private function readProcCmdline(int $pid): string {
		$path = "/proc/$pid/cmdline";
		if (!is_file($path)) {
			return '';
		}

		try {
			$content = file_get_contents($path);
			if (!is_string($content) || $content === '') {
				return '';
			}
			return str_replace("\0", ' ', trim($content));
		} catch (Throwable $e) {
			Buddy::debug('Collector fallback (' . self::class . '): ' . $e->getMessage());
			return '';
		}
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
	 * @return array<string, int>|null
	 */
	private function readProcStatus(int $pid): ?array {
		$path = "/proc/$pid/status";
		if (!is_file($path)) {
			return null;
		}

		$content = file_get_contents($path);
		if (!is_string($content) || $content === '') {
			return null;
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

	private function countProcFd(int $pid): ?int {
		$dir = "/proc/$pid/fd";
		if (!is_dir($dir)) {
			return null;
		}

		$items = scandir($dir);
		if (!is_array($items)) {
			return null;
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
}
