<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Trait;

trait CheckClientStateTrait {

	/**
	 * checking if client's process (i.e., Manticore daemon) is alive
	 *
	 * @param string $clPid
	 * @param string $clPidPath
	 * @return bool
	 */
	public static function isClientAlive(string $clPid, string $clPidPath): bool {
		$pidFromFile = -1;
		if (file_exists($clPidPath)) {
			$content = file_get_contents($clPidPath);
			if ($content === false) {
				return false;
			}
			$pidFromFile = substr($content, 0, -1);
		}
		return $clPid === $pidFromFile;
	}
}
