<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Interface;

use Manticoresearch\Buddy\Lib\Task;
use parallel\Runtime;

interface CommandExecutorInterface {
	/** @return Task */
	public function run(Runtime $runtime): Task;

	/** @return array<string> */
	public function getProps(): array;
}
