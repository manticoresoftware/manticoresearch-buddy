<?php declare(strict_types=1);

/*
  Copyright (c) 2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Test;

use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * Request for Backup command that has parsed parameters from SQL
 */
final class Payload extends BasePayload {

	public function __construct(public int $timeout = 0, public bool $isDeferred = false) {
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		// Request for Test command emulating hung Buddy requests
		// Contains info on request timeout and the type of the command's Task(deferred or not)
		// E.g.: test 6/deferred ; test 10 ; test deferred
		$self = new static();
		$matches = [];
		preg_match('/^\s*test\s+(\d+)?\/?(deferred)?\s*$/i', $request->payload, $matches);
		$self->timeout = isset($matches[1]) ? abs((int)$matches[1]) : 0;
		$self->isDeferred = isset($matches[2]);

		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		return stripos($request->payload, 'test') === 0;
	}
}
