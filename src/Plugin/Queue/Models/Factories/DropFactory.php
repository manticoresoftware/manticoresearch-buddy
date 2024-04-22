<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/


namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Drop\DropMaterializedViewModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Drop\DropSourceModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;

class DropFactory {
	/**
	 * @template T of array
	 * @phpstan-param T $parsedPayload
	 * @phpstan-return Model<T>|null
	 */
	public static function create(array $parsedPayload): ?Model {
		$model = null;

		if (self::isDropSourceMatch($parsedPayload)) {
			$model = new DropSourceModel($parsedPayload);
		}

		if (self::isDropMaterializedViewMatch($parsedPayload)) {
			$model = new DropMaterializedViewModel($parsedPayload);
		}

		/** @var Model<T>|null $model */
		return $model;
	}

	/**
	 * Should match DROP SOURCE {name}
	 *
	 * @param array{
	 *      DROP?: array{
	 *          expr_type?: string,
	 *          option: bool,
	 *          if-exists: bool,
	 *          sub_tree: array{
	 *              array{
	 *                  expr_type: string,
	 *                  base_expr: string
	 *              },
	 *              array{
	 *                  expr_type: string,
	 *                  base_expr: string,
	 *                  sub_tree?: array{
	 *                     array{
	 *                       expr_type: string,
	 *                       table: string,
	 *                       no_quotes: array{
	 *                           delim: bool,
	 *                           parts: array<string>
	 *                       },
	 *                       alias: bool,
	 *                       base_expr: string,
	 *                       delim: bool
	 *                     }
	 *                  }
	 *              }
	 *          }
	 *      }
	 *  } $parsedPayload
	 * @return bool
	 */
	private static function isDropSourceMatch(array $parsedPayload): bool {
		return (
			isset($parsedPayload['DROP']['expr_type']) &&
			isset($parsedPayload['DROP']['sub_tree'][1]['sub_tree'][0]['no_quotes']['parts'][0]) &&
			strtolower($parsedPayload['DROP']['expr_type']) === Payload::TYPE_SOURCE
		);
	}

	/**
	 * Should match DROP MATERIALIZED VIEW {name}
	 *
	 * @param array{
	 *      DROP?: array{
	 *          expr_type: string,
	 *          option: bool,
	 *          if-exists: bool,
	 *          sub_tree: array{
	 *              ?array{
	 *                  expr_type: string,
	 *                  base_expr: string
	 *              },
	 *              ?array{
	 *                  expr_type: string,
	 *                  base_expr: string,
	 *                  sub_tree?: array{
	 *                       array{
	 *                        expr_type: string,
	 *                        table: string,
	 *                        no_quotes: array{
	 *                            delim: bool,
	 *                            parts: array<string>
	 *                        },
	 *                        alias: bool,
	 *                        base_expr: string,
	 *                        delim: bool
	 *                       }
	 *                   }
	 *              },
	 *              ?array{
	 *                   expr_type: string,
	 *                   base_expr: string,
	 *                   sub_tree?: array{
	 *                        array{
	 *                         expr_type: string,
	 *                         table: string,
	 *                         no_quotes: array{
	 *                             delim: bool,
	 *                             parts: array<string>
	 *                         },
	 *                         alias: bool,
	 *                         base_expr: string,
	 *                         delim: bool
	 *                        }
	 *                    }
	 *               }
	 *          }
	 *      }
	 *  } $parsedPayload
	 * @return bool
	 */
	private static function isDropMaterializedViewMatch(array $parsedPayload): bool {
		return (
			isset($parsedPayload['DROP']['sub_tree'][0]) &&
			isset($parsedPayload['DROP']['sub_tree'][1]) &&
			isset($parsedPayload['DROP']['sub_tree'][2]['sub_tree'][0]['no_quotes']['parts'][0]) &&
			strtolower($parsedPayload['DROP']['sub_tree'][0]['base_expr']) === Payload::TYPE_MATERLIALIZED &&
			strtolower($parsedPayload['DROP']['sub_tree'][1]['base_expr']) === Payload::TYPE_VIEW
		);
	}
}
