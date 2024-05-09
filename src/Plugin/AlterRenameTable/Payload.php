<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)
  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\AlterRenameTable;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload {
	public string $path;

	public string $destinationTableName;
	public string $sourceTableName;

	public string $type;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables alter table rename';
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
		 *           ALTER: array{
		 *               expr_type: string,
		 *               base_expr: string,
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
		 *           RENAME: array{
		 *               expr_type: string,
		 *               sub_tree: array<
		 *               array{
		 *                   destination: array{
		 *                       expr_type: string,
		 *                       table: string,
		 *                       no_quotes: array{
		 *                           delim: bool,
		 *                           parts: array<string>
		 *                       },
		 *                       base_expr: string
		 *                   }
		 *               }>
		 *           }
		 *       } $payload
		 */
		$payload = Payload::$sqlQueryParser::getParsedPayload();

		$self->destinationTableName = $payload['RENAME']['sub_tree'][0]['destination']['no_quotes']['parts'][0];
		$self->sourceTableName = $payload['TABLE']['no_quotes']['parts'][0];
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
		 *           TABLE?: array{
		 *               base_expr: string,
		 *               name: string,
		 *               no_quotes: array{
		 *                   delim: bool,
		 *                   parts: array<string>
		 *               },
		 *               create-def: bool,
		 *               options: bool
		 *           },
		 *           RENAME?: array{
		 *               expr_type: string,
		 *               sub_tree: array<
		 *               array{
		 *                   destination: array{
		 *                       expr_type: string,
		 *                       table: string,
		 *                       no_quotes: array{
		 *                           delim: bool,
		 *                           parts: array<string>
		 *                       },
		 *                       base_expr: string
		 *                   }
		 *               }>
		 *           }
		 *       }|false $payload
		 */

		$payload = Payload::$sqlQueryParser::parse(
			$request->payload,
			fn(Request $request) => (
				strpos($request->error, 'P03: syntax error, unexpected tablename') === 0
				&& stripos($request->payload, 'alter') !== false
				&& stripos($request->payload, 'table') !== false
				&& stripos($request->payload, 'rename') !== false
			),
			$request
		);

		if (!$payload) {
			return false;
		}

		if (isset($payload['ALTER']['base_expr'])
			&& isset($payload['TABLE']['no_quotes']['parts'][0])
			&& isset($payload['RENAME']['sub_tree'][0]['destination']['no_quotes']['parts'][0])
			&& $payload['ALTER']['base_expr'] === 'TABLE'
		) {
			return true;
		}

		return false;
	}
}
