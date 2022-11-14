<?php declare(strict_types=1);

/*
 Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Lib;

use Manticoresearch\Buddy\Enum\Datatype;
use Manticoresearch\Buddy\Interface\QueryParserInterface;

abstract class QueryParser implements QueryParserInterface {

	/**
	 * @var string $name
	 */
	protected string $name = '';
	/**
	 * @var array<string> $cols
	 */
	protected array $cols = [];
	/**
	 * @var array<Datatype> $colTypes
	 */
	protected array $colTypes = [];
	/**
	 * @var array<mixed> $rows
	 */
	protected array $rows = [];
	/**
	 * @var string $name
	 */
	protected string $error = '';

}
