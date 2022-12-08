<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

// TODO: do something to bootstrap tests
include_once __DIR__ . DIRECTORY_SEPARATOR
	. '..' . DIRECTORY_SEPARATOR
	. 'src' . DIRECTORY_SEPARATOR
	. 'init.php';

// Not the best way, but it's ok for now
// phpcs:disable
// we mock config file just to make tests pass because we do not test backup here
if (is_dir('/etc/manticore')) {
	mkdir('/etc/manticore', 0755, true);
}
touch('/etc/manticore/manticore.conf');
putenv('SEARCHD_CONFIG=/etc/manticore/manticore.conf');
// Disable telemetry because we do not need it in tests
putenv('TELEMETRY=0');
// phpcs:enable
