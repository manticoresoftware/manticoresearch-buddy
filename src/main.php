<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Base\Lib\CrashDetector;
use Manticoresearch\Buddy\Base\Lib\MetricThread;
use Manticoresearch\Buddy\Base\Lib\QueryProcessor;
use Manticoresearch\Buddy\Base\Network\EventHandler;
use Manticoresearch\Buddy\Base\Network\Server;
use Manticoresearch\Buddy\Core\Task\Task;
use Manticoresearch\Buddy\Core\Tool\Buddy;

// Init autoload first
include_once __DIR__ . DIRECTORY_SEPARATOR . 'init.php';

// We buffer it here cuz we need to expect listen address first
// We made in in onStart closure, but this is just workaround
// to make parallels work with external plugins
// this is defintely need to think how we can better, it's very complex now
ob_start();
try {
	QueryProcessor::init();
	$settings = QueryProcessor::getSettings();
	$initBuffer = ob_get_clean();
	putenv("PLUGIN_DIR={$settings->commonPluginDir}");
} catch (Throwable $t) {
	fwrite(STDERR, "Error while initialization: {$t->getMessage()}" . PHP_EOL);
	Buddy::debug($t->getTraceAsString());
	ob_flush();
	exit(1);
}


/** @var int $threads */
$threads = (int)(getenv('THREADS', true) ?: swoole_cpu_num());
$server = Server::create(
	[
	'daemonize' => 0,
	'max_request' => 0,
	'dispatch_mode' => 3,
	'enable_coroutine' => true,
	'task_enable_coroutine' => false,
	'task_max_request' => 0,
	'task_worker_num' => 0,
	'reactor_num' => $threads,
	'worker_num' => max(1, (int)($threads / 4)),
	'enable_reuse_port' => true,
	'input_buffer_size' => 2097152,
	'buffer_output_size' => 32 * 1024 * 1024, // byte in unit
	'tcp_fastopen' => true,
	// better not change, different oses different behaviour
	// 'max_conn' => $threads * 2,
	'backlog' => 8192,
	'tcp_defer_accept' => 3,
	'open_tcp_keepalive' => false,
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
		QueryProcessor::printPluginsInfo();
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
	->beforeStart(
		static function () {
			$settings = QueryProcessor::getSettings();
			// Configure PHP memory limit and post data sizeMetricThreadTest.php
			ini_set('memory_limit', '384M');
			ini_set('post_max_size', $settings->maxAllowedPacket);
			// Disable JIT because there is almost no benefit in our cases, and we encounter
			// weird issues with JIT in some instances of comment removal regex.
			// As tested across 1000 runs of the same regex, JIT offers no benefit.
			ini_set('pcre.jit', 0);
		}
	)
	// We need to run it outside of couroutine so do it before
	->beforeStart(
		static function () use ($server) {
			QueryProcessor::startPlugins(fn($p) => $server->addProcess($p));
		}
	)
	->beforeStop(
		static function () {
			QueryProcessor::stopPlugins();
		}
	)
	->beforeStop(
		static function () {
			QueryProcessor::stopPlugins();
		}
	)
	->beforeStart(
		static function () use ($server) {
			$process = MetricThread::instance()->process;
			$server->addProcess($process);
		}
	)
	->addHandler('request', EventHandler::request(...))
	->addTicker(
		static function () {
			$memory = memory_get_usage() / 1024;
			$formatted = number_format($memory, 3).'K';
			Buddy::debugv("memory usage: {$formatted}");
		}, 60
	);

if (is_telemetry_enabled()) {
	$server->addTicker(
		static function () {
			Buddy::debugv('running metric snapshot');
			MetricThread::instance()->execute(
				'checkAndSnapshot',
				[(int)(getenv('TELEMETRY_PERIOD', true) ?: 300)]
			);
		}, 10
	);
}

try {
	$server->start();
} catch (Throwable $t) {
	fwrite(STDERR, "Error while starting the server: {$t->getMessage()}" . PHP_EOL);
	Buddy::debug($t->getTraceAsString());
}
