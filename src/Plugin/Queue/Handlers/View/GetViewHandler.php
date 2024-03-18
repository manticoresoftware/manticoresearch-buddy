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

final class GetViewHandler extends BaseGetHandler
{

	#[\Override] protected static function formatResult(string $query): string {
		return $query;
	}

	#[\Override] protected function getType(): string {
		return 'View';
	}

	#[\Override] protected function getTableName(): string {
		return CreateViewHandler::VIEWS_TABLE_NAME;
	}
}
