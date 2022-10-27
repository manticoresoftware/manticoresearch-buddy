<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Interface\CustomErrorInterface;
use Manticoresearch\Buddy\Interface\QueryParserLocatorInterface;

class QueryParserLocator implements QueryParserLocatorInterface {

	use \Manticoresearch\Buddy\Trait\CustomErrorTrait;

	const PARSER_LOC = [
		'sphinqxl' => [
			'INSERT_QUERY' => 'SQLInsertParser',
		],
		'http' => [
			'INSERT_QUERY' => 'JSONInsertParser',
		],
	];

	/**
	 * @param CustomErrorInterface $exceptionHandler
	 * @return void
	 */
	public function __construct(
		protected CustomErrorInterface $exceptionHandler = null,
	) {
	}

	/**
	 * @param string $reqFormat
	 * @param string $queryType
	 * @return object
	 */
	public function getQueryParser(string $reqFormat, string $queryType): object {
		if (!array_key_exists($reqFormat, static::PARSER_LOC)) {
			$this->error("Unrecognized request format: $reqFormat");
		}
		if (!array_key_exists($queryType, static::PARSER_LOC[$reqFormat])) {
			$this->error("Unrecognized query type: $queryType");
		}
		$parserClass = __NAMESPACE__ . '\\' . static::PARSER_LOC[$reqFormat][$queryType];
		return new $parserClass();
	}

}
