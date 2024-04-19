<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Drop\DropMaterializedViewModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Drop\DropSourceModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;

class DropFactory
{
	/**
	 * @param array{
	 *     DROP: array{
	 *         expr_type: string,
	 *         option: bool,
	 *         if-exists: bool,
	 *         sub_tree: array{
	 *             array{
	 *                 expr_type: string,
	 *                 base_expr: string
	 *             },
	 *             array{
	 *                 expr_type: string,
	 *                 base_expr: string,
	 *                 sub_tree?: array{
	 *                      array{
	 *                       expr_type: string,
	 *                       table: string,
	 *                       no_quotes: array{
	 *                           delim: bool,
	 *                           parts: array<string>
	 *                       },
	 *                       alias: bool,
	 *                       base_expr: string,
	 *                       delim: bool
	 *                      }
	 *                  }
	 *             },
	 *             array{
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
	 *              }
	 *         }
	 *     }
	 * } $parsedPayload
	 * @return Model|null
	 */
	public static function create(array $parsedPayload): ?Model {
		$model = null;

		if (self::isDropSourceMatch($parsedPayload)) {
			$model = new DropSourceModel($parsedPayload);
		}

		if (self::isDropMaterializedViewMatch($parsedPayload)) {
			$model = new DropMaterializedViewModel($parsedPayload);
		}

		return $model;
	}

	/**
	 * Should match DROP SOURCE {name}
	 *
	 * @param array{
	 *      DROP: array{
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
	 *      DROP: array{
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
