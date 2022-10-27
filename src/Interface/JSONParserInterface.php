<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

use \Iterable;

interface JSONParserInterface extends QueryParserInterface {
	/**
	 * @param string $query
	 * @return Iterable<string>
	 */
	public static function parseNDJSON(string $query): Iterable;
	/**
	 * @param array<mixed> $query
	 * @return array<mixed>
	 */
	public function parseJSONRow(array $query): array;
}