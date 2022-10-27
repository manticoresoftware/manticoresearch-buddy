<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Trait;

use \Iterable;

trait NDJSONTrait {

	/**
	 * @param string $query
	 * @return Iterable<string>
	 */
	public static function parseNDJSON($query): Iterable {
		do {
			$ndPos = strpos($query, PHP_EOL);
			if ($ndPos === false) {
				$ndPos = strlen($query);
			}
			$row = substr($query, 0, $ndPos);
			if ($row !== '') {
				yield $row;
			}
			$query = substr($query, $ndPos + 1);
		} while (strlen($query) > 0);
	}

}
