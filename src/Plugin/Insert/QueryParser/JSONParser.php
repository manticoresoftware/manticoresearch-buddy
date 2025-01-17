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
use Manticoresearch\Buddy\Core\Network\Struct;

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
		$isNdJson = !Struct::isValid($query);
		if ($isNdJson) {
			// checking if query has ndjson format
			$queries = static::parseNdJSON($query);
			foreach ($queries as $query) {
				$struct = Struct::fromJson($query);
				$row = $struct->toArray();
				if (!$row || !is_array($row)) {
					throw new QueryParseError('Invalid JSON in query');
				}
				$this->isNdJSON = true;
				$this->parseJSONRow($row);
			}
		} else {
			$struct = Struct::fromJson($query);
			$row = $struct->toArray();
			$this->parseJSONRow($row);
		}

		if ($this->error !== '') {
			throw new QueryParseError($this->error);
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
