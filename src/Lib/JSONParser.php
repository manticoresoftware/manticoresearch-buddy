<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Interface\JSONParserInterface;
use Manticoresearch\Buddy\Lib\QueryParser;
use Throwable;

abstract class JSONParser extends QueryParser implements JSONParserInterface {

	use \Manticoresearch\Buddy\Trait\CustomErrorTrait;
	use \Manticoresearch\Buddy\Trait\NDJSONTrait;

	/**
	 * @param string $query
	 * @return array{data?:array<mixed>,error?:string}
	 */
	public function parse($query): array {
		$row = json_decode($query);
		if ($row === null) {
			// checking if query has ndjson format
			try {
				$queries = static::parseNDJSON($query);
				foreach ($queries as $query) {
					$row = json_decode($query);
					if ($row === null) {
						return ['error' => 'Unvalid JSON in query'];
					}
					$this->parseJSONRow((array)$row);
				}
			} catch (Throwable $e) {
				$this->error($e->getMessage());
			}
		} else {
			$this->parseJSONRow((array)$row);
		}
		return ['data' => ['name' => $this->name, 'cols' => $this->cols, 'colTypes' => $this->colTypes] ];
	}

}
