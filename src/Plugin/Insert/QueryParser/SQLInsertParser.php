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

class SQLInsertParser extends BaseParser implements InsertQueryParserInterface {

	use CheckInsertDataTrait;

	/**
	 * @var array<array<string>> $blocksReplaced
	 */
	protected array $blocksReplaced;
	/**
	 * @var array<string> $insertVals
	 */
	protected array $insertVals;

	/**
	 * @param string $query
	 * @return array{name:string,cols:array<string>,colTypes:array<string>}
	 */
	public function parse($query): array {
		$this->cols = $this->colTypes = [];
		$matches = [];
		preg_match_all(
			'/\s*(?:INSERT|REPLACE)\s+INTO\s+(.*?)\s*\((.*?)\)\s+VALUES\s*(.*?)\s*;?\s*$/is',
			$query,
			$matches
		);
		if (empty($matches[2])) {
			throw QueryParseError::create('Cannot create table with column names missing');
		}
		$name = $matches[1][0];
		$colExpr = $matches[2][0];
		$this->cols = array_map('trim', explode(',', $colExpr));
		$valExpr = $matches[3][0];
		if (!$name || !$valExpr) {
			throw new QueryParseError("Invalid query passed: $query");
		}
		$rows = $this->parseInsertRows($valExpr);
		foreach ($rows as $row) {
			//self::checkUnescapedChars($row, QueryParseError::class);
			self::checkColTypesError(
				[$this, 'detectValType'],
				$this->parseInsertValues($row),
				$this->colTypes,
				$this->cols,
				QueryParseError::class
			);
		}

		return ['name' => $name, 'cols' => $this->cols, 'colTypes' => self::stringifyColTypes($this->colTypes)];
	}

	/**
	 * Helper function to build insert row expression
	 *
	 * @param string $valExpr
	 * @param int $i
	 * @param bool &$isValStarted
	 * @param int &$parenthInd
	 * @param string &$curVal
	 * @return bool
	 */
	protected static function processExprChr(
		string $valExpr,
		int $i,
		bool &$isValStarted,
		int &$parenthInd,
		string &$curVal
	): bool {
		switch ($valExpr[$i]) {
			case '(':
				if (!$isValStarted) {
					$isValStarted = true;
				} else {
					$curVal .= '(' ;
				}
				$parenthInd++;
				break;
			case ')':
				$parenthInd--;
				if (!$parenthInd && $valExpr[$i - 1] !== '\\') {
					$isValStarted = false;
					return true;
				}

				$curVal .= ')' ;
				break;
			default:
				if ($isValStarted) {
					$curVal .= $valExpr[$i];
				}
				break;
		}

		return false;
	}

	/**
	 * Splitting VALUES expression into separate row values
	 *
	 * @param string $valExpr
	 * @return Iterable<string>|array<string>
	 */
	protected static function parseInsertRows(string $valExpr): Iterable {
		$curVal = '';
		$parenthInd = 0;
		$isValStarted = false;
		for ($i = 0; $i < strlen($valExpr); $i++) {
			$isRowFinished = self::processExprChr($valExpr, $i, $isValStarted, $parenthInd, $curVal);
			if ($isRowFinished === false) {
				continue;
			}
			yield $curVal;
			$curVal = '';
		}
	}

	/**
	 * Splitting insert row values expression into separate values
	 *
	 * @param string|array<mixed> $insertRow
	 * @return array<string>
	 */
	protected function parseInsertValues(string|array $insertRow): array {
		$this->insertVals = $this->blocksReplaced = [];
		if (!is_array($insertRow)) {
			$insertRow = (string)$insertRow;
			$insertRow = $this->replaceCommaBlocks($insertRow);
			$this->insertVals = explode(',', $insertRow);
			if (!empty($this->blocksReplaced)) {
				$this->restoreCommaBlocks();
			}
			$this->insertVals = array_map('trim', $this->insertVals);
		}

		return $this->insertVals;
	}

	/**
	 * Temporarily replacing column values that can contain commas to tokens
	 * before splitting row values expression
	 *
	 * @param string $row
	 * @return string
	 */
	protected function replaceCommaBlocks(string $row): string {
		$this->blocksReplaced = [];
		$replInfo = [
			['{', '}'],
			['\(', '\)'],
			["'", "'"],
		];

		foreach ($replInfo as $i => $replItem) {
			$matches = [];
			$repl = "%$i";
			$pos = strpos($row, $repl);
			while ($pos !== false) {
				$repl = "%$repl";
				$pos = strpos($row, $repl);
			}
			$pattern = "/($replItem[0].*?([^\\\]|)$replItem[1])/i";
			preg_match_all($pattern, $row, $matches);
			foreach ($matches[0] as $m) {
				$row = preg_replace($pattern, $repl, $row);
				if (!isset($row)) {
					throw new QueryParseError('Comma replace error');
				}
				$this->blocksReplaced[$repl][] = $m;
			}
		}

		return $row;
	}

	/**
	 * @param string $replaced
	 * @param int $valInd
	 * @return bool
	 */
	protected function restoreSingleCommaBlock(string $replaced, int $valInd): bool {
		if (str_contains($this->insertVals[$valInd], $replaced)) {
			$restored = array_shift($this->blocksReplaced[$replaced]);
			if (isset($restored)) {
				$this->insertVals[$valInd] = str_replace($replaced, $restored, $this->insertVals[$valInd]);
				return true;
			}
		}
		return false;
	}

	/**
	 * Getting replaced column values back
	 * @return void
	 */
	protected function restoreCommaBlocks(): void {
		do {
			$isBlockRestored = false;
			foreach (array_keys($this->blocksReplaced) as $replaced) {
				foreach (array_keys($this->insertVals) as $valInd) {
					$isBlockRestored = $isBlockRestored || $this->restoreSingleCommaBlock($replaced, $valInd);
				}
			}
		} while ($isBlockRestored);
	}

	/**
	 * Helper function to detect numeric datatypes
	 *
	 * @param string $val
	 * @return Datatype
	 */
	protected static function detectNumericValType(string $val): Datatype {
		$int = (int)$val;
		if ((string)$int !== $val) {
			return Datatype::Float;
		}

		if ($int > Datalim::MySqlMaxInt->value) {
			return Datatype::Bigint;
		}

		return Datatype::Int;
	}

	/**
	 * Helper function to detect Mva datatypes
	 *
	 * @param string $val
	 * @return Datatype
	 */
	protected static function detectMvaTypes(string $val): Datatype {
		$subVals = explode(',', substr($val, 1, -1));
		array_walk(
			$subVals,
			function (&$v) {
				$v = trim($v);
			}
		);
		$returnType = Datatype::Multi;
		foreach ($subVals as $v) {
			$subValType = self::detectValType($v);
			if ($returnType !== Datatype::Multi64 && $subValType === Datatype::Bigint) {
				$returnType = Datatype::Multi64;
			} elseif ($subValType === Datatype::Float) {
				return Datatype::Json;
			}
		}

		return $returnType;
	}

	/**
	 * @param string $val
	 * @return bool
	 */
	protected static function isValidJSONVal(string $val): bool {
		return json_validate(substr($val, 1, -1));
	}

	/**
	 * @param string $val
	 * @return Datatype
	 */
	protected static function detectValType(string $val): Datatype {
		return match (true) {
			// numeric types
			is_numeric($val) => self::detectNumericValType($val),
			// json type
			((substr($val, 1, 1) === '{' && substr($val, -2, 1) === '}') ||
			(substr($val, 1, 1) === '[' && substr($val, -2, 1) === ']'))
			&& self::isValidJSONVal($val) => Datatype::Json,
			// mva types
			(substr($val, 0, 1) === '(' && substr($val, -1) === ')') => self::detectMvaTypes($val),
			self::isManticoreString($val) => Datatype::String,
			self::isManticoreDate($val) => Datatype::Timestamp,
			default => Datatype::Text,
		};
	}
}
