<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\AlterDistributedTable;

use Manticoresearch\Buddy\Core\Error\GenericError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload
{
	public string $table;

	/**
	 * @var array<array<string, string>>
	 */
	public array $options;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Enables alter for distributed tables';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		/** phpstan error fix */
		unset($request);
		$self = new static();

		/**
* @phpstan-var array{
		 *       ALTER: array{
		 *           expr_type: string,
		 *           base_expr: string,
		 *           sub_tree: array<array{
		 *               expr_type: string,
		 *               base_expr: string
		 *           }>
		 *       },
		 *       TABLE: array{
		 *           base_expr: string,
		 *           name: string,
		 *           no_quotes: array{
		 *               delim: bool,
		 *               parts: array<string>
		 *           },
		 *           create-def: bool,
		 *           options: array<array{
		 *               expr_type: string,
		 *               base_expr: string,
		 *               delim: string,
		 *               sub_tree: array<array{
		 *                   expr_type: string,
		 *                   base_expr: string
		 *               }>
		 *           }>
		 *       }
		 *   } $parsedPayload
		 */
		$parsedPayload = static::$sqlQueryParser::getParsedPayload();


		$self->table = $parsedPayload['TABLE']['no_quotes']['parts'][0];


		foreach ($parsedPayload['TABLE']['options'] as $option) {
			if (!isset($option['sub_tree'][0]) || !isset($option['sub_tree'][2])
				|| !in_array(strtolower($option['sub_tree'][0]['base_expr']), ['local', 'agent'])
			) {
				continue;
			}

			$self->options[] = [
				strtolower($option['sub_tree'][0]['base_expr']) => $option['sub_tree'][2]['base_expr'],
			];
		}


		return $self;
	}


	/**
	 * @param Request $request
	 * @return bool
	 * @throws GenericError
	 */
	public static function hasMatch(Request $request): bool {

		$payload = self::getParsedPayload($request);

		if (!$payload) {
			return false;
		}

		if (isset($payload['ALTER'])
			&& isset($payload['TABLE']['no_quotes']['parts'][0])
			&& isset($payload['TABLE']['options'][0]['sub_tree'][0]['base_expr'])
			&& isset($payload['TABLE']['options'][0]['sub_tree'][2]['base_expr'])
			&& in_array(
				strtolower($payload['TABLE']['options'][0]['sub_tree'][0]['base_expr']), ['local', 'agent']
			)
		) {
			return true;
		}

		return false;
	}

	/**
	 * Small preprocessing. Allow us to not call hard $sqlQueryParser::parse method
	 *
	 * @param Request $request
	 * @return array{
	 *      ALTER?: array{
	 *          expr_type: string,
	 *          base_expr: string,
	 *          sub_tree: array<array{
	 *              expr_type: string,
	 *              base_expr: string
	 *          }>
	 *      },
	 *      TABLE?: array{
	 *          base_expr: string,
	 *          name: string,
	 *          no_quotes: array{
	 *              delim: bool,
	 *              parts: array<string>
	 *          },
	 *          create-def: bool,
	 *          options: array<array{
	 *              expr_type: string,
	 *              base_expr: string,
	 *              delim: string,
	 *              sub_tree: array<array{
	 *                  expr_type: string,
	 *                  base_expr: string
	 *              }>
	 *          }>
	 *      }
	 *  }|null
	 * @throws GenericError
	 */
	private static function getParsedPayload(Request $request): ?array {
		return static::$sqlQueryParser::parse(
			$request->payload,
			fn(Request $request) => (
				str_contains($request->error, 'is not found, or not real-time')
				&& stripos($request->payload, 'alter') !== false
				&& stripos($request->payload, 'table') !== false
				&& (stripos($request->payload, 'local') !== false
					|| stripos($request->payload, 'agent') !== false)
			),
			$request
		);
	}

}
