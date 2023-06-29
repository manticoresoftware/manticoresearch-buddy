<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Lib\CrashDetector;
use Manticoresearch\Buddy\Base\Lib\MetricThread;
use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Base\Network\EventHandler;
use Manticoresearch\Buddy\Base\Network\Server;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Task\TaskPool;
use Manticoresearch\Buddy\Core\Tool\Buddy;
use Manticoresearch\Buddy\Core\Tool\Process;

// Init autoload first
include_once __DIR__ . DIRECTORY_SEPARATOR . 'init.php';

// We buffer it here cuz we need to expect listen address first
// We made in in onStart closure, but this is just workaround
// to make parallels work with external plugins
// this is defintely need to think how we can better, it's very complex now
ob_start();
QueryProcessor::init();
$settings = QueryProcessor::getSettings();
$initBuffer = ob_get_clean();
putenv("PLUGIN_DIR={$settings->commonPluginDir}");

$server = Server::create();
$server->onStart(
	static function () use ($initBuffer) {
		echo $initBuffer;
		$settings = QueryProcessor::getSettings();
		putenv("PLUGIN_DIR={$settings->commonPluginDir}");

		Task::setSettings(QueryProcessor::getSettings());
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
	->addTicker(
		static function () use ($server) {
			if (!EventHandler::shouldExit()) {
				return;
			}

			$server->stop();
		}, 1
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
