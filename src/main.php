<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Lib\CliArgsProcessor;
use Manticoresearch\Buddy\Lib\MetricThread;
use Manticoresearch\Buddy\Lib\TaskPool;
use Manticoresearch\Buddy\Network\EventHandler;
use Manticoresearch\Buddy\Network\ManticoreClient\HTTPClient;
use Manticoresearch\Buddy\Network\Server;
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
	->addHandler('request', EventHandler::request(...))
	->addHandler('error', EventHandler::error(...))
	->addTicker(
		function () {
			$memory = memory_get_usage() / 1024;
			$formatted = number_format($memory, 3).'K';
			debug("memory usage: {$formatted}");
		}, 60
	)
	->addTicker(
		function () {
			$taskCount = TaskPool::getCount();
			debug("running {$taskCount} tasks");
		}, 60
	)
	->addTicker(EventHandler::clientCheckTickerFn(get_parent_pid()), 5, 'server');

if (is_telemetry_enabled()) {
	$server->addTicker(
		function () {
			debug('running metric snapshot');
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
