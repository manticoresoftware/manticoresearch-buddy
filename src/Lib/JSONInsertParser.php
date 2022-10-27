<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Enum\DATATYPE;
use Manticoresearch\Buddy\Interface\CheckInsertInterface;
use Manticoresearch\Buddy\Lib\JSONParser;

class JSONInsertParser extends JSONParser implements CheckInsertInterface {

	use \Manticoresearch\Buddy\Trait\CheckInsertDataTrait;

	/**
	 * @param array{index:string, doc:array<mixed>} $query
	 * @return array<mixed>
	 */
	public function parseJSONRow(array $query): array {
		if (empty($this->cols)) {
			$this->name = $query['index'];
			$this->cols = array_keys($query['doc']);
		}
		$row = array_values($query['doc']);
		$this->checkInsertRow($row);
		return $row;
	}

	/**
	 * Splitting insert row values expression into separate values
	 *
	 * @param mixed $insertRow
	 * @return array<mixed>
	 */
	protected function parseInsertValues(mixed $insertRow): array {
		return array_values((array)$insertRow);
	}

	/**
	 * @param mixed $val
	 * @return DATATYPE
	 */
	protected static function detectValType(mixed $val): DATATYPE {
		//!TODO
		if ($val === null) {
			return DATATYPE::T_JSON;
		}
		return DATATYPE::T_JSON;
	}

}
