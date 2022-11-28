<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Enum\ManticoreEndpoint;
use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Lib\QueryParserLoader;
use Manticoresearch\Buddy\Network\Request;

class InsertQueryRequest  {
	/** @var array<string> */
	public array $queries = [];

	/**
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * @param Request $request
	 * @return InsertQueryRequest
	 */
	public static function fromNetworkRequest(Request $request): InsertQueryRequest {
		$self = new self();
		// Resolve the possible ambiguity with Manticore query format as it may not correspond to request format
		$queryFormat = in_array($request->endpoint, [ManticoreEndpoint::Cli, ManticoreEndpoint::Sql])
			? RequestFormat::SQL : RequestFormat::JSON;
		$parser = QueryParserLoader::getInsertQueryParser($queryFormat);
		$self->queries[] = $self->buildCreateTableQuery(...$parser->parse($request->query));
		$self->queries[] = $request->query;
		return $self;
	}

	/**
	 * @param string $name
	 * @param array<string> $cols
	 * @param array<string> $colTypes
	 * @return string
	 */
	protected static function buildCreateTableQuery(string $name, array $cols, array $colTypes): string {
		$colExpr = implode(
			',',
			array_map(
				function ($a, $b) {
					return "$a $b";
				},
				$cols,
				$colTypes
			)
		);
		$repls = ['%NAME%' => $name, '%COL_EXPR%' => $colExpr];
		return strtr('CREATE TABLE IF NOT EXISTS %NAME% (%COL_EXPR%)', $repls);
	}
}