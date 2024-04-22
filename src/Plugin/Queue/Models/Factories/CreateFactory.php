<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/


namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create\CreateMaterializedViewModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create\CreateSourceModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;

class CreateFactory {
	/**
	 * @template T of array
	 * @phpstan-param T $parsedPayload
	 * @phpstan-return Model<T>|null
	 */
	public static function create(array $parsedPayload): ?Model {

		$model = null;

		if (self::isCreateSourceMatch($parsedPayload)) {
			$model = new CreateSourceModel($parsedPayload);
		}

		if (self::isMaterializedViewMatch($parsedPayload)) {
			$model = new CreateMaterializedViewModel($parsedPayload);
		}

		/** @var Model<T>|null $model */
		return $model;
	}

	/**
	 *
	 *
	 * @param array{
	 *       CREATE?: array{
	 *           expr_type: string,
	 *           not-exists: bool,
	 *           base_expr: string,
	 *           sub_tree: array{
	 *               expr_type: string,
	 *               base_expr: string
	 *           }[]
	 *       },
	 *       SOURCE?: array{
	 *           base_expr: string,
	 *           name: string,
	 *           no_quotes?: array{
	 *               delim: bool,
	 *               parts: string[]
	 *           },
	 *           create-def?: array{
	 *               expr_type: string,
	 *               base_expr: string,
	 *               sub_tree: array{
	 *                   expr_type: string,
	 *                   base_expr: string,
	 *                   sub_tree: array{
	 *                       expr_type: string,
	 *                       base_expr: string,
	 *                       sub_tree: array{
	 *                           expr_type: string,
	 *                           base_expr: string
	 *                       }[]
	 *                   }[]
	 *               }[]
	 *           },
	 *           options?: array{
	 *               expr_type: string,
	 *               base_expr: string,
	 *               delim: string,
	 *               sub_tree: array{
	 *                   expr_type: string,
	 *                   base_expr: string,
	 *                   delim: string,
	 *                   sub_tree: array{
	 *                       expr_type: string,
	 *                       base_expr: string
	 *                   }[]
	 *               }[]
	 *           }[]
	 *       }
	 *   } $parsedPayload
	 * @return bool
	 */

	private static function isCreateSourceMatch(array $parsedPayload): bool {
		return (
			!empty($parsedPayload['SOURCE']['no_quotes']['parts'][0]) &&
			!empty($parsedPayload['SOURCE']['create-def']) &&
			!empty($parsedPayload['SOURCE']['options'])
		);
	}


	/**
	 * @param array{
	 *       CREATE?: array{
	 *           base_expr: string,
	 *           sub_tree: array{
	 *                expr_type: string,
	 *                base_expr: string
	 *            }[]
	 *       },
	 *       VIEW?: array{
	 *           base_expr: string,
	 *           name: string,
	 *           no_quotes: array{
	 *               delim: bool,
	 *               parts: string[]
	 *           },
	 *           create-def: bool,
	 *           options: bool,
	 *           to?: array{
	 *               expr_type: string,
	 *               table: string,
	 *               base_expr: string,
	 *               no_quotes: array{
	 *                   delim: bool,
	 *                   parts: string[]
	 *               }
	 *           }
	 *       },
	 *       SELECT?: array{
	 *           array{
	 *               expr_type: string,
	 *               alias: bool|array{
	 *                   as: bool,
	 *                   name: string,
	 *                   base_expr: string,
	 *                   no_quotes: array{
	 *                       delim: bool,
	 *                       parts: string[]
	 *                   }
	 *               },
	 *               base_expr: string,
	 *               no_quotes: array{
	 *                   delim: bool,
	 *                   parts: string[]
	 *               },
	 *               sub_tree: mixed,
	 *               delim: bool|string
	 *           }[]
	 *       },
	 *       FROM?: array{
	 *           array{
	 *               expr_type: string,
	 *               table: string,
	 *               no_quotes: array{
	 *                   delim: bool,
	 *                   parts: string[]
	 *               },
	 *               alias: bool,
	 *               hints: bool,
	 *               join_type: string,
	 *               ref_type: bool,
	 *               ref_clause: bool,
	 *               base_expr: string,
	 *               sub_tree: bool|array{}
	 *           }[]
	 *       }
	 *   } $parsedPayload
	 * @return bool
	 */
	private static function isMaterializedViewMatch(array $parsedPayload): bool {
		return (
			isset($parsedPayload['SELECT']) &&
			isset($parsedPayload['FROM']) &&
			!empty($parsedPayload['VIEW']['no_quotes']['parts'][0]) &&
			!empty($parsedPayload['VIEW']['to'])
		);
	}
}
