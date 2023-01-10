<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\QueryParser;

use Manticoresearch\Buddy\Enum\RequestFormat;
use Manticoresearch\Buddy\Exception\ParserLoadError;
use Manticoresearch\Buddy\Interface\InsertQueryParserInterface;

class Loader {

	/**
	 * @param RequestFormat $reqFormat
	 * @return InsertQueryParserInterface
	 */
	public static function getInsertQueryParser(RequestFormat $reqFormat): InsertQueryParserInterface {
		$parserClass = match ($reqFormat) {
			RequestFormat::SQL => 'SQLInsertParser',
			RequestFormat::JSON => 'JSONInsertParser',
			default => throw new ParserLoadError("Unrecognized request format '{$reqFormat->value}' passed"),
		};
		$parserClass = __NAMESPACE__ . '\\' . $parserClass;
		$parser = new $parserClass();
		if ($parser instanceof InsertQueryParserInterface) {
			return $parser;
		}
	}

}
