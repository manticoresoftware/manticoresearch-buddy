<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/


namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Alter\AlterMaterializedViewModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;

class AlterFactory {
	/**
	 * @template T of array
	 * @phpstan-param T $parsedPayload
	 * @phpstan-return Model<T>|null
	 */
	public static function create(array $parsedPayload): ?Model {

		$model = null;

		if (self::isAlterMaterializedViewMatch($parsedPayload)) {
			$model = new AlterMaterializedViewModel($parsedPayload);
		}

		/** @var Model<T>|null $model */
		return $model;
	}


	/**
	 * Should match ALTER MATERIALIZED VIEW {name} suspended=0;
	 *
	 * @param array{
	 *      ALTER?: array{
	 *          base_expr: string,
	 *          sub_tree: mixed[]
	 *      },
	 *      VIEW?: array{
	 *          base_expr: string,
	 *          name: string,
	 *          no_quotes: array{
	 *              delim: bool,
	 *              parts: string[]
	 *          },
	 *          create-def: bool,
	 *          options: array{
	 *              expr_type: string,
	 *              base_expr: string,
	 *              delim: string,
	 *              sub_tree: array{
	 *                  expr_type: string,
	 *                  base_expr: string,
	 *                  delim: string,
	 *                  sub_tree: array{
	 *                      expr_type: string,
	 *                      base_expr: string
	 *                  }[]
	 *              }[]
	 *          }[]
	 *      }
	 *  } $parsedPayload
	 * @return bool
	 *
	 */
	private static function isAlterMaterializedViewMatch(array $parsedPayload): bool {
		return (
			isset($parsedPayload['ALTER']['base_expr']) &&
			!empty($parsedPayload['VIEW']['no_quotes']['parts']) &&
			!empty($parsedPayload['VIEW']['options']) &&
			strtolower($parsedPayload['ALTER']['base_expr']) === Payload::TYPE_MATERLIALIZED . ' ' . Payload::TYPE_VIEW
		);
	}
}
