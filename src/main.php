<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Lib\CliArgsProcessor;
use Manticoresearch\Buddy\Base\Lib\CrashDetector;
use Manticoresearch\Buddy\Base\Lib\MetricThread;
use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Base\Network\EventHandler;
use Manticoresearch\Buddy\Base\Network\Server;
use Manticoresearch\Buddy\Core\ManticoreSearch\Client as HTTPClient;
use Manticoresearch\Buddy\Core\Task\TaskPool;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Process;
use Symfony\Component\DependencyInjection\ContainerBuilder as Container;

// Init autoload first
/** @var Container */
$container = include_once __DIR__ . DIRECTORY_SEPARATOR . 'init.php';

$opts = CliArgsProcessor::run();
if (!isset($opts['debug'])) {
	// Set error reporting with excluding warnings
	error_reporting(E_ALL & ~E_WARNING);
}

/** @var HTTPClient $manticoreClient */
$manticoreClient = $container->get('manticoreClient');
$manticoreClient->setServerUrl($opts['listen']);

// Initialize runtimes that we will use for request handling
EventHandler::init();

$server = Server::create()
	->onStart(
		function () {
			QueryProcessor::init();

		// Dispay all loaded plugins
			echo 'Loaded plugins:' . PHP_EOL
			. '  core: ' . implode(', ', QueryProcessor::getCorePlugins()) . PHP_EOL
			. '  local: ' . implode(', ', QueryProcessor::getLocalPlugins()) . PHP_EOL
			. '  extra: ' . implode(', ', QueryProcessor::getExtraPlugins()) . PHP_EOL
			;
		}
	)
	->onStart(
		static function () {
			buddy_metric('invocation', 1);

			$settings = QueryProcessor::getSettings();
			$crashDetector = new CrashDetector($settings);
			if (!$crashDetector->hadCrash()) {
				return;
			}

			buddy_metric('crash', 1);
		}
	)
	->onStart(
		static function () {
			$settings = QueryProcessor::getSettings();

			// Configure PHP memory limit and post data sizeMetricThreadTest.php
			ini_set('memory_limit', '384M');
			ini_set('post_max_size', $settings->maxAllowedPacket);
		}
	)
	->addHandler('request', EventHandler::request(...))
	->addHandler('error', EventHandler::error(...))
	->addTicker(
		static function () {
			$memory = memory_get_usage() / 1024;
			$formatted = number_format($memory, 3).'K';
			Buddy::debug("memory usage: {$formatted}");
		}, 60
	)
	->addTicker(
		static function () {
			$taskCount = TaskPool::getCount();
			Buddy::debug("running {$taskCount} tasks");
		}, 60
	)
	->addTicker(EventHandler::clientCheckTickerFn(Process::getParentPid()), 5, 'server');

if (is_telemetry_enabled()) {
	$server->addTicker(
		static function () {
			Buddy::debug('running metric snapshot');
			MetricThread::instance()->execute(
				'checkAndSnapshot',
				[(int)(getenv('TELEMETRY_PERIOD', true) ?: 300)]
			);
		}, 10, 'server'
	);
	register_shutdown_function(MetricThread::destroy(...));
}

// Shutdown functions MUST be registered here only
register_shutdown_function(EventHandler::destroy(...));

$server->start();
