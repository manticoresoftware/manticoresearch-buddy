<?php declare(strict_types=1);

/*
 Copyright (c) 2023-present, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser;

use Manticoresearch\Buddy\Core\Error\QueryParseError;

class JSONInsertParser extends JSONParser implements InsertQueryParserInterface {

	use CheckInsertDataTrait;

	/**
	 * @var int $id
	 */
	protected ?int $id = null;

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
	 * @throws QueryParseError
	 */
	protected function extractRow(array $query): array {
		if ($this->isNdJSON) {
			if (!array_key_exists('insert', $query)) {
				throw new QueryParseError("Operation name 'insert' is missing");
			}
			$query = $query['insert'];
		}
		if (!is_array($query)) {
			throw new QueryParseError("Mandatory request field 'insert' must be an object");
		}
		if (!array_key_exists('index', $query)) {
			throw new QueryParseError("Mandatory request field 'index' is missing");
		}
		if (!is_string($query['index'])) {
			throw new QueryParseError("Mandatory request field 'index' must be a string");
		}
		$this->name = $query['index'];
		if (!array_key_exists('doc', $query)) {
			throw new QueryParseError("Mandatory request field 'doc' is missing");
		}
		if (!is_array($query['doc'])) {
			throw new QueryParseError("Mandatory request field 'doc' must be an object");
		}
		return $query['doc'];
	}

	/**
	 * @param array{index:string,id:int,doc:array<mixed>}|array<
	 * string, array{index:string,id:int,doc:array<mixed>}> $query
	 * @return array<mixed>
	 */
	public function parseJSONRow(array $query): array {
		$row = $this->extractRow($query);
		if (empty($row)) {
			return $row;
		}
		$vals = $this->parseInsertValues($row);

		self::checkUnescapedChars($row, QueryParseError::class);
		self::checkColTypesError(
			[$this, 'detectValType'],
			$vals,
			$this->colTypes,
			$this->cols,
			QueryParseError::class
		);
		return $row;
	}

	/**
	 * Getting insert row values and their types
	 *
	 * @param mixed $insertRow
	 * @return array<mixed>
	 */
	protected function parseInsertValues(mixed $insertRow): array {
		$valuesRow = (array)$insertRow;
		$this->cols = array_values(
			array_unique(
				array_merge($this->cols, array_keys($valuesRow))
			)
		);
		$vals = [];
		foreach ($this->cols as $i => $col) {
			if (!is_string($col)) {
				continue;
			}
			$vals[] = $valuesRow[$col] ?? null;
			if (sizeof($this->colTypes) > $i) {
				continue;
			}
			$this->colTypes[] = Datatype::Null;
		}

		return $vals;
	}

	/**
	 * Helper to detect types of array items
	 *
	 * @param array<mixed> $val
	 * @return Datatype
	 */
	protected static function detectArrayVal(array $val): Datatype {
		if (!array_is_list($val)) {
			return Datatype::Json;
		}
		$returnType = Datatype::Multi;
		foreach ($val as $subVal) {
			$subValType = self::detectValType($subVal);
			if ($subValType === Datatype::Bigint) {
				$returnType = Datatype::Multi64;
			}
			if ($subValType !== Datatype::Bigint && $subValType !== Datatype::Int) {
				return Datatype::Json;
			}
		}
		return $returnType;
	}

	/**
	 * @param int $val
	 * @return Datatype
	 */
	protected static function detectIntVal(int $val): Datatype {
		return $val > Datalim::MySqlMaxInt->value ? Datatype::Bigint : Datatype::Int;
	}

	/**
	 * @param string $val
	 * @return Datatype
	 */
	protected static function detectStringVal(string $val): Datatype {
		return match (true) {
			self::isManticoreString($val) => Datatype::String,
			self::isManticoreDate($val) => Datatype::Timestamp,
			default => Datatype::Text,
		};
	}

	/**
	 * @param mixed $val
	 * @return Datatype
	 */
	protected static function detectValType(mixed $val): Datatype {
		return match (true) {
			($val === null) => Datatype::Null,
			is_float($val) => Datatype::Float,
			is_int($val) => self::detectIntVal($val),
			is_array($val) => self::detectArrayVal($val),
			is_string($val) => self::detectStringVal($val),
			default => Datatype::Text,
		};
	}
}
