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
	 * @var bool $isBulkQuery
	 */
	private $isBulkQuery = false;

	/**
	 * @var bool $isIndexInfoRow
	 */
	private $isIndexInfoRow = false;

	/**
	* @param string $path
	* @return void
	*/
	public function __construct(string $path) {
		[$this->name, $this->id, $this->isBulkQuery] = $this->parseQueryPath($path);
	}

	/**
	 * @param string $path
	 * @return array{0:string,1:?int, 2:bool}
	 */
	protected function parseQueryPath(string $path): array {
		$pathParts = explode('/', $path);
		if ($pathParts[0] === '_bulk') {
			$isBulkRequest = true;
			$tableName = '';
		} else {
			$isBulkRequest = false;
			$tableName = $pathParts[0];
		}
		$rowId = isset($pathParts[2]) ? (int)$pathParts[2] : null;
		return [$tableName, $rowId, $isBulkRequest];
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
		// When using the elastic-like format for bulk requests, table name is passed as the part of 'index' row info
		$this->isIndexInfoRow = $this->isBulkQuery ? !$this->isIndexInfoRow : false;
		if ($this->isIndexInfoRow && isset($query['index']['_index'])
			&& is_string($query['index']['_index'])) {
			$this->name = $query['index']['_index'];
		}
		// When using the elastic-like format, the 'id' field is optional so we omit it
		if (!$this->isIndexInfoRow && isset($query['id'])) {
			unset($query['id']);
		}

		return $this->isIndexInfoRow ? [] : $query;
	}

}
