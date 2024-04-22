<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/


namespace Manticoresearch\Buddy\Base\Plugin\Queue\Models\Factories;

use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Model;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Show\ShowMaterializedViewModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Show\ShowMaterializedViewsModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Show\ShowSourceModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Models\Show\ShowSourcesModel;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;

class ShowFactory {

	/**
	 * @template T of array
	 * @phpstan-param T $parsedPayload
	 * @phpstan-return Model<T>|null
	 */

	public static function create(array $parsedPayload): ?Model {

		$model = null;

		if (self::isViewMaterializedViewsMatch($parsedPayload)) {
			$model = new ShowMaterializedViewsModel($parsedPayload);
		}

		if (self::isGetMaterializedViewMatch($parsedPayload)) {
			$model = new ShowMaterializedViewModel($parsedPayload);
		}

		if (self::isViewSourcesMatch($parsedPayload)) {
			$model = new ShowSourcesModel($parsedPayload);
		}

		if (self::isGetSourceMatch($parsedPayload)) {
			$model = new ShowSourceModel($parsedPayload);
		}

		/** @var Model<T>|null $model */
		return $model;
	}


	/**
	 * Should match SHOW MATERIALIZED VIEWS
	 *
	 * @param array{
	 *      SHOW?: array{
	 *          0: array{
	 *              expr_type: string,
	 *              base_expr?: string
	 *          },
	 *          1: array{
	 *              expr_type: string,
	 *              base_expr?: string
	 *          }
	 *      }
	 *  } $parsedPayload
	 * @return bool
	 * @example {"SHOW":[{"expr_type":"reserved","base_expr":"materialized"},{"expr_type":"reserved","base_expr":"views"}]}
	 */
	private static function isViewMaterializedViewsMatch(array $parsedPayload): bool {
		return (isset($parsedPayload['SHOW'][0]['base_expr']) &&
			isset($parsedPayload['SHOW'][1]['base_expr']) &&
			strtolower($parsedPayload['SHOW'][0]['base_expr']) === Payload::TYPE_MATERLIALIZED &&
			strtolower($parsedPayload['SHOW'][1]['base_expr']) === Payload::TYPE_VIEWS);
	}


	/**
	 * Should match SHOW MATERIALIZED VIEW {name}
	 *
	 * @param array{
	 *      SHOW?: array{
	 *          0: array{
	 *              expr_type: string,
	 *              base_expr?: string
	 *          },
	 *          1: array{
	 *              expr_type: string,
	 *              base_expr?: string
	 *          },
	 *          2: array{
	 *              expr_type: string,
	 *              view: string,
	 *              no_quotes: array{
	 *                  delim: bool,
	 *                  parts: array<string>
	 *              },
	 *              base_expr: string
	 *          }
	 *      }
	 *  } $parsedPayload
	 * @return bool
	 * @example {"SHOW":[{"expr_type":"reserved","base_expr":"materialized"},{"expr_type":"reserved","base_expr":"view"},
	 * {"expr_type":"view","view":"view_table","no_quotes":{"delim":false,"parts":["view_table"]},
	 * "base_expr":"view_table"}]}
	 */
	private static function isGetMaterializedViewMatch(array $parsedPayload): bool {
		return (isset($parsedPayload['SHOW'][0]['base_expr']) &&
			isset($parsedPayload['SHOW'][1]['base_expr']) &&
			!empty($parsedPayload['SHOW'][2]['no_quotes']['parts'][0]) &&
			strtolower($parsedPayload['SHOW'][0]['base_expr']) === Payload::TYPE_MATERLIALIZED &&
			strtolower($parsedPayload['SHOW'][1]['base_expr']) === Payload::TYPE_VIEW);
	}


	/**
	 * Should match SHOW SOURCES
	 *
	 * @param array{
	 *      SHOW?: array{
	 *          0: array{
	 *              expr_type: string,
	 *              base_expr?: string
	 *          }
	 *      }
	 *  } $parsedPayload
	 * @return bool
	 * @example {"SHOW":[{"expr_type":"reserved","base_expr":"sources"}]}
	 */
	private static function isViewSourcesMatch(array $parsedPayload): bool {
		return (isset($parsedPayload['SHOW'][0]['base_expr']) &&
			strtolower($parsedPayload['SHOW'][0]['base_expr']) === Payload::TYPE_SOURCES);
	}

	/**
	 * Should match SHOW SOURCE {name}
	 *
	 * @param array{
	 *      SHOW?: array{
	 *          0: array{
	 *              expr_type: string,
	 *              base_expr?: string
	 *          },
	 *          1: array{
	 *              expr_type: string,
	 *              view?: string,
	 *              no_quotes?: array{
	 *                  delim: bool,
	 *                  parts: array<string>
	 *              },
	 *              base_expr?: string
	 *          },
	 *          2: array{
	 *              expr_type: string,
	 *              view: string,
	 *              no_quotes: array{
	 *                  delim: bool,
	 *                  parts: array<string>
	 *              },
	 *              base_expr: string
	 *          }
	 *      }
	 *  } $parsedPayload
	 * @return bool
	 * @example {"SHOW":[{"expr_type":"reserved","base_expr":"source"},
	 * {"expr_type":"source","source":"kafka","no_quotes":{"delim":false,"parts":["kafka"]},"base_expr":"kafka"}]}
	 */
	private static function isGetSourceMatch(array $parsedPayload): bool {
		return (
			isset($parsedPayload['SHOW'][0]['base_expr']) &&
			strtolower($parsedPayload['SHOW'][0]['base_expr']) === Payload::TYPE_SOURCE &&
			!empty($parsedPayload['SHOW'][1]['no_quotes']['parts'][0])
		);
	}


}
