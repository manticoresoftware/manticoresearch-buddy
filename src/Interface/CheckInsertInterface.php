<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Interface;

//@codingStandardsIgnoreStart
use Manticoresearch\Buddy\Enum\Datatype;
use RuntimeException;
//@codingStandardsIgnoreEnd

interface CheckInsertInterface {
	/**
	 * Checking for unescaped characters. Just as a test feature so far
	 *
	 * @param string|array<mixed> $row
	 * @param class-string<RuntimeException> $errorHandler
	 * @return void
	 */
	public static function checkUnescapedChars(mixed $row, string $errorHandler): void;

	/**
	 * @param callable $checker
	 * @param array<mixed> $rowVals
	 * @param array<Datatype> $types
	 * @param array<string> $cols
	 * @param class-string<RuntimeException> $errorHandler
	 * @return void
	 */
	public static function checkColTypesError(
		callable $checker,
		array $rowVals,
		array &$types,
		array $cols,
		string $errorHandler
	): void;
}
