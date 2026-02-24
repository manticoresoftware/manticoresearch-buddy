<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Metrics;

use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\ConnectivityCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\CrashCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\FilesystemCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\ProcessCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\SchemaCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\StatusCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\TablesCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\ThreadsCollector;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchClientError;
use Manticoresearch\Buddy\Core\Error\ManticoreSearchResponseError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;
use Manticoresearch\Buddy\Core\Tool\Buddy;

final class Handler extends BaseHandlerWithClient
{
	public function __construct(public Payload $payload) {
	}

	/**
	 * @throws GenericError
	 */
	public function run(): Task {
		$taskFn = static function (Client $client): TaskResult {
			$definitionsPath = __DIR__ . '/metric_definitions.json';
			$json = file_get_contents($definitionsPath);
			if ($json === false) {
				throw GenericError::create("Metrics: failed to read internal definitions file: {$definitionsPath}");
			}

			$decoded = simdjson_decode($json, true);
			if (!is_array($decoded)) {
				throw GenericError::create("Metrics: failed to parse internal definitions JSON: {$definitionsPath}");
			}

			/** @var array<string, array{name: string, type: string, description: string, deprecated_use?: string}> $definitions */
			$definitions = $decoded;
			$store = new MetricStore($definitions);
			$context = new MetricsScrapeContext();
			$settings = $client->getSettings();
			$context->settings = [
				'searchd.data_dir' => (string)($settings->searchdDataDir ?? ''),
				'searchd.binlog_path' => (string)($settings->searchdBinlogPath ?? ''),
			];

			$collectors = [
				new ConnectivityCollector(),
				new ProcessCollector(),
				new CrashCollector(),
				new StatusCollector(),
				new ThreadsCollector(),
				new TablesCollector(),
				new SchemaCollector(),
				new FilesystemCollector(),
			];

			foreach ($collectors as $collector) {
				try {
					$collector->collect($client, $store, $context);
				} catch (ManticoreSearchClientError | ManticoreSearchResponseError $e) {
					Buddy::warning('Metrics: collector failed (' . $collector::class . '): ' . $e->getMessage());

					if ($collector instanceof ConnectivityCollector) {
						$store->addDirect(
							'connect_ok',
							'gauge',
							'Manticore connectivity check result (1 = OK, 0 = failed)',
							0
						);
						$store->addDirect(
							'connect_time',
							'gauge',
							'Manticore connectivity check time in seconds',
							0
						);
						$store->addDirect(
							'connect_time_seconds',
							'gauge',
							'Manticore connectivity check time in seconds',
							0
						);
					}
				}
			}

			return TaskResult::raw(PrometheusTextRenderer::render($store->all()))
				->setContentType('text/plain');
		};

		return Task::create(
			$taskFn,
			[$this->manticoreClient]
		)->run();
	}
}
