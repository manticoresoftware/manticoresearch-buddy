<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

/**
 * This file contains various global functions that are useful in some cases
 */
use Manticoresearch\Buddy\Lib\MetricThread;

/**
 * Emit metric into the separate thread
 *
 * @param string $name
 * @param int|float $value
 * @return void
 */
function metric(string $name, int|float $value) {
	static $thread;
	if (!isset($thread)) {
		$thread = MetricThread::start();
	}
	$thread->execute('add', [$name, $value]);
}
