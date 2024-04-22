<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\BaseGetHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;

/**
 * @extends BaseGetHandler<array{
 *       SHOW: array{
 *           0: array{
 *               expr_type: string,
 *               base_expr: string
 *           },
 *           1: array{
 *               expr_type: string,
 *               base_expr: string
 *           },
 *           2: array{
 *               expr_type: string,
 *               view: string,
 *               no_quotes: array{
 *                   delim: bool,
 *                   parts: array<string>
 *               },
 *               base_expr: string
 *           }
 *       }
 *   }>
 */
final class GetViewHandler extends BaseGetHandler {

	/**
	 * @param Payload<array{
	 *        SHOW: array{
	 *            0: array{
	 *                expr_type: string,
	 *                base_expr: string
	 *            },
	 *            1: array{
	 *                expr_type: string,
	 *                base_expr: string
	 *            },
	 *            2: array{
	 *                expr_type: string,
	 *                view: string,
	 *                no_quotes: array{
	 *                    delim: bool,
	 *                    parts: array<string>
	 *                },
	 *                base_expr: string
	 *            }
	 *        }
	 *    }> $payload
	 * @return string
	 */
	#[\Override] protected function getName(Payload $payload): string {
		$parsedPayload = $payload->model->getPayload();
		return $parsedPayload['SHOW'][2]['no_quotes']['parts'][0];
	}

	#[\Override] protected static function formatResult(string $query): string {
		return str_replace("\n", '', $query);
	}

	#[\Override] protected function getType(): string {
		return 'View';
	}

	#[\Override] protected function getTableName(): string {
		return Payload::VIEWS_TABLE_NAME;
	}

	#[\Override] protected function getFields(): array {
		return ['suspended'];
	}
}
