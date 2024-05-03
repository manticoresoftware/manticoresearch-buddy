<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\BaseViewHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;

/**
* @extends BaseViewHandler<array{
 *       SHOW: array{
 *           0: array{
 *               expr_type: string,
 *               base_expr?: string
 *           }
 *       }
 *   }>
 */
final class ViewSourceHandler extends BaseViewHandler {
	protected function getTableName(): string {
		return Payload::SOURCE_TABLE_NAME;
	}
}
