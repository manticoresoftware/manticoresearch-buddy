<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\View;

use Manticoresearch\Buddy\Base\Plugin\PluginsAuthPermissions\ResourceTable;
use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\BaseListHandler;

/**
 * @extends BaseListHandler<array{
 *       SHOW: array{
 *           0: array{
 *               expr_type: string,
 *               base_expr: string
 *           },
 *           1: array{
 *               expr_type: string,
 *               base_expr: string
 *           }
 *       }
 *   }>
 */
final class ListViewsHandler extends BaseListHandler {


	protected function getTablePrefix(): string {
		return ResourceTable::TABLE_PREFIX_MATERIALIZED_VIEW;
	}
}
