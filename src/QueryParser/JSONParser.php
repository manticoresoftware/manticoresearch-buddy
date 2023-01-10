<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\QueryParser;

use Manticoresearch\Buddy\Exception\QueryParserError;
use Manticoresearch\Buddy\Interface\JSONParserInterface;

abstract class JSONParser extends BaseParser implements JSONParserInterface {

	/**
	 * @var bool $isNdJSON
	 */
	protected bool $isNdJSON = false;

	/**
	 * @param string $query
	 * @return array{name:string}
	 */
	public function parse($query): array {
		$this->cols = $this->colTypes = [];
		$row = json_decode($query, true);
		if (!is_array($row)) {
			// checking if query has ndjson format
			$queries = static::parseNdJSON($query);
			foreach ($queries as $query) {
				$row = json_decode($query, true);
				if (!is_array($row)) {
					throw new QueryParserError('Invalid JSON in query');
				}
				$this->isNdJSON = true;
				$this->parseJSONRow($row);
			}
		} else {
			$this->parseJSONRow($row);
		}

		if ($this->error !== '') {
			throw new QueryParserError($this->error);
		}
		return ['name' => $this->name];
	}

	/**
	 * @param string $query
	 * @return Iterable<string>
	 */
	public static function parseNdJSON($query): Iterable {
		do {
			$eolPos = strpos($query, PHP_EOL);
			if ($eolPos === false) {
				$eolPos = strlen($query);
			}
			$row = substr($query, 0, $eolPos);
			if ($row !== '') {
				yield $row;
			}
			$query = substr($query, $eolPos + 1);
		} while (strlen($query) > 0);
	}

}
