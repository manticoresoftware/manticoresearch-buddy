<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Trait;

use Manticoresearch\Buddy\Enum\Datatype;

use Manticoresearch\Buddy\Exception\GenericError;

trait CheckInsertDataTrait {

	/**
	 * Checking for unescaped characters. Just as a test feature so far
	 *
	 * @param string|array<mixed> $row
	 * @param class-string<GenericError> $errorHandler
	 * @return void
	 */
	public static function checkUnescapedChars(mixed $row, string $errorHandler): void {
		if (!is_string($row)) {
			return;
		}
		$charsToEsc = ['"'];
		foreach ($charsToEsc as $ch) {
			if (preg_match("/(?<!\\\\)$ch/", $row)) {
				throw new $errorHandler("Unescaped '$ch' character found in INSERT statement");
			}
		}
	}

	/**
	 * Checking potentially incompatible Datatypes among values inserted
	 *
	 * @param callable $checker
	 * @param array<mixed> $rowVals
	 * @param array<Datatype> $types
	 * @param array<string> $cols
	 * @param class-string<GenericError> $errorHandler
	 * @return void
	 */
	public static function checkColTypesError(
		callable $checker,
		array $rowVals,
		array &$types,
		array $cols,
		string $errorHandler
	): void {
		$curTypes = array_map($checker, $rowVals);
		if (!empty($types)) {
			// checking for column count in different rows
			if (sizeof($curTypes) !== sizeof($types) or sizeof($curTypes) !== sizeof($cols)) {
				throw new $errorHandler('Column count mismatch in INSERT statement');
			}
			self::checkColTypesCompatibilityError($curTypes, $types, $cols, $errorHandler);
		} else {
			$types = $curTypes;
		}
	}

	/**
	 * Helper function for the incompatible column Datatypes check
	 *
	 * @param Datatype $type
	 * @param string $col
	 * @param int $i
	 * @param array<Datatype> &$types
	 * @param string &$error
	 * @return void
	 */
	protected static function checkTypeBundlesCompatibility(
		Datatype $type,
		string $col,
		int $i,
		array &$types,
		string &$error
	): void {
		$typeBundles = [
			[Datatype::Json, Datatype::Null],
			[Datatype::Multi64, Datatype::Multi, Datatype::Null],
			[Datatype::Float, Datatype::Bigint, Datatype::Int, Datatype::Null],
			[Datatype::Text, Datatype::String, Datatype::Null],
		];
		$isNewErrorCol = true;
		foreach ($typeBundles as $tb) {
			$i1 = array_search($type, $tb);
			$i2 = array_search($types[$i], $tb);
			// updating possible Datatype by priority set in $typeBundles
			if ($i1 !== false && $i2 !== false && $i1 < $i2) {
				$types[$i] = $tb[$i1];
			}
			if ($type === Datatype::Null || $types[$i] === Datatype::Null
				|| ($i1 !== false && $i2 !== false) || ($i1 === false && $i2 === false)) {
				continue;
			}
			// Incompatible types are found
			if ($error === '') {
				$error = "Incompatible types in '{$col}': ";
			} elseif ($isNewErrorCol) {
				$error .= "; '{$col}': ";
			}
			$isNewErrorCol = false;
			$error .= "'{$type->value} {$types[$i]->value}',";
			break;
		}
	}

	/**
	 * Checking for incompatible column Datatypes in different rows
	 *
	 * @param array<Datatype> $curTypes
	 * @param array<Datatype> &$types
	 * @param array<string> $cols
	 * @param class-string<GenericError> $errorHandler
	 * @return void
	 */
	protected static function checkColTypesCompatibilityError(
		array $curTypes,
		array &$types,
		array $cols,
		string $errorHandler
	): void {
		$error = '';
		foreach ($curTypes as $i => $t) {
			self::checkTypeBundlesCompatibility($t, $cols[$i], $i, $types, $error);
		}
		if ($error !== '') {
			throw new $errorHandler($error);
		}
	}

	/**
	 * Detecting if type is text or string, following Elastic's logic
	 *
	 * @param string $val
	 * @return bool
	 */
	protected static function isManticoreString(string $val): bool {
		$regexes = [
			// so far only email regex is implemented for the prototype
			'email' => '/^\s*(?:[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+'
			. '(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*|"'
			. '(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|'
			. '\\[\x01-\x09\x0b\x0c\x0e-\x7f])*")\\@'
			. '(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+'
			. '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:(2(5[0-5]|[0-4][0-9])|1[0-9][0-9]|[1-9]?[0-9]))\.){3}'
			. '(?:(2(5[0-5]|[0-4][0-9])|1[0-9][0-9]|[1-9]?[0-9])|[a-z0-9-]*'
			. '[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|'
			. '\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\])\s*$/i',
		];
		foreach ($regexes as $r) {
			if (preg_match($r, substr($val, 0, -1))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Converting enum Datatypes to their string values
	 *
	 * @param array<Datatype> $types
	 * @return array<string>
	 */
	protected function stringifyColTypes(array $types): array {
		return array_map(
			function ($v) {
				return $v->value;
			}, $types
		);
	}
}
