<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\Source;

use Manticoresearch\Buddy\Base\Plugin\Queue\Handlers\BaseGetHandler;
use Manticoresearch\Buddy\Base\Plugin\Queue\Payload;

final class GetSourceHandler extends BaseGetHandler
{

	#[\Override] protected static function formatResult(string $query): string {

		$formatted = str_replace(
			['(', ')'], ["\n(", ")\n"],
			$query
		);
		$formatted = explode(')', $formatted);
		$pattern = "/\s*(\w+)\s*=\s*'([^']+)'/";
		$formatted[1] = preg_replace($pattern, "$1='$2'\n", $formatted[1]);
		return implode(")\n", $formatted);
	}

	#[\Override] protected function getType(): string {
		return 'Source';
	}

	#[\Override] protected function getTableName(): string {
		return Payload::SOURCE_TABLE_NAME;
	}

	#[\Override] protected function getName(Payload $payload): string {
		return $payload->parsedPayload['SHOW'][1]['no_quotes']['parts'][0];
	}

	#[\Override] protected function getFields() {
		return [];
	}
}
