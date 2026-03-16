<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Metrics\Collector;

use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricStore;
use Manticoresearch\Buddy\Base\Plugin\Metrics\MetricsScrapeContext;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;

final class SchemaCollector implements CollectorInterface {

	private const SCHEMA_HASH_METRIC = 'schema_hash';
	private const NON_SERVED_TABLE_METRIC = 'non_served_table';

	public function collect(Client $client, MetricStore $store, MetricsScrapeContext $context): void {
		unset($client);

		$dataDir = trim($context->settings['searchd.data_dir']);
		if ($dataDir === '') {
			return;
		}

		$jsonPath = $dataDir . '/manticore.json';
		if (!is_file($jsonPath)) {
			return;
		}

		$content = file_get_contents($jsonPath);
		if (!is_string($content) || $content === '') {
			return;
		}

		$data = simdjson_decode($content, true);
		if (!is_array($data)) {
			return;
		}

		$indexes = $data['indexes'] ?? null;
		if (!is_array($indexes)) {
			return;
		}

		$indexNames = array_keys($indexes);
		sort($indexNames);

		$served = array_fill_keys(array_keys($context->tables), true);
		$nonServed = [];
		foreach ($indexNames as $indexName) {
			if (isset($served[$indexName])) {
				continue;
			}

			$nonServed[] = $indexName;
		}

		$store->addDirect(
			'non_served_tables_count',
			'gauge',
			'Count of non-served tables (present in manticore.json but missing in SHOW TABLES)',
			sizeof($nonServed)
		);
		foreach ($nonServed as $indexName) {
			$store->addDirect(
				self::NON_SERVED_TABLE_METRIC,
				'gauge',
				'Non-served tables (present in manticore.json but missing in SHOW TABLES)',
				1,
				['table' => $indexName]
			);
		}

		$hash = hash('sha256', implode("\0", $indexNames));
		$store->addDirect(
			self::SCHEMA_HASH_METRIC,
			'gauge',
			'Schema hash (sha256) derived from manticore.json index names',
			1,
			['hash' => $hash]
		);
	}
}
