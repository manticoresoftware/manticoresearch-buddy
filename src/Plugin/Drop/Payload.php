<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Drop;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * This is simple do nothing request that handle empty queries
 * which can be as a result of only comments in it that we strip
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload
{
	public string $table;

	/**
	 * Get description for this plugin
	 * @return string
	 */
	public static function getInfo(): string {
		return 'Handles DROP statements with MySQL options not supported by Manticore';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();

		$matches = [];
		if (preg_match('/(Manticore|`Manticore`)\.(\S+)\s*$/i', $request->payload, $matches)) {
			$self->table = $matches[2];
			return $self;
		}

		throw QueryParseError::create('Failed to handle your DROP query', true);
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		return (stripos($request->error, "P01: syntax error, unexpected identifier near 'DROP TABLE") === 0);
	}
}
