<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Optimize;

use Manticoresearch\Buddy\Core\Error\QueryParseError;
use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * @extends BasePayload<array>
 */
final class Payload extends BasePayload
{
	public string $table;

	public static function getInfo(): string {
		return 'Handles OPTIMIZE TABLE statements on sharded/distributed tables';
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		if (preg_match('/optimize\s+table\s+(?:`?([^`\s;]+)`?)/i', $request->payload, $matches)) {
			$self->table = $matches[1];
			return $self;
		}

		throw QueryParseError::create('Failed to handle your OPTIMIZE query', true);
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		return $request->command === 'optimize' &&
			stripos($request->error, 'requires an existing RT table') !== false;
	}
}
