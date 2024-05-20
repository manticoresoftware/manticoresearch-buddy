<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\AlterColumn;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload {

	public string $destinationTableName;

	public string $columnName;
	public string $columnDatatype;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables adding/dropping table fields(columns)';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		/** phpstan fix */
		unset($request);
		/**
		 * @phpstan-var array{
		 *           TABLE: array{
		 *               base_expr: string,
		 *               name: string,
		 *               no_quotes: array{
		 *                   delim: bool,
		 *                   parts: array<string>
		 *               },
		 *               create-def: bool,
		 *               options: bool
		 *           },
		 *           ADD?: array{
		 *               expr_type: string,
		 *               sub_tree: array<
		 *               array{
		 *                   sub_tree: array<
		 *                   array{
		 *                       base_expr: string
		 *                   }>
		 *               }>
		 *           },
		 *           DROP?: array{
		 *               expr_type: string,
		 *               sub_tree: array<
		 *               array{
		 *                   sub_tree: array<
		 *                   array{
		 *                       base_expr: string
		 *                   }>
		 *               }>
		 *           }
		 *       } $payload
		 */
		$payload = Payload::$sqlQueryParser::getParsedPayload();

		$self->destinationTableName = (string)array_pop($payload['TABLE']['no_quotes']['parts']);
		if (isset($payload['ADD'])) {
			$self->columnName = $payload['ADD']['sub_tree'][0]['sub_tree'][0]['base_expr'];
			$self->columnDatatype = $payload['ADD']['sub_tree'][0]['sub_tree'][1]['base_expr'];
		} elseif (isset($payload['DROP'])) {
			$self->columnName = $payload['DROP']['sub_tree'][0]['sub_tree'][1]['base_expr'];
		}

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 * @throws GenericError
	 */
	public static function hasMatch(Request $request): bool {
		/**
		 * @phpstan-var array{
		 *           ALTER: array{
		 *               expr_type: string,
		 *               base_expr?: string,
		 *               sub_tree: array<
		 *               array{
		 *                   expr_type: string,
		 *                   base_expr: string
		 *               }>
		 *           },
		 *           TABLE: array{
		 *               base_expr: string,
		 *               name: string,
		 *               no_quotes: array{
		 *                   delim: bool,
		 *                   parts: array<string>
		 *               },
		 *               create-def: bool,
		 *               options: bool
		 *           },
		 *           ADD?: array{
		 *               expr_type: string,
		 *               sub_tree: array<
		 *               array{
		 *                   sub_tree: array<
		 *                   array{
		 *                       base_expr: string
		 *                   }>
		 *               }>
		 *           },
		 *           DROP?: array{
		 *               expr_type: string,
		 *               sub_tree: array<
		 *               array{
		 *                   sub_tree: array<
		 *                   array{
		 *                       base_expr: string
		 *                   }>
		 *               }>
		 *           }
		 *       }|false $payload
		 */
		$payload = Payload::$sqlQueryParser::parse(
			$request->payload,
			fn(Request $request) => (
				(stripos($request->payload, 'add') !== false || stripos($request->payload, 'drop') !== false)
				&& strpos($request->error, "P01: syntax error, unexpected identifier near 'ALTER TABLE") === 0
			),
			$request
		);

		return ($payload && isset($payload['ALTER']['base_expr'], $payload['TABLE']['no_quotes']['parts'][0])
			&& $payload['ALTER']['base_expr'] === 'TABLE'
		);
	}

}
