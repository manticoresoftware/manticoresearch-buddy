<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Buddy\Network\EventHandler;
use Manticoresearch\Buddy\Network\Server;

// Init autoload first
include_once __DIR__ . DIRECTORY_SEPARATOR
  . '..' . DIRECTORY_SEPARATOR
  . 'vendor' . DIRECTORY_SEPARATOR
  . 'autoload.php'
;

$longopts  = ['pid:', 'pid_path:', 'listen:', 'sockAddr::', 'sockPort::'];
$defaultOpts = ['sockAddr' => '127.0.0.1', 'sockPort' => 5000];

[
	'pid' => $clPid, /** @var string $clPid */
	'pid_path' => $clPidPath, /** @var string $clPidPath */
	//'listen' => $clUrl, /** @var string $clUrl */
	'sockAddr' => $sockAddr, /** @var string $sockAddr */
	'sockPort' => $sockPort, /** @var string $sockPort */
] = getopt('', $longopts) + $defaultOpts;

Server::create($sockAddr, (int)$sockPort)
	->addHandler('request', EventHandler::request(...))
	->addHandler('error', EventHandler::error(...))
	->addTicker(
		function () {
			$memory = memory_get_usage() / 1024;
			$formatted = number_format($memory, 3).'K';
			echo "Current memory usage: {$formatted}\n";
		}, 10
	)
	->addTicker(EventHandler::clientCheckTickerFn((int)$clPid, (string)$clPidPath), 5, 'client')
	->start();
