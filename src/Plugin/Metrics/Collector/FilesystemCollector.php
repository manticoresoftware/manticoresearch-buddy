<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class FilesystemCollector implements CollectorInterface {

	/**
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		$binlogDir = trim($context->settings['searchd.binlog_path']);
		if ($binlogDir !== '') {
			$binlog = $this->dirFilesCountAndSize($binlogDir);
			if (isset($binlog)) {
				$store->addDirect(
					'binlog_files_count',
					'gauge',
					'Number of binlog files (from searchd.binlog_path)',
					$binlog['count']
				);
				$store->addDirect(
					'binlog_files_bytes',
					'gauge',
					'Total size of binlog files in bytes (from searchd.binlog_path)',
					$binlog['bytes']
				);
			}
		}

		$totalFilesCount = 0;
		$totalFilesBytes = 0;
		foreach ($context->tables as $tableName => $type) {
			$stats = $this->collectTableSize($client, $tableName, $type);
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
	private function collectTableSize(
		Client $client,
		string $tableName,
		string $type
	): ?array {
		if ($type === 'distributed') {
			return null;
		}

		$files = $this->fetchTableFilesRows($client, $tableName);
		$count = 0;
		$bytes = 0;
		foreach ($files as $row) {
			$file = $row['file'] ?? null;
			if (!is_string($file) || $file === '') {
				continue;
			}

			$count++;

			$size = (int)($row['size'] ?? 0);
			if ($size <= 0) {
				continue;
			}

			$bytes += $size;
		}

		return ['count' => $count, 'bytes' => $bytes];
	}

	/**
	 * @return array<int, array{file?:string,normalized?:string,size?:int|string}>
	 *
	 * @throws ManticoreSearchClientError
	 * @throws ManticoreSearchResponseError
	 */
	private function fetchTableFilesRows(Client $client, string $tableName): array {
		$request = $client->sendRequest('SELECT file, size FROM ' . $tableName . '.@files');
		if ($request->hasError()) {
			ManticoreSearchResponseError::throw((string)$request->getError());
		}

		/** @var array<int, array{data: array<int, array<string, int|string>>}> $result */
		$result = $request->getResult()->toArray();

		if (!is_array($result[0] ?? null) || empty($result[0]['data'])) {
			ManticoreSearchResponseError::throw(
				'Unexpected response format for SELECT ... FROM ' . $tableName . '.@files (missing result[0])'
			);
		}

		/** @var array<int, array{file?:string,normalized?:string,size?:int|string}> $data */
		$data = $result[0]['data'];
		return $data;
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
