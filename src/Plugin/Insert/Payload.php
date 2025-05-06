<?php declare(strict_types=1);

/*
 Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License version 3 or any later
 version. You should have received a copy of the GPL license along with this
 program; if you did not, you can find it at http://www.gnu.org/
 */

namespace Manticoresearch\Buddy\Base\Plugin\Insert;

use Manticoresearch\Buddy\Base\Plugin\Insert\QueryParser\Loader;
use Manticoresearch\Buddy\Core\ManticoreSearch\Endpoint as ManticoreEndpoint;
use Manticoresearch\Buddy\Core\ManticoreSearch\RequestFormat;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {

	const ELASTIC_LIKE_TABLE_OPTIONS = ["min_infix_len='2'"];

	/** @var array<string> */
	public array $queries = [];

	/** @var string $path */
	public string $path;

	/** @var bool $isElasticLikeInsert */
	public bool $isElasticLikeInsert = false;

	/**
	 * @return void
	 */
	public function __construct() {
	}

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Auto schema support. When an insert operation is performed'
			. ' and the table does not exist, it creates it with data types auto-detection';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$parser = Loader::getInsertQueryParser($request->path, $request->endpointBundle);
		$self->path = $request->path;
		if ($request->endpointBundle === ManticoreEndpoint::Bulk) {
			$self->path = '_bulk';
			$request->payload = trim($request->payload) . "\n";
		}
		['name' => $name, 'cols' => $cols, 'colTypes' => $colTypes] = $parser->parse($request->payload);
		$createTableQuery = $self->buildCreateTableQuery($name, $cols, $colTypes);
		if (Loader::isElasticLikeRequest($request->path, $request->endpointBundle)) {
			$createTableQuery .= ' ' . implode(' ', self::ELASTIC_LIKE_TABLE_OPTIONS);
		}
		// Handling uppercased table names in the request
		$payload = (strtolower($name) !== $name)
			? $self->preprocessUppercasedTableName($request->payload, $request->format)
			: $request->payload;
		$self->queries[] = $createTableQuery;

		// Preprocessing Elastic-like insert requests
		if (str_contains($self->path, '_doc') || str_contains($self->path, '_create')) {
			$insertQuery = self::preprocessElasticLikeRequest($self->path, $payload);
			$self->path = 'insert';
			$self->isElasticLikeInsert = true;
		} else {
			$insertQuery = $payload;
		}
		$self->queries[] = $insertQuery;

		return $self;
	}

	/**
	 * Replaces uppercased table names with lowercased ones to avoid errors on further insert requests
	 *
	 * @param string $payload
	 * @param RequestFormat $format
	 * @return string
	 */
	protected static function preprocessUppercasedTableName(string $payload, RequestFormat $format): string {
		return match ($format) {
			RequestFormat::SQL => (string)preg_replace_callback(
				'/^INSERT\s+INTO\s+(.+?)([\s\(])/is',
				function ($matches) {
					return 'INSERT INTO ' . strtolower($matches[1]) . $matches[2];
				},
				$payload,
				1,
			),
			default => (
				function ($payload) {
					/** @var array{table?:string,index?:string} */
					$payload = (array)simdjson_decode($payload, true);
					// Supporting both table and index options here
					if (isset($payload['table'])) {
						$payload['table'] = strtolower($payload['table']);
					} elseif (isset($payload['index'])) {
						$payload['index'] = strtolower($payload['index']);
					}
					return (string)json_encode($payload);
				}
			)($payload),
		};
	}

	/**
	 * @param string $path
	 * @param string $payload
	 * @return string
	 */
	protected static function preprocessElasticLikeRequest(string $path, string $payload): string {
		$pathParts = explode('/', $path);
		$table = $pathParts[0];
		$query = [
			'table' => $table,
			'doc' => (array)simdjson_decode($payload, true),
		];
		if (isset($pathParts[2]) && $pathParts[2]) {
			$query['id'] = (int)$pathParts[2];
		}

		return (string)json_encode($query);
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
					return "`$a` $b";
				},
				$cols,
				$colTypes
			)
		);
		$repls = ['%NAME%' => $name, '%COL_EXPR%' => $colExpr];
		return strtr('CREATE TABLE IF NOT EXISTS `%NAME%` (%COL_EXPR%)', $repls);
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$queryLowercase = strtolower($request->payload);

		// Making a bit of extra preprocessing to simplify following detection of the bulk insert query
		if ($request->endpointBundle === ManticoreEndpoint::Bulk) {
			$queryLowercase = ltrim(substr($queryLowercase, 1));
		}

		$isInsertSQLQuery = match ($request->endpointBundle) {
			ManticoreEndpoint::Sql, ManticoreEndpoint::Cli, ManticoreEndpoint::CliJson => str_starts_with(
				$queryLowercase, 'insert into'
			),
			default => false,
		};
		$isInsertHTTPQuery = match ($request->endpointBundle) {
			ManticoreEndpoint::Insert, ManticoreEndpoint::Replace => true,
			ManticoreEndpoint::Bulk => str_starts_with($queryLowercase, '"insert"')
				|| str_starts_with($queryLowercase, '"create"')
				|| str_starts_with($queryLowercase, '"index"'),
			default => false,
		};
		$isInsertError = str_contains($request->error, 'no such index')
			|| str_contains($request->error, 'bulk request must be terminated')
			|| str_contains($request->error, 'unsupported endpoint')
			|| (str_contains($request->error, 'table ') && str_contains($request->error, ' absent'))
			|| (str_contains($request->error, ' body ') && str_ends_with($request->error, ' is required'));

		return ($isInsertError && ($isInsertSQLQuery || $isInsertHTTPQuery));
	}
}
