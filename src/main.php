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
use Manticoresearch\Buddy\Base\Sharding\Thread as ShardingThread;
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

$threads = getenv('THREADS', true) ?: swoole_cpu_num();
$server = Server::create(
	[
	'daemonize' => 0,
	'max_request' => 0,
	'enable_coroutine' => true,
	'task_enable_coroutine' => true,
	'task_max_request' => 0,
	'task_worker_num' => $threads,
	'reactor_num' => $threads,
	'worker_num' => $threads,
	'enable_reuse_port' => true,
	'input_buffer_size' => 2097152,
	'buffer_output_size' => 32 * 1024 * 1024, // byte in unit
	'tcp_fastopen' => true,
	'max_conn' => 8192,
	'backlog' => 8192,
	'tcp_defer_accept' => 3,
	'open_tcp_keepalive' => true,
	'open_tcp_nodelay' => true,
	'open_http_protocol' => true,
	'open_http2_protocol' => false,
	'open_websocket_protocol' => false,
	'open_mqtt_protocol' => false,
	'reload_async' => false,
	'http_parse_post' => false,
	'http_parse_cookie' => false,
	'upload_tmp_dir' => '/tmp',
	'http_compression' => true,
	'http_compression_level' => 5,
	'compression_min_length' => 20,
	'open_cpu_affinity' => false,
	'max_wait_time' => 5,
	]
);
$server->beforeStart(
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
			// Hack to fix issue with cut the output from manticore daemon
			usleep(20000);
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
	->beforeStart(
		static function () use ($server) {
			$process = ShardingThread::instance()->process;
			$server->addProcess($process);
		}
	)
	->beforeStart(
		static function () use ($server) {
			$process = MetricThread::instance()->process;
			$server->addProcess($process);
		}
	)
	->addHandler(
		'request', static function (...$args) use ($server) {
			array_unshift($args, $server);
			EventHandler::request(...$args);
		}
	)
	->addHandler('task', EventHandler::task(...))
	->addHandler('finish', EventHandler::finish(...))
	->addTicker(
		static function () {
			ShardingThread::instance()->execute('ping', []);
		}, 1
	)
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
	)->addTicker(EventHandler::clientCheckTickerFn($server->pid, Process::getParentPid()), 5);

if (is_telemetry_enabled()) {
	$server->addTicker(
		static function () {
			Buddy::debug('running metric snapshot');
			MetricThread::instance()->execute(
				'checkAndSnapshot',
				[(int)(getenv('TELEMETRY_PERIOD', true) ?: 300)]
			);
		}, 10
	);
}

$server->start();
