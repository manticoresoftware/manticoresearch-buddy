<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Enum\Datalim;
use Manticoresearch\Buddy\Enum\Datatype;
use Manticoresearch\Buddy\Exception\QueryParserError;
use Manticoresearch\Buddy\Interface\InsertQueryParserInterface;
use Manticoresearch\Buddy\Lib\QueryParser;

class SQLInsertParser extends QueryParser implements InsertQueryParserInterface {

	use \Manticoresearch\Buddy\Trait\CheckInsertDataTrait;

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
		preg_match_all('/\s*INSERT\s+INTO\s+(.*?)\s*\((.*?)\)\s+VALUES\s*(.*?)\s*;?\s*$/i', $query, $matches);
		$name = $matches[1][0];
		$colExpr = $matches[2][0];
		$this->cols = array_map('trim', explode(',', $colExpr));
		$valExpr = $matches[3][0];

		$rows = $this->parseInsertRows($valExpr);
		foreach ($rows as $row) {
// 			$rowVals = $this->parseInsertValues($row);
// 			$curColTypes = array_map([$this, 'detectValType'], $rowVals);
// 			$this->error = self::checkColTypesError($curColTypes, $this->colTypes, $this->cols);
// 			if ($this->error !== '') {
// 				throw new QueryParserError($this->error);
// 			}
			self::checkUnescapedChars($row, QueryParserError::class);
			self::checkColTypesError(
				[$this, 'detectValType'],
				$this->parseInsertValues($row),
				$this->colTypes,
				$this->cols,
				QueryParserError::class
			);
		}

		return ['name' => $name, 'cols' => $this->cols, 'colTypes' => self::stringifyColTypes($this->colTypes)];
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
						yield $curVal;
						$isValStarted = false;
						$curVal = '';
					} else {
						$curVal .= ')' ;
					}
					break;
				default:
					if ($isValStarted) {
						$curVal .= $valExpr[$i];
					}
					break;
			}
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
					throw new QueryParserError('Comma replace error');
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
					$isBlockRestored = $this->restoreSingleCommaBlock($replaced, $valInd);
				}
			}
		} while ($isBlockRestored);
	}

	/**
	 * @param string $val
	 * @return Datatype
	 */
	protected static function detectValType(string $val): Datatype {
		// numeric types
		if (is_numeric($val)) {
			$int = (int)$val;
			if ((string)$int !== $val) {
				return Datatype::Float;
			}

			if ($int > Datalim::MySqlMaxInt->value) {
				return Datatype::Bigint;
			}

			return Datatype::Int;
		}
		// json type
		if (substr($val, 0, 1) === '{' && substr($val, -1) === '}') {
			return Datatype::Json;
		}
		// mva types
		if (substr($val, 0, 1) === '(' && substr($val, -1) === ')') {
			$subVals = explode(',', substr($val, 1, -1));
			array_walk(
				$subVals,
				function (&$v) {
					$v = trim($v);
				}
			);
			foreach ($subVals as $v) {
				if (self::detectValType($v) === Datatype::Bigint) {
					return Datatype::Multi64;
				}
			}
			return Datatype::Multi;
		}

		return (self::isManticoreString($val) === true) ? Datatype::String : Datatype::Text;
	}

}
