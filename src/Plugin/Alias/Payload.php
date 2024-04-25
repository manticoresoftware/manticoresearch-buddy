<?php declare(strict_types=1);

/*
  Copyright (c) 2023, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

namespace Manticoresearch\Buddy\Base\Plugin\Alias;

use Manticoresearch\Buddy\Core\Network\Request;
use Manticoresearch\Buddy\Core\Plugin\BasePayload;

/**
 * @phpstan-extends BasePayload<array>
 */
final class Payload extends BasePayload {
	/** @var string */
	public string $path;

	/** @var string */
	public string $query;

	public function __construct() {
	}

	/**
	 * @param Request $request
	 * @return static
	 */
	public static function fromRequest(Request $request): static {
		$self = new static();
		$self->path = $request->path;
		$self->query = $request->payload;
		return $self;
	}

	/**
	 * @param Request $request
	 * @return bool
	 */
	public static function hasMatch(Request $request): bool {
		$hasError = str_contains($request->error, "unexpected \$undefined near '.*")
			|| str_contains($request->error, "unexpected identifier, expecting SET near 't ")
			|| (
				str_contains($request->error, "expecting \$end near '")
				&& str_contains($request->error, " t'")
			);

		$hasMatch = str_contains($request->payload, ' t.')
			|| str_ends_with($request->payload, ' t');
		if ($hasError && $hasMatch) {
			return true;
		}

		return false;
	}
}
