<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\InsertQuery;

use Manticoresearch\Buddy\Base\CommandRequestBase;
use Manticoresearch\Buddy\Network\Request as NetRequest;
use Manticoresearch\Buddy\QueryParser\Loader;

final class Request extends CommandRequestBase {
	/** @var array<string> */
	public array $queries = [];

	/** @var string $endpoint */
	public string $endpoint;

	/**
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * @param NetRequest $request
	 * @return self
	 */
	public static function fromNetworkRequest(NetRequest $request): self {
		$self = new self();
		$parser = Loader::getInsertQueryParser($request->path, $request->endpointBundle);
		$self->queries[] = $self->buildCreateTableQuery(...$parser->parse($request->payload));
		$self->queries[] = $request->payload;
		$self->endpoint = $request->path;
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
