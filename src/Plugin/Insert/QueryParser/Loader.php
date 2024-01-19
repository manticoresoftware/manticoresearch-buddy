<?php declare(strict_types=1);

/*
 Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 2 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser;

use Manticoresearch\Buddy\Base\Plugin\Insert\Error\ParserLoadError;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;

class Loader {

	/**
	 * @param string $reqPath
	 * @param ManticoreEndpoint $reqEndpointBundle
	 * @return InsertQueryParserInterface
	 */
	public static function getInsertQueryParser(
		string $reqPath,
		ManticoreEndpoint $reqEndpointBundle
	): InsertQueryParserInterface {
		// Resolve the possible ambiguity with Manticore query format as it may not correspond to request format
		$reqFormat = match ($reqEndpointBundle) {
			ManticoreEndpoint::Cli, ManticoreEndpoint::CliJson, ManticoreEndpoint::Sql => RequestFormat::SQL,
			ManticoreEndpoint::Insert, ManticoreEndpoint::Bulk => RequestFormat::JSON,
			default => throw new ParserLoadError("Unsupported endpoint bundle '{$reqEndpointBundle->value}' passed"),
		};
		$parserClass = match ($reqFormat) {
			RequestFormat::SQL => 'SQLInsertParser',
			RequestFormat::JSON => ($reqEndpointBundle->value === $reqPath)
				? 'JSONInsertParser'
				: 'ElasticJSONInsertParser',
		};
		$parserClassFull = __NAMESPACE__ . '\\' . $parserClass;
		$parser = ($parserClassFull === __NAMESPACE__ . '\ElasticJSONInsertParser')
			? new $parserClassFull($reqPath)
			: new $parserClassFull();
		if ($parser instanceof InsertQueryParserInterface) {
			return $parser;
		}
	}

}
