<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\QueryParser;

use Manticoresearch\Buddy\Enum\Datalim;
use Manticoresearch\Buddy\Enum\Datatype;
use Manticoresearch\Buddy\Exception\QueryParserError;
use Manticoresearch\Buddy\Interface\InsertQueryParserInterface;

class JSONInsertParser extends JSONParser implements InsertQueryParserInterface {

	use \Manticoresearch\Buddy\Trait\CheckInsertDataTrait;

	/**
	 * @param string $query
	 * @return array{name:string,cols:array<string>,colTypes:array<string>}
	 */
	public function parse($query): array {
		$res = parent::parse($query);
		$res += ['cols' => $this->cols, 'colTypes' => self::stringifyColTypes($this->colTypes)];
		return $res;
	}

	/**
	 * @param array{index:string,id:int,doc:array<mixed>}|array<
	 * string, array{index:string,id:int,doc:array<mixed>}> $query
	 * @return array<mixed>
	 */
	public function parseJSONRow(array $query): array {
		if ($this->isNdJSON) {
			if (!array_key_exists('insert', $query)) {
				throw new QueryParserError("Operation name 'insert' is missing");
			}
			$query = $query['insert'];
		}
		if (!is_array($query)) {
			throw new QueryParserError("Mandatory request field 'insert' must be an object");
		}
		if (!array_key_exists('index', $query)) {
			throw new QueryParserError("Mandatory request field 'index' is missing");
		}
		if (!is_string($query['index'])) {
			throw new QueryParserError("Mandatory request field 'index' must be a string");
		}
		$this->name = $query['index'];
		if (!array_key_exists('doc', $query)) {
			throw new QueryParserError("Mandatory request field 'doc' is missing");
		}
		if (!is_array($query['doc'])) {
			throw new QueryParserError("Mandatory request field 'doc' must be an object");
		}
		if (empty($this->cols)) {
			$this->cols = array_keys($query['doc']);
		}
		$row = $query['doc'];
		self::checkUnescapedChars($row, QueryParserError::class);
		self::checkColTypesError(
			[$this, 'detectValType'],
			$this->parseInsertValues($row),
			$this->colTypes,
			$this->cols,
			QueryParserError::class
		);

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
	 * @return Datatype
	 */
	protected static function detectValType(mixed $val): Datatype {
		if (is_float($val)) {
			return Datatype::Float;
		}
		if (is_int($val)) {
			if ($val > Datalim::MySqlMaxInt->value) {
				return Datatype::Bigint;
			}
			return Datatype::Int;
		}
		if (is_array($val)) {
			if (!array_is_list($val)) {
				return Datatype::Json;
			}
			foreach ($val as $subVal) {
				if (self::detectValType($subVal) === Datatype::Bigint) {
					return Datatype::Multi64;
				}
			}
			return Datatype::Multi;
		}

		return (is_string($val) && self::isManticoreString($val) === true) ? Datatype::String : Datatype::Text;
	}

}
