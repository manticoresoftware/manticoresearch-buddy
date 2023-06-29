<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

// Initialize runtime environment to run Task in threads
include_once __DIR__ . DIRECTORY_SEPARATOR
	. '..' . DIRECTORY_SEPARATOR
	. 'vendor' . DIRECTORY_SEPARATOR
	. 'autoload.php'
;

use Manticoresearch\Buddy\Core\ManticoreSearch\Settings;
use Manticoresearch\Buddy\Core\Plugin\Pluggable;

set_error_handler(buddy_error_handler(...)); // @phpstan-ignore-line
Manticoresearch\Buddy\Core\Tool\Buddy::setVersionFile(__DIR__ . '/../APP_VERSION');

$pluggable = new Pluggable(
	Settings::fromArray(['common.plugin_dir' => getenv('PLUGIN_DIR') ?: '/usr/lib/manticore'])
);
$pluggable->reload();
