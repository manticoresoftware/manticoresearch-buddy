<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Trait;

use Manticoresearch\Buddy\Enum\DATATYPE;

trait CheckInsertDataTrait {

	/**
	 * @param string|array<mixed> $row
	 * @return void
	 */
	public function checkInsertRow(string|array $row): void {
		$rowVals = $this->parseInsertValues($row);
		$curColTypes = [];
		foreach ($rowVals as $v) {
			$curColTypes[] = static::detectValType($v);
		}
		$this->checkColTypes($curColTypes);
	}

	/**
	 * Checking potentially ambiguous datatypes and datatype errors
	 *
	 * @param array<DATATYPE> $curTypes
	 * @return void
	 */
	protected function checkColTypes(array $curTypes): void {
		if (!empty($this->colTypes)) {
			// checking for column count in different rows
			if (sizeof($curTypes) !== sizeof($this->colTypes) or sizeof($curTypes) !== sizeof($this->cols)) {
				$this->error = 'Column count mismatch in INSERT statement';
			} else {
				$this->checkColTypesCompatibility($curTypes);
			}
		} else {
			$this->colTypes = array_map(
				function ($v) {
					return $v->value;
				}, $curTypes
			);
		}
	}

	/**
	 * Checking for incompatible column datatypes in different rows
	 *
	 * @param array<DATATYPE> $curTypes
	 * @return void
	 */
	protected function checkColTypesCompatibility(array $curTypes) {
		$TYPE_BUNDLES = [
			[DATATYPE::T_JSON],
			[DATATYPE::T_MULTI_64, DATATYPE::T_MULTI],
			[DATATYPE::T_FLOAT, DATATYPE::T_BIGINT, DATATYPE::T_INT],
			[DATATYPE::T_TEXT, DATATYPE::T_STRING],
		];
		foreach ($curTypes as $i => $t) {
			foreach ($TYPE_BUNDLES as $tb) {
				$i1 = array_search($t, $tb);
				$i2 = array_search($this->colTypes[$i], $tb);
				if (($i1 === false && $i2) || ($i2 === false && $i1)) {
					if (!isset($this->error)) {
						$this->error = "Incompatible data types for columns {$this->cols[$i]}: ";
						$this->error .= "{$t->value} {$this->colTypes[$i]}";
					} else {
						$this->error .= ", {$this->cols[$i]}";
					}
				}
				// updating possible datatype by priority
				if ($i1 >= $i2) {
					continue;
				}
				$this->colTypes[$i] = $tb[$i1]->value;
			}
		}
	}

}
