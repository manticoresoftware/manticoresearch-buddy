<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Lib\CliArgsProcessor;
use Manticoresearch\Buddy\Network\EventHandler;
use Manticoresearch\Buddy\Network\Server;

// Init autoload first
include_once __DIR__ . DIRECTORY_SEPARATOR . 'init.php';

[$pid, $pidFile, $host, $port] = CliArgsProcessor::run();

Server::create($host, $port)
	->addHandler('request', EventHandler::request(...))
	->addHandler('error', EventHandler::error(...))
	->addTicker(
		function () {
			$memory = memory_get_usage() / 1024;
			$formatted = number_format($memory, 3).'K';
			echo "Current memory usage: {$formatted}\n";
		}, 10
	)
	->addTicker(EventHandler::clientCheckTickerFn($pid, $pidFile), 5, 'client')
	->start();
