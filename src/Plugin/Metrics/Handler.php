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
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\SettingsCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\StatusCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\TablesCollector;
use Manticoresearch\Buddy\Base\Plugin\Metrics\Collector\ThreadsCollector;
use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client;
use Manticoresearch\Buddy\Core\Plugin\BaseHandlerWithClient;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskResult;

final class Handler extends BaseHandlerWithClient
{
	public function __construct(public Payload $payload) {
	}

	/**
	 * @throws GenericError
	 */
	public function run(): Task {
		$taskFn = static function (Client $client): TaskResult {
			/** @var array<string, array{name: string, type: string, description: string, deprecated_use?: string}> $definitions */
			$definitions = require __DIR__ . '/MetricDefinitionsMap.php';
			$store = new MetricStore($definitions);
			$context = new MetricsScrapeContext();

			$collectors = [
				new ConnectivityCollector(),
				new SettingsCollector(),
				new ProcessCollector(),
				new CrashCollector(),
				new StatusCollector(),
				new ThreadsCollector(),
				new TablesCollector(),
				new SchemaCollector(),
				new FilesystemCollector(),
			];

			foreach ($collectors as $collector) {
				$collector->collect($client, $store, $context);
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
