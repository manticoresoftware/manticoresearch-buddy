<?php declare(strict_types=1);

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Create\CreateSourceModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;

class AlterFactory
{
	public static function create(array $parsedPayload): ?Model {

		$model = null;

		if (self::isAlterMaterializedViewMatch($parsedPayload)) {
			$model = new CreateSourceModel($parsedPayload);
		}


		return $model;
	}


	/**
	 * Should match ALTER MATERIALIZED VIEW {name} suspended=0;
	 *
	 * @param array{
	 *      ALTER: array{
	 *          base_expr: string,
	 *          sub_tree: mixed[]
	 *      },
	 *      VIEW: array{
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
	 * @example {"ALTER":{"base_expr":"materialized view","sub_tree":[]},"VIEW":{"base_expr":"view_table",
	 * "name":"view_table","no_quotes":{"delim":false,"parts":["view_table"]},"create-def":false,
	 * "options":[{"expr_type":"expression","base_expr":"suspended=0","delim":" ","sub_tree":[{"expr_type":
	 * "reserved","base_expr":"suspended"},{"expr_type":"operator","base_expr":"="},
	 * {"expr_type":"const","base_expr":"0"}]}]}}
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
