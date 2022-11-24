<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Lib\ContainerBuilder;
use Manticoresearch\Buddy\Lib\QueryProcessor;
use Manticoresearch\Buddy\Network\EventHandler;
use Manticoresearch\Buddy\Network\Server;

// Init autoload first
include_once __DIR__ . DIRECTORY_SEPARATOR
  . '..' . DIRECTORY_SEPARATOR
  . 'vendor' . DIRECTORY_SEPARATOR
  . 'autoload.php'
;

$longopts  = ['pid:', 'pid-file:', 'host::', 'port::', 'disable-telemetry', 'help', 'config:'];
$defaultOpts = ['host' => '127.0.0.1', 'port' => 5000];

/** @var array{config:string,host:string,port:string,pid:?string,pid-file:?string,port:?int,disable-telemetry?:bool,help?:bool} */
$opts = array_replace(getopt('', $longopts), $defaultOpts);

if (isset($opts['disable-telemetry'])) {
	putenv('TELEMETRY=1');
}

if (isset($opts['help'])) {
	echo "This is going to be help\n";
	exit(0);
}

// Not the best way, but it's ok for now
// phpcs:disable
define('SEARCHD_CONFIG', $opts['config']);
// phpcs:enable

QueryProcessor::setContainer(ContainerBuilder::create());

Server::create($opts['host'], (int)$opts['port'])
	->addHandler('request', EventHandler::request(...))
	->addHandler('error', EventHandler::error(...))
	->addTicker(
		function () {
			$memory = memory_get_usage() / 1024;
			$formatted = number_format($memory, 3).'K';
			echo "Current memory usage: {$formatted}\n";
		}, 10
	)
	->addTicker(EventHandler::clientCheckTickerFn((int)$opts['pid'], (string)$opts['pid-file']), 5, 'client')
	->start();
