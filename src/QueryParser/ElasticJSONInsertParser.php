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

class ElasticJSONInsertParser extends JSONInsertParser {

	use \Manticoresearch\Buddy\Trait\CheckInsertDataTrait;

	/**
	* @param string $path
	* @return void
	*/
	public function __construct(string $path) {
		[$this->name, $this->id] = $this->parseQueryPath($path);
	}

	/**
	 * @param string $path
	 * @return array{0:string,1:?int}
	 */
	protected function parseQueryPath(string $path): array {
		$pathParts = explode('/', $path);
		$tableName = $pathParts[0];
		$rowId = isset($pathParts[2]) ? (int)$pathParts[2] : null;
		return [$tableName, $rowId];
	}

	/**
	 * @param array{index:string,id:int,doc:array<mixed>}|array<
	 * string, array{index:string,id:int,doc:array<mixed>}> $query
	 * @return array<mixed>
	 * @throws QueryParserError
	 */
	protected function extractRow(array $query): array {
		if (!is_array($query)) {
			throw new QueryParserError('Request must be an object');
		}
		if (empty($query)) {
			throw new QueryParserError('Request cannot be an empty object');
		}
		if ($this->id !== null) {
			$query['id'] = $this->id;
		}
		return $query;
	}

}
