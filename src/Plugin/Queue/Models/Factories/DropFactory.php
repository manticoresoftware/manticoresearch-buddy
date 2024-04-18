<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create\DropMaterializedViewModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create\DropSourceModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;

class DropFactory
{
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
	 *          expr_type: string,
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
	 *                  sub_tree: array{
	 *                      expr_type: string,
	 *                      base_expr: string,
	 *                      sub_tree: array{
	 *                          expr_type: string,
	 *                          table: string,
	 *                          no_quotes: array{
	 *                              delim: bool,
	 *                              parts: string[]
	 *                          },
	 *                          alias: bool,
	 *                          base_expr: string,
	 *                          delim: bool
	 *                      }[]
	 *                  }
	 *              }
	 *          }[]
	 *      }
	 *  } $parsedPayload
	 * @return bool
	 * @example {"DROP":{"expr_type":"source","option":false,"if-exists":false,
	 * "sub_tree":[{"expr_type":"reserved","base_expr":"source"},
	 * {"expr_type":"expression","base_expr":"kafka","sub_tree":[{"expr_type":"source","table":"kafka","no_quotes":
	 * {"delim":false,"parts":["kafka"]},"alias":false,"base_expr":"kafka","delim":false}]}]}}
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
	 *              array{
	 *                  expr_type: string,
	 *                  base_expr: string
	 *              },
	 *              array{
	 *                  expr_type: string,
	 *                  base_expr: string,
	 *                  sub_tree: array{
	 *                      expr_type: string,
	 *                      base_expr: string,
	 *                      sub_tree: array{
	 *                          expr_type: string,
	 *                          table: string,
	 *                          no_quotes: array{
	 *                              delim: bool,
	 *                              parts: string[]
	 *                          },
	 *                          alias: bool,
	 *                          base_expr: string,
	 *                          delim: bool
	 *                      }[]
	 *                  }
	 *              }
	 *          }[]
	 *      }
	 *  } $parsedPayload
	 * @return bool
	 * @example {"DROP":{"expr_type":"view","option":false,"if-exists":false,"sub_tree":[
	 * {"expr_type":"reserved","base_expr":"materialized"},
	 * {"expr_type":"reserved","base_expr":"view"}
	 * {"expr_type":"expression","base_expr":"view_table","sub_tree":[
	 * {"expr_type":"view","table":"view_table","no_quotes":{"delim":false,"parts":["view_table"]},
	 * "alias":false,"base_expr":"view_table","delim":false}]}]}}
	 */
	private static function isDropMaterializedViewMatch(array $parsedPayload): bool {
		return (
			isset($parsedPayload['DROP']['sub_tree'][0]) &&
			isset($parsedPayload['DROP']['sub_tree'][1]) &&
			!empty($parsedPayload['DROP']['sub_tree'][2]['sub_tree'][0]['no_quotes']['parts']) &&
			strtolower($parsedPayload['DROP']['sub_tree'][0]['base_expr']) === Payload::TYPE_MATERLIALIZED &&
			strtolower($parsedPayload['DROP']['sub_tree'][1]['base_expr']) === Payload::TYPE_VIEW
		);
	}
}
