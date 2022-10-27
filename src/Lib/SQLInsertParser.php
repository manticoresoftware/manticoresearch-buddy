<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Iterable;
use Manticoresearch\Buddy\Enum\DATALIM;
use Manticoresearch\Buddy\Enum\DATATYPE;
use Manticoresearch\Buddy\Interface\CheckInsertInterface;
use Manticoresearch\Buddy\Interface\QueryParserInterface;
use Manticoresearch\Buddy\Lib\QueryParser;
use RuntimeException;

class SQLInsertParser extends QueryParser implements CheckInsertInterface, QueryParserInterface {

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
	 * @return array{data?:array{name:string,cols:array<string>,colTypes:array<string>},error?:string}
	 */
	public function parse($query): array {
		$matches = [];
		preg_match_all('/\s*INSERT\s+INTO\s+(.*?)\s*\((.*?)\)\s+VALUES\s*(.*?)\s*;?\s*$/i', $query, $matches);
		$name = $matches[1][0];
		$colExpr = $matches[2][0];
		$this->cols = explode(',', $colExpr);
		$valExpr = $matches[3][0];

		$rows = $this->parseInsertRows($valExpr);

		foreach ($rows as $row) {
			$this->checkInsertRow($row);
			if ($this->error !== '') {
				return ['error' => $this->error];
			}
		}

		return ['data' => ['name' => $name, 'cols' => $this->cols, 'colTypes' => $this->colTypes]];
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
	 * @param mixed $insertRow
	 * @return array<string>
	 */
	protected function parseInsertValues(mixed $insertRow): array {
		$this->insertVals = $this->blocksReplaced = [];
		$insertRow = strval($insertRow);
		$this->replaceCommaBlocks($insertRow);
		if (!empty($this->blocksReplaced)) {
			$this->insertVals = explode(',', $insertRow);
			$this->restoreCommaBlocks();
			foreach ($this->insertVals as $i => $val) {
				$this->insertVals[$i] = trim($val);
			}
		}

		return $this->insertVals;
	}

	/**
	 * Temporarily replacing column values that can contain commas to tokens
	 * before splitting expression
	 *
	 * @param string $row
	 * @return array<array<string>>
	 */
	protected function replaceCommaBlocks(string $row): array {
		$this->blocksReplaced = [];
		$replInfo = [
			[ '{', '}' ],
			[ '\(', '\)' ],
			[ "'", "'" ],
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
				if ($row === null) {
					throw new RuntimeException('Comma replace error');
				}
				$this->blocksReplaced[$repl] = $m;
			}
		}

		return $this->blocksReplaced;
	}

	/**
	 * @param string $replaced
	 * @param int $valInd
	 * @return bool
	 */
	protected function restoreSingleCommaBlock(string $replaced, int $valInd): bool {
		if (strpos($this->insertVals[$valInd], $replaced) !== false) {
			$restored = array_shift($this->blocksReplaced[$replaced]);
			if ($restored !== null) {
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
	 * @return DATATYPE
	 */
	protected static function detectValType(string $val): DATATYPE {
		// numeric types
		if (is_numeric($val)) {
			$int = (int)$val;
			if ((string)$int !== $val) {
				return DATATYPE::T_FLOAT;
			}

			if ($int > DATALIM::MYSQL_MAX_INT->value) {
				return DATATYPE::T_BIGINT;
			}

			return DATATYPE::T_INT;
		}
		// json type
		if (substr($val, 0, 1) === '{' && substr($val, -1) === '}') {
			return DATATYPE::T_JSON;
		}
		// mva types
		if (substr($val, 0, 1) === '(' && substr($val, -1) === ')') {
			$subVals = explode(',', substr($val, 1, -1));
			array_walk(
				$subVals,
				function (&$v) {
					return trim($v);
				}
			);
			foreach ($subVals as $v) {
				if (self::detectValType($v) === DATATYPE::T_BIGINT) {
					return DATATYPE::T_MULTI_64;
				}
			}
			return DATATYPE::T_MULTI;
		}
		// determining if type is text or string, using Elastic's logic
		$regexes = [
			// so far only email regex is implemented for the prototype
			'email' => '/^\s*(?:[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+'
			. '(?:\.[a-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*|"'
			. '(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|'
			. '\\[\x01-\x09\x0b\x0c\x0e-\x7f])*")\\\@'
			. '(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+'
			. '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:(2(5[0-5]|[0-4][0-9])|1[0-9][0-9]|[1-9]?[0-9]))\.){3}'
			. '(?:(2(5[0-5]|[0-4][0-9])|1[0-9][0-9]|[1-9]?[0-9])|[a-z0-9-]*'
			. '[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|'
			. '\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)\])\s*$/i',
		];
		foreach ($regexes as $r) {
			if (preg_match($r, substr($val, 0, -1))) {
				return DATATYPE::T_STRING;
			}
		}

		return DATATYPE::T_TEXT;
	}

}
