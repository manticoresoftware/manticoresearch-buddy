<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class FilesystemCollector implements CollectorInterface {

	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		unset($client);

		$dataDir = trim($context->settings['searchd.data_dir'] ?? '');
		if ($dataDir === '') {
			$dataDir = '/var/lib/manticore';
		}

		$binlogDir = trim($context->settings['searchd.binlog_path'] ?? '');
		if ($binlogDir !== '') {
			$binlog = $this->dirFilesCountAndSize($binlogDir);
			if (isset($binlog)) {
				$store->addDirect(
					'binlog_files_count',
					'gauge',
					'Number of binlog files (from data_dir/binlog)',
					$binlog['count']
				);
				$store->addDirect(
					'binlog_files_bytes',
					'gauge',
					'Total size of binlog files in bytes (from data_dir/binlog)',
					$binlog['bytes']
				);
			}
		}

		$totalFilesCount = 0;
		$totalFilesBytes = 0;
		foreach ($context->tableNames as $tableName) {
			$tableDir = $dataDir . '/' . $tableName;
			$stats = $this->dirFilesCountAndSize($tableDir);
			if (!isset($stats)) {
				continue;
			}

			$totalFilesCount += $stats['count'];
			$totalFilesBytes += $stats['bytes'];

			$store->addDirect(
				'table_files_count',
				'gauge',
				'Number of files in table data directory',
				$stats['count'],
				['table' => $tableName]
			);
			$store->addDirect(
				'table_files_bytes',
				'gauge',
				'Total size of files in table data directory in bytes',
				$stats['bytes'],
				['table' => $tableName]
			);
		}

		$store->addDirect(
			'index_files_count',
			'gauge',
			'Number of files in all table data directories',
			$totalFilesCount
		);
		$store->addDirect(
			'index_files_bytes',
			'gauge',
			'Total size of files in all table data directories in bytes',
			$totalFilesBytes
		);
	}

	/**
	 * @return array{count:int,bytes:int}|null
	 */
	private function dirFilesCountAndSize(string $dir): ?array {
		if (!is_dir($dir)) {
			return null;
		}

		$items = scandir($dir);
		if (!is_array($items)) {
			return null;
		}

		$count = 0;
		$bytes = 0;
		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$path = $dir . '/' . $item;
			if (!is_file($path)) {
				continue;
			}

			$count++;
			$size = filesize($path);
			if (!is_int($size) || $size <= 0) {
				continue;
			}

			$bytes += $size;
		}

		return ['count' => $count, 'bytes' => $bytes];
	}
}
